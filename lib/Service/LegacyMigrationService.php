<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\Attachment;
use OCA\Runbook\Db\AttachmentMapper;
use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunMapper;
use OCA\Runbook\Db\TemplateMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Migrates legacy AppData evidence into the run-managed Files folder (issue
 * #55, docs/folder-model.md §8.1).
 *
 * The migration is **resumable and idempotent** at run and attachment level:
 *
 * 1. Resolve the destination for a legacy run using the first *supplied* level
 *    of the template → admin → default precedence, in the **run owner's** view.
 *    A supplied but invalid/inaccessible/incomplete level is authoritative and
 *    blocks the run; it never falls through to a lower level, another user's
 *    view or AppData.
 * 2. Freeze the resolved destination on the run and create/reuse the managed
 *    per-run folder (marker ownership proven by {@see RunDestinationResolver}).
 * 3. Per attachment: read AppData → copy under a **deterministic** collision-free
 *    name → verify size + SHA-256 against recorded metadata → update the row to
 *    `storage_kind='files'` with the owner-resolved identity → delete the AppData
 *    source only after the metadata update.
 *
 * Any failure preserves the AppData source, records `blocked`/`pending` state and
 * a reason on the run and/or attachment, and never deletes user content. A crash
 * between the copy and the metadata update is resumed because the target name is
 * deterministic: the existing copy is re-verified instead of duplicated.
 */
class LegacyMigrationService {
	public const RUN_PENDING = 'pending';
	public const RUN_BLOCKED = 'blocked';
	public const RUN_DONE = 'done';

	public const ATTACHMENT_PENDING = 'pending';
	public const ATTACHMENT_BLOCKED = 'blocked';
	public const ATTACHMENT_DONE = 'done';

	public const REASON_SOURCE_MISSING = 'migration_source_missing';
	public const REASON_SOURCE_UNREADABLE = 'migration_source_unreadable';
	public const REASON_TARGET_CONFLICT = 'migration_target_conflict';
	public const REASON_COPY_FAILED = 'migration_copy_failed';
	public const REASON_VERIFY_FAILED = 'migration_verify_failed';
	public const REASON_METADATA_FAILED = 'migration_metadata_failed';
	public const REASON_SOURCE_DELETE_FAILED = 'migration_source_delete_failed';
	public const REASON_INCOMPLETE = 'migration_incomplete';

	/** App-config key holding the durable rotating keyset cursor (#55 fairness). */
	private const CURSOR_KEY = 'migration_cursor';

	/** Stable cross-worker lock key serializing batch selection and processing. */
	private const LOCK_KEY = 'runbook/legacy-migration';

	public function __construct(
		private readonly AttachmentMapper $attachments,
		private readonly RunMapper $runs,
		private readonly TemplateMapper $templates,
		private readonly TemplateDestinationService $templateDestinations,
		private readonly EvidenceStorage $appData,
		private readonly FilesAttachmentStorage $filesStorage,
		private readonly RunDestinationResolver $destinations,
		private readonly ITimeFactory $timeFactory,
		private readonly IAppConfig $appConfig,
		private readonly ILockingProvider $locking,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Migrate a bounded, fairly-selected batch of runs.
	 *
	 * Candidate runs are all runs that still have an AppData attachment (active
	 * dependency) or an attachment whose AppData source deletion failed
	 * (residual cleanup). They are selected with a durable keyset cursor over the
	 * ascending run-id order: each batch starts after the last selected run id and
	 * wraps to the beginning when it reaches the end. This guarantees forward
	 * progress and rotation independent of `migration_attempted_at`, so equal or
	 * NULL attempt timestamps can never make the same lower-id runs starve the
	 * rest. Blocked runs stay in the candidate set and are retried on the next
	 * rotation. The cursor is shared by the hourly job and the admin trigger.
	 *
	 * Because the cursor is a single read-modify-write value and a batch can wrap
	 * to re-select the same runs (whenever `$maxRuns` is at least the candidate
	 * count), the whole selection **and** processing is a critical section: two
	 * overlapping invocations must not both read the same cursor and migrate the
	 * same runs. An exclusive {@see ILockingProvider} lock on a stable key
	 * serializes them across PHP workers. A contended invocation returns a
	 * `busy` result without moving the cursor or touching any record.
	 *
	 * @return array{processed: int, migrated: int, blocked: int, remaining: int, busy: bool}
	 */
	public function migrateAll(int $maxRuns = 50): array {
		if (!$this->acquireMigrationLock()) {
			return [
				'processed' => 0,
				'migrated' => 0,
				'blocked' => 0,
				'remaining' => $this->attachments->countByStorageKind(Attachment::STORAGE_KIND_APPDATA),
				'busy' => true,
			];
		}

		try {
			$processed = 0;
			$migrated = 0;
			$blocked = 0;

			foreach ($this->selectBatch($this->candidateRunIds(), $maxRuns) as $runId) {
				try {
					$result = $this->migrateRun($runId);
				} catch (\Throwable $exception) {
					// One broken run must never abort the whole batch.
					$this->logger->warning('Runbook legacy migration failed for a run', [
						'app' => 'runbook',
						'runId' => $runId,
						'exception' => $exception,
					]);
					continue;
				}
				$processed++;
				if ($result['state'] === self::RUN_DONE) {
					$migrated++;
				} elseif ($result['state'] === self::RUN_BLOCKED) {
					$blocked++;
				}
			}

			return [
				'processed' => $processed,
				'migrated' => $migrated,
				'blocked' => $blocked,
				'remaining' => $this->attachments->countByStorageKind(Attachment::STORAGE_KIND_APPDATA),
				'busy' => false,
			];
		} finally {
			$this->locking->releaseLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Try to become the single batch owner. Returns false when another worker
	 * already holds the lock; the caller must then leave the cursor and records
	 * untouched.
	 */
	private function acquireMigrationLock(): bool {
		try {
			$this->locking->acquireLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);

			return true;
		} catch (LockedException) {
			return false;
		}
	}

	/**
	 * Deterministic ascending candidate run ids (active AppData or residual
	 * cleanup), without loading the entities.
	 *
	 * @return list<int>
	 */
	private function candidateRunIds(): array {
		$ids = array_values(array_unique(array_merge(
			$this->attachments->findRunIdsByStorageKind(Attachment::STORAGE_KIND_APPDATA),
			$this->attachments->findRunIdsByMigrationReason(self::REASON_SOURCE_DELETE_FAILED),
		)));
		sort($ids);

		return $ids;
	}

	/**
	 * Select the next bounded batch and advance the durable keyset cursor.
	 *
	 * @param list<int> $sortedIds Ascending, unique candidate run ids.
	 * @return list<int>
	 */
	private function selectBatch(array $sortedIds, int $maxRuns): array {
		$count = count($sortedIds);
		if ($count === 0 || $maxRuns <= 0) {
			return [];
		}

		$cursor = $this->appConfig->getValueInt('runbook', self::CURSOR_KEY, 0);
		$start = 0;
		foreach ($sortedIds as $index => $id) {
			if ($id > $cursor) {
				$start = $index;
				break;
			}
		}

		$take = min($maxRuns, $count);
		$selected = [];
		for ($offset = 0; $offset < $take; $offset++) {
			$selected[] = $sortedIds[($start + $offset) % $count];
		}

		$this->appConfig->setValueInt('runbook', self::CURSOR_KEY, $selected[$take - 1]);

		return $selected;
	}

	/**
	 * Migrate one run's legacy AppData evidence.
	 *
	 * @return array{runId: int, state: string, reason: string|null, migrated: int, remaining: int}
	 */
	public function migrateRun(int $runId): array {
		try {
			$run = $this->runs->find($runId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('run_not_found');
		}

		try {
			$this->ensureDestination($run);
		} catch (ConflictException|ValidationException $exception) {
			$this->markRun($run, self::RUN_BLOCKED, $exception->getReason());

			return [
				'runId' => $run->getId(),
				'state' => self::RUN_BLOCKED,
				'reason' => $exception->getReason(),
				'migrated' => 0,
				'remaining' => $this->countRunAppData($run->getId()),
			];
		}

		$migrated = 0;
		$blockReason = null;
		foreach ($this->attachments->findByRun($run->getId()) as $attachment) {
			if ($attachment->getStorageKind() === Attachment::STORAGE_KIND_APPDATA) {
				$reason = $this->migrateAttachment($run, $attachment);
				if ($reason === null) {
					$migrated++;
				} elseif ($blockReason === null) {
					$blockReason = $reason;
				}
				continue;
			}
			if ($attachment->getMigrationReason() === self::REASON_SOURCE_DELETE_FAILED) {
				// The evidence is already a verified Files copy; only the residual
				// AppData source is retried here. This never touches the copy or an
				// unrelated AppData item (it deletes the exact recorded key).
				$this->retrySourceDelete($run, $attachment);
			}
		}

		$remaining = $this->countRunAppData($run->getId());
		if ($remaining === 0) {
			$this->markRun($run, self::RUN_DONE, null);

			return ['runId' => $run->getId(), 'state' => self::RUN_DONE, 'reason' => null, 'migrated' => $migrated, 'remaining' => 0];
		}

		$blockReason ??= self::REASON_INCOMPLETE;
		$this->markRun($run, self::RUN_BLOCKED, $blockReason);

		return ['runId' => $run->getId(), 'state' => self::RUN_BLOCKED, 'reason' => $blockReason, 'migrated' => $migrated, 'remaining' => $remaining];
	}

	/**
	 * Actionable migration status for administrators (never exposes storage ids
	 * or paths).
	 *
	 * `remainingAppDataAttachments` / `runs` describe the **active** AppData
	 * dependency (evidence that still has to be migrated). `residualCleanupAttachments`
	 * / `cleanup` describe evidence whose Files copy is already verified but whose
	 * legacy AppData source could not be deleted; that is a cleanup task, not an
	 * incomplete migration, so it is reported separately and never blocks `done`.
	 *
	 * @return array{remainingAppDataAttachments: int, residualCleanupAttachments: int, runs: list<array{id: int, title: string, owner: string, state: string|null, reason: string|null, appDataAttachments: int, lastAttemptedAt: int|null}>, cleanup: list<array{id: int, title: string, owner: string, residualAttachments: int, lastAttemptedAt: int|null}>}
	 */
	public function status(): array {
		$runs = [];
		foreach ($this->attachments->findRunIdsByStorageKind(Attachment::STORAGE_KIND_APPDATA) as $runId) {
			try {
				$run = $this->runs->find($runId);
			} catch (DoesNotExistException) {
				continue;
			}
			$runs[] = [
				'id' => $run->getId(),
				'title' => $run->getTitle(),
				'owner' => $run->getOwner(),
				'state' => $run->getMigrationState(),
				'reason' => $run->getMigrationReason(),
				'appDataAttachments' => $this->countRunAppData($run->getId()),
				'lastAttemptedAt' => $run->getMigrationAttemptedAt(),
			];
		}

		$cleanup = [];
		foreach ($this->attachments->findRunIdsByMigrationReason(self::REASON_SOURCE_DELETE_FAILED) as $runId) {
			try {
				$run = $this->runs->find($runId);
			} catch (DoesNotExistException) {
				continue;
			}
			$cleanup[] = [
				'id' => $run->getId(),
				'title' => $run->getTitle(),
				'owner' => $run->getOwner(),
				'residualAttachments' => $this->countRunReason($runId, self::REASON_SOURCE_DELETE_FAILED),
				'lastAttemptedAt' => $run->getMigrationAttemptedAt(),
			];
		}

		return [
			'remainingAppDataAttachments' => $this->attachments->countByStorageKind(Attachment::STORAGE_KIND_APPDATA),
			'residualCleanupAttachments' => $this->attachments->countByMigrationReason(self::REASON_SOURCE_DELETE_FAILED),
			'runs' => $runs,
			'cleanup' => $cleanup,
		];
	}

	/**
	 * Ensure the run has a frozen, owner-resolved destination and managed folder.
	 *
	 * A legacy run is resolved with the migration precedence (template → admin →
	 * default) and frozen; an already-frozen run is reused. A corrupt run fails
	 * closed.
	 *
	 * @throws ConflictException|ValidationException
	 */
	private function ensureDestination(Run $run): void {
		$state = $this->destinationState($run);
		if ($state === 'corrupt') {
			throw new ValidationException('destination_invalid_config');
		}
		if ($state === 'frozen') {
			return;
		}

		$templateReference = $this->templateReference($run);
		$destination = $this->destinations->resolveForRun(
			$run->getOwner(),
			$run->getUuid(),
			$run->getTitle(),
			$templateReference,
			null,
		);
		$this->destinations->applyToRun($run, $destination);
		$run->setDestinationMigratedAt($this->timeFactory->getTime());
		$this->markRun($run, self::RUN_PENDING, null);
	}

	/**
	 * @return string One of `legacy`, `frozen`, `corrupt`.
	 */
	private function destinationState(Run $run): string {
		$source = $run->getDestinationSource();
		$values = [
			$run->getDestinationViewUid(),
			$run->getDestinationStorageId(),
			$run->getDestinationFileId(),
			$run->getDestinationPath(),
			$run->getDestinationConfiguredBy(),
			$run->getDestinationStorageRootId(),
			$run->getDestinationMountType(),
			$run->getDestinationMountProvider(),
			$run->getDestinationMountId(),
			$run->getDestinationNumericStorageId(),
			$run->getRunFolderFileId(),
			$run->getRunFolderStorageId(),
			$run->getRunFolderPath(),
			$run->getRunFolderStorageRootId(),
			$run->getRunFolderMountType(),
			$run->getRunFolderMountProvider(),
			$run->getRunFolderMountId(),
			$run->getRunFolderNumericStorageId(),
		];
		$anySet = array_filter($values, static fn (mixed $value): bool => $value !== null) !== [];

		if ($source === null) {
			// destination_migrated_at without a source, or any coordinate set
			// while the source is NULL, is corrupt — never treated as legacy.
			if ($run->getDestinationMigratedAt() !== null || $anySet) {
				return 'corrupt';
			}

			return 'legacy';
		}

		// A frozen destination must be complete (identity + managed folder).
		if ($run->getDestinationViewUid() === null
			|| $run->getDestinationStorageId() === null
			|| $run->getDestinationFileId() === null
			|| $run->getRunFolderFileId() === null
			|| $run->getRunFolderStorageId() === null) {
			return 'corrupt';
		}

		return 'frozen';
	}

	/**
	 * @throws ValidationException When the source template's reference is partial.
	 */
	private function templateReference(Run $run): ?DestinationReference {
		$templateId = $run->getTemplateId();
		if ($templateId === null) {
			return null;
		}
		try {
			$template = $this->templates->find($templateId);
		} catch (DoesNotExistException) {
			return null;
		}

		return $this->templateDestinations->readReference($template);
	}

	/**
	 * Copy, verify, switch metadata and only then delete one AppData source.
	 *
	 * @return string|null null on success, otherwise a block reason.
	 */
	private function migrateAttachment(Run $run, Attachment $attachment): ?string {
		$storageKey = $attachment->getStorageKey();
		if ($storageKey === '') {
			return $this->blockAttachment($attachment, self::REASON_SOURCE_MISSING);
		}

		try {
			$content = $this->appData->read($run->getId(), $attachment->getStepId(), $storageKey);
		} catch (FilesNotFoundException) {
			return $this->blockAttachment($attachment, self::REASON_SOURCE_MISSING);
		} catch (\Throwable $exception) {
			$this->logger->warning('Runbook could not read legacy AppData evidence', [
				'app' => 'runbook',
				'attachmentId' => (int)$attachment->getId(),
				'exception' => $exception,
			]);

			return $this->blockAttachment($attachment, self::REASON_SOURCE_UNREADABLE);
		}

		$targetName = $this->migrationTargetName($attachment);
		try {
			$file = $this->filesStorage->findInManagedFolder($run, $targetName);
		} catch (ConflictException|ValidationException $exception) {
			return $this->blockAttachment($attachment, $exception->getReason());
		}

		if ($file === null) {
			try {
				$file = $this->filesStorage->writeNamed($run, $targetName, $content);
			} catch (ConflictException $exception) {
				if ($exception->getReason() !== 'migration_target_exists') {
					return $this->blockAttachment($attachment, $exception->getReason());
				}
				// A concurrent/preceding attempt created the target; re-read it.
				try {
					$file = $this->filesStorage->findInManagedFolder($run, $targetName);
				} catch (ConflictException|ValidationException $readException) {
					return $this->blockAttachment($attachment, $readException->getReason());
				}
				if ($file === null) {
					return $this->blockAttachment($attachment, self::REASON_COPY_FAILED);
				}
			} catch (ValidationException $exception) {
				return $this->blockAttachment($attachment, $exception->getReason());
			}
		}

		try {
			if (!$this->matchesRecorded($file, $attachment)) {
				return $this->blockAttachment($attachment, self::REASON_VERIFY_FAILED);
			}
		} catch (\Throwable $exception) {
			$this->logger->warning('Runbook could not verify a migrated Files copy', [
				'app' => 'runbook',
				'attachmentId' => (int)$attachment->getId(),
				'exception' => $exception,
			]);

			return $this->blockAttachment($attachment, self::REASON_COPY_FAILED);
		}

		// Persist the migrated state on a clone so a failed metadata update never
		// leaves the stored row half-switched: the original stays a verified
		// AppData row until the Files metadata is durably written.
		$working = clone $attachment;
		try {
			$descriptor = $this->filesStorage->describe($file);
			$working->setStorageKind(Attachment::STORAGE_KIND_FILES);
			$working->setFileId($descriptor['fileId']);
			$working->setStorageId($descriptor['storageId']);
			$working->setStorageRootId($descriptor['storageRootId']);
			$working->setMountType($descriptor['mountType']);
			$working->setMountProvider($descriptor['mountProvider']);
			$working->setMountId($descriptor['mountId']);
			$working->setNumericStorageId($descriptor['numericStorageId']);
			$working->setPath($descriptor['path']);
			$working->setMigrationState(self::ATTACHMENT_DONE);
			$working->setMigrationReason(null);
			$this->attachments->update($working);
		} catch (\Throwable $exception) {
			$this->logger->warning('Runbook could not persist migrated attachment metadata', [
				'app' => 'runbook',
				'attachmentId' => (int)$attachment->getId(),
				'exception' => $exception,
			]);

			return $this->blockAttachment($attachment, self::REASON_METADATA_FAILED);
		}

		// Only now that the stored row is a verified Files attachment may the
		// AppData source be removed. A failure keeps the source (and its storage
		// key) for manual review; the row stays a completed migration because the
		// bytes are safely in Files — only the cleanup is outstanding.
		try {
			$this->appData->delete($run->getId(), $attachment->getStepId(), $storageKey);
			$working->setStorageKey('');
			$this->attachments->update($working);
		} catch (\Throwable $exception) {
			$this->logger->warning('Runbook could not delete a migrated AppData source', [
				'app' => 'runbook',
				'attachmentId' => (int)$attachment->getId(),
				'exception' => $exception,
			]);
			$working->setMigrationReason(self::REASON_SOURCE_DELETE_FAILED);
			$this->attachments->update($working);
		}

		return null;
	}

	/**
	 * Deterministic, collision-free copy name derived from the attachment UUID.
	 *
	 * The suffix makes a retry resolve the exact same target, so an interrupted
	 * copy is re-verified rather than duplicated, and it can never be the
	 * reserved ownership marker.
	 */
	public function migrationTargetName(Attachment $attachment): string {
		$filename = str_replace(['\\', '/'], '-', $attachment->getFilename());
		$filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? '';
		$filename = trim($filename);
		if ($filename === '' || $filename === '.' || $filename === '..') {
			$filename = 'evidence';
		}

		$extension = pathinfo($filename, PATHINFO_EXTENSION);
		$stem = pathinfo($filename, PATHINFO_FILENAME);
		if ($stem === '') {
			$stem = 'evidence';
		}

		$suffix = ' (' . $attachment->getUuid() . ')';
		$suffix .= $extension !== '' ? '.' . $extension : '';
		$maxStem = 255 - mb_strlen($suffix);
		if ($maxStem < 1) {
			$maxStem = 1;
		}
		if (mb_strlen($stem) > $maxStem) {
			$stem = mb_substr($stem, 0, $maxStem);
		}

		return $stem . $suffix;
	}

	private function matchesRecorded(File $file, Attachment $attachment): bool {
		$content = $file->getContent();

		return strlen($content) === $attachment->getSize()
			&& hash('sha256', $content) === $attachment->getChecksum();
	}

	private function blockAttachment(Attachment $attachment, string $reason): string {
		$attachment->setMigrationState(self::ATTACHMENT_BLOCKED);
		$attachment->setMigrationReason($reason);
		$this->attachments->update($attachment);

		return $reason;
	}

	private function markRun(Run $run, string $state, ?string $reason): void {
		$run->setMigrationState($state);
		$run->setMigrationReason($reason);
		// Durable "least recently attempted" marker: a blocked run moves to the
		// back of the fair-selection queue so later runs are reached (and the
		// blocked run is retried on the next cycle).
		$run->setMigrationAttemptedAt($this->timeFactory->getTime());
		$this->runs->update($run);
	}

	/**
	 * Retry deletion of a residual AppData source whose Files copy is already
	 * verified. Never touches the Files copy or an unrelated AppData item: only
	 * the exact recorded storage key of this attachment is deleted.
	 */
	private function retrySourceDelete(Run $run, Attachment $attachment): void {
		$storageKey = $attachment->getStorageKey();
		$working = clone $attachment;
		try {
			if ($storageKey !== '') {
				$this->appData->delete($run->getId(), $attachment->getStepId(), $storageKey);
			}
			$working->setStorageKey('');
			$working->setMigrationReason(null);
			$this->attachments->update($working);
		} catch (\Throwable $exception) {
			$this->logger->warning('Runbook could not delete a residual AppData source', [
				'app' => 'runbook',
				'attachmentId' => (int)$attachment->getId(),
				'exception' => $exception,
			]);
		}
	}

	private function countRunAppData(int $runId): int {
		$count = 0;
		foreach ($this->attachments->findByRun($runId) as $attachment) {
			if ($attachment->getStorageKind() === Attachment::STORAGE_KIND_APPDATA) {
				$count++;
			}
		}

		return $count;
	}

	private function countRunReason(int $runId, string $reason): int {
		$count = 0;
		foreach ($this->attachments->findByRun($runId) as $attachment) {
			if ($attachment->getMigrationReason() === $reason) {
				$count++;
			}
		}

		return $count;
	}
}
