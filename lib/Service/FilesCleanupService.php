<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\FilesCleanup;
use OCA\Runbook\Db\FilesCleanupMapper;
use OCA\Runbook\Db\Run;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Durable cleanup of a run's managed Files folder (issue #54).
 *
 * The run-row deletion and the cleanup intent are committed atomically by
 * {@see RunService}; this service only builds the record and resolves the
 * post-commit folder outcome (with idempotent retries).
 *
 * **Fail-closed folder policy.** The public Nextcloud Files API offers no
 * non-recursive/conditional empty-folder deletion: `Folder::delete()` is
 * recursive (`View::rmdir()` → `Storage\Local::rmdir()`, which deletes every
 * child recursively). Any check-then-delete therefore races with a user adding
 * a file, which could be silently destroyed. Runbook therefore **never removes a
 * managed folder**: an existing folder — with untracked content, with only the
 * ownership marker, or empty — is preserved and the cleanup record is finalized
 * `blocked` for human review. Only a folder that is already gone closes the
 * record. The lower-level `IStorage::rmdir()` primitive is non-recursive but
 * bypasses the Files cache/hooks/locking layer, so it is not a safe substitute.
 */
class FilesCleanupService {
	public function __construct(
		private readonly FilesCleanupMapper $cleanups,
		private readonly RunDestinationResolver $destinations,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Build the pending cleanup record for a run's frozen managed folder, or
	 * `null` when the run has no complete managed-folder identity (legacy run).
	 */
	public function buildRecord(Run $run): ?FilesCleanup {
		$fileId = $run->getRunFolderFileId();
		$storageId = $run->getRunFolderStorageId();
		if ($fileId === null || $fileId <= 0 || $storageId === null || $storageId === '') {
			return null;
		}

		$record = new FilesCleanup();
		$record->setKind(FilesCleanup::KIND_FOLDER);
		$record->setStatus(FilesCleanup::STATUS_PENDING);
		$record->setViewUid($run->getDestinationViewUid() ?? $run->getOwner());
		$record->setStorageId($storageId);
		$record->setFileId($fileId);
		$record->setStorageRootId($run->getRunFolderStorageRootId());
		$record->setMountType($run->getRunFolderMountType());
		$record->setMountProvider($run->getRunFolderMountProvider());
		$record->setMountId($run->getRunFolderMountId());
		$record->setNumericStorageId($run->getRunFolderNumericStorageId());
		$record->setPath($run->getRunFolderPath());
		$record->setReason(null);
		$record->setAttempts(0);
		$record->setLastAttemptAt(null);
		$record->setCreatedAt($this->timeFactory->getTime());

		return $record;
	}

	/**
	 * Persist a cleanup record. Called inside the run-row deletion transaction so
	 * the intent is durable before the run disappears.
	 */
	public function persist(FilesCleanup $record): FilesCleanup {
		$stored = $this->cleanups->insert($record);

		return $stored;
	}

	/**
	 * Attempt to resolve the recorded folder once, updating/deleting the record.
	 *
	 * Idempotent: a folder that is already gone counts as success. Otherwise the
	 * folder is preserved (see the class policy) and the record is finalized for
	 * review, or kept for retry when the identity could only not be resolved
	 * transiently.
	 */
	public function attempt(FilesCleanup $record): void {
		$record->setAttempts($record->getAttempts() + 1);
		$record->setLastAttemptAt($this->timeFactory->getTime());

		try {
			$reason = $this->resolveOutcome($record);
		} catch (\Throwable $exception) {
			$this->logger->warning('Runbook managed-folder cleanup failed', [
				'app' => 'runbook',
				'fileId' => $record->getFileId(),
				'exception' => $exception,
			]);
			$reason = FilesCleanup::REASON_ERROR;
		}

		if ($reason === null) {
			// The folder is already gone: the record is no longer needed.
			$this->cleanups->delete($record);

			return;
		}

		$record->setReason($reason);
		$record->setStatus(
			self::isReviewReason($reason)
				? FilesCleanup::STATUS_BLOCKED
				: FilesCleanup::STATUS_PENDING,
		);
		$this->cleanups->update($record);
	}

	/**
	 * Retry every pending cleanup record once, oldest first.
	 *
	 * @return int The number of records processed.
	 */
	public function processPending(): int {
		$processed = 0;
		foreach ($this->cleanups->findPending() as $record) {
			$this->attempt($record);
			$processed++;
		}

		return $processed;
	}

	/**
	 * Whether a reason is terminal (human review) rather than retryable.
	 */
	private static function isReviewReason(string $reason): bool {
		return in_array($reason, [
			FilesCleanup::REASON_NOT_EMPTY,
			FilesCleanup::REASON_REMOVAL_UNSUPPORTED,
			FilesCleanup::REASON_AMBIGUOUS,
		], true);
	}

	/**
	 * Resolve the recorded folder and decide its cleanup outcome.
	 *
	 * @return string|null A preserved/blocked reason, or `null` when the folder
	 *                     is already gone.
	 */
	private function resolveOutcome(FilesCleanup $record): ?string {
		try {
			$folder = $this->destinations->findManagedFolder(
				$record->getViewUid(),
				$record->getStorageId(),
				$record->getFileId(),
			);
		} catch (ConflictException $exception) {
			// A transient or ambiguous resolution never acts on the tree: an
			// ambiguous identity is terminal, everything else is retryable.
			return match ($exception->getReason()) {
				'destination_ambiguous' => FilesCleanup::REASON_AMBIGUOUS,
				default => FilesCleanup::REASON_UNAVAILABLE,
			};
		}

		if ($folder === null) {
			// Already removed (for example by the user, or between the folder
			// removal and record completion in a previous safe run): success.
			return null;
		}

		// The folder still exists. Runbook must not call recursive
		// `Folder::delete()` here: the listing below is not atomic with a delete,
		// so an untracked file added by a user in between would be destroyed.
		foreach ($folder->getDirectoryListing() as $child) {
			if ($child->getName() !== RunDestinationResolver::MARKER_FILE_NAME) {
				return FilesCleanup::REASON_NOT_EMPTY;
			}
		}

		$this->logger->info('Runbook preserved a managed run folder that cannot be removed safely', [
			'app' => 'runbook',
			'fileId' => $record->getFileId(),
			'reason' => FilesCleanup::REASON_REMOVAL_UNSUPPORTED,
		]);

		return FilesCleanup::REASON_REMOVAL_UNSUPPORTED;
	}
}
