<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\Attachment;
use OCA\Runbook\Db\AttachmentMapper;
use OCA\Runbook\Db\Run;
use OCA\Runbook\Enum\ActivityType;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Enum\StepType;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

/**
 * Evidence attachments.
 *
 * New evidence is written to the run's managed folder in Nextcloud Files and
 * tracked by identity `(storage_id, file_id)`; a run without a usable managed
 * folder fails closed and never falls back to AppData. Existing AppData
 * attachments (from before the Files milestone) remain readable/deletable until
 * the #55 migration. Storage paths, ids and keys are never exposed through the
 * API.
 */
class AttachmentService {
	private const MAX_FILENAME_LENGTH = 255;

	/**
	 * Conservative allowlist of common evidence MIME types detected from the
	 * actual file content.
	 */
	private const ALLOWED_MIME_TYPES = [
		'image/png',
		'image/jpeg',
		'image/gif',
		'image/webp',
		'application/pdf',
		'text/plain',
		'text/csv',
		'application/json',
		'application/zip',
	];

	public function __construct(
		private readonly AttachmentMapper $attachments,
		private readonly RunService $runService,
		private readonly RunStepService $runStepService,
		private readonly EvidenceLockService $evidenceLock,
		private readonly RunAccessService $access,
		private readonly EvidenceStorage $storage,
		private readonly FilesAttachmentStorage $filesStorage,
		private readonly RunDestinationResolver $destinations,
		private readonly AttachmentReconciliationService $reconciliation,
		private readonly ActivityService $activity,
		private readonly AdminSettings $settings,
		private readonly UploadedFileReader $fileReader,
		private readonly FileTypeDetector $fileTypeDetector,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $timeFactory,
		private readonly ISecureRandom $secureRandom,
	) {
	}

	/**
	 * @return list<Attachment>
	 */
	public function listForRun(int $runId): array {
		$this->runService->requireAccessibleRun($runId);

		return $this->attachments->findByRun($runId);
	}

	/**
	 * Attachments of a run with their reconciled `file_state` (issue #52) and a
	 * run-level degraded flag.
	 *
	 * @return array{attachments: list<array{attachment: Attachment, fileState: string}>, degraded: bool}
	 */
	public function describeForRun(int $runId): array {
		$run = $this->runService->requireAccessibleRun($runId);

		$described = [];
		$degraded = false;
		foreach ($this->attachments->findByRun($runId) as $attachment) {
			$state = $this->reconciliation->stateFor($run, $attachment);
			if ($state !== AttachmentReconciliationService::PRESENT) {
				$degraded = true;
			}
			$described[] = ['attachment' => $attachment, 'fileState' => $state];
		}

		return ['attachments' => $described, 'degraded' => $degraded];
	}

	/**
	 * Upload evidence to a step the current user may execute.
	 *
	 * @param array<array-key, mixed> $file Raw upload entry.
	 */
	public function upload(int $stepId, array $file): Attachment {
		[$run] = $this->runStepService->requireExecutableStep($stepId);

		$read = $this->fileReader->read($file);
		$filename = $this->sanitizeFilename($read['name']);
		$content = $read['content'];
		$size = strlen($content);

		if ($size === 0) {
			throw new ValidationException('attachment_empty');
		}
		if ($size > $this->settings->getMaxAttachmentSize()) {
			throw new ValidationException('attachment_too_large');
		}

		$mimeType = $this->fileTypeDetector->detect($content, $filename);
		if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
			throw new ValidationException('attachment_mime_not_allowed');
		}

		return $this->storeFilesAttachment($run, $stepId, $filename, $content, $mimeType);
	}

	/**
	 * Attach a copy of an existing Files item as evidence (issue #53).
	 *
	 * The acting user selects a file in their own Files; the server resolves it,
	 * verifies read permission, and copies the bytes into the run-managed folder
	 * in the **run owner's** view. The original is never moved, renamed,
	 * overwritten or deleted, and the new attachment is a normal Files-backed
	 * attachment whose identity is the copied destination node.
	 *
	 * @param array<string, mixed> $data Request body; only `sourcePath` is read.
	 */
	public function copyFromFiles(int $stepId, array $data): Attachment {
		[$run] = $this->runStepService->requireExecutableStep($stepId);
		$sourcePath = $this->readSourcePath($data);

		$source = $this->destinations->resolveSourceFile($this->currentUserId(), $sourcePath);
		$filename = $this->sanitizeFilename($source->getName());

		try {
			$sourceSize = (int)$source->getSize();
		} catch (\Throwable $exception) {
			throw $this->destinations->translateSourceFilesError($exception);
		}
		if ($sourceSize === 0) {
			throw new ValidationException('attachment_empty');
		}
		if ($sourceSize > $this->settings->getMaxAttachmentSize()) {
			throw new ValidationException('attachment_too_large');
		}

		try {
			$content = $source->getContent();
		} catch (\Throwable $exception) {
			throw $this->destinations->translateSourceFilesError($exception);
		}

		// Re-check after reading: the source size is advisory and must not be
		// trusted to bound the actual byte count.
		if (strlen($content) === 0) {
			throw new ValidationException('attachment_empty');
		}
		if (strlen($content) > $this->settings->getMaxAttachmentSize()) {
			throw new ValidationException('attachment_too_large');
		}

		$mimeType = $this->fileTypeDetector->detect($content, $filename);
		if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
			throw new ValidationException('attachment_mime_not_allowed');
		}

		return $this->storeFilesAttachment($run, $stepId, $filename, $content, $mimeType);
	}

	/**
	 * @return array{attachment: Attachment, content: string}
	 */
	public function download(int $id): array {
		$attachment = $this->requireAttachment($id);
		$run = $this->runService->requireAccessibleRun($attachment->getRunId());

		if ($attachment->getStorageKind() === Attachment::STORAGE_KIND_FILES) {
			$content = $this->filesStorage->read($run, $attachment);
		} else {
			$content = $this->storage->read(
				$attachment->getRunId(),
				$attachment->getStepId(),
				$attachment->getStorageKey(),
			);
		}

		return ['attachment' => $attachment, 'content' => $content];
	}

	public function delete(int $id): void {
		$attachment = $this->requireAttachment($id);
		$run = $this->runService->requireAccessibleRun($attachment->getRunId());

		if ($run->getStatus() !== RunStatus::Active->value) {
			throw new ConflictException('run_not_active');
		}

		$uid = $this->currentUserId();
		if ($attachment->getUploaderUid() !== $uid && !$this->access->isOwner($run, $uid)) {
			throw new ForbiddenException('not_allowed');
		}

		// The count check and the removal must be atomic with step completion so
		// two concurrent deletions cannot both remove the last two attachments.
		$this->evidenceLock->synchronized($run->getId(), $attachment->getStepId(), function () use ($run, $attachment, $uid): void {
			// A completed required FILE step must keep at least one *present*
			// piece of evidence (#52): upload a replacement first, then delete.
			$step = $this->runStepService->findStep($attachment->getStepId());
			if ($step->getType() === StepType::File->value
				&& $step->getRequired()
				&& $step->getStatus() === RunStepStatus::Completed->value
				&& $this->reconciliation->stateFor($run, $attachment) === AttachmentReconciliationService::PRESENT
				&& $this->reconciliation->presentCount($run, $this->attachments->findByStep($step->getId())) <= 1) {
				throw new ConflictException('last_file_evidence_required');
			}

			// Persist the event before removing the metadata.
			$this->activity->record($run->getId(), $attachment->getStepId(), ActivityType::AttachmentDeleted, [
				'attachmentId' => $attachment->getId(),
				'filename' => $attachment->getFilename(),
			], $uid);

			if ($attachment->getStorageKind() === Attachment::STORAGE_KIND_FILES) {
				$this->filesStorage->delete($run, $attachment);
			} else {
				$this->storage->delete($run->getId(), $attachment->getStepId(), $attachment->getStorageKey());
			}
			$this->attachments->delete($attachment);
		});
	}

	/**
	 * Enforce that new evidence is only ever written to Nextcloud Files.
	 *
	 * A run with a complete managed-folder identity proceeds. A legacy run (no
	 * destination identity at all) has no managed folder yet and fails closed
	 * until it is migrated (#55); a partially populated or corrupt identity is
	 * invalid. AppData is never used as a fallback.
	 *
	 * @throws ConflictException|ValidationException
	 */
	private function assertUploadDestinationUsable(Run $run): void {
		$fileId = $run->getRunFolderFileId();
		$storageId = $run->getRunFolderStorageId();
		$hasFileId = $fileId !== null && $fileId > 0;
		$hasStorageId = $storageId !== null && $storageId !== '';

		if ($hasFileId && $hasStorageId) {
			return;
		}

		if ($hasFileId || $hasStorageId) {
			// A managed-folder identity must be complete; a partial one is
			// corrupt, never a legacy run.
			throw new ValidationException('destination_invalid_config');
		}

		$destinationMetadata = [
			$run->getDestinationViewUid(),
			$run->getDestinationSource(),
			$run->getDestinationStorageId(),
			$run->getDestinationFileId(),
			$run->getDestinationPath(),
			$run->getDestinationConfiguredBy(),
			$run->getDestinationStorageRootId(),
			$run->getDestinationMountType(),
			$run->getDestinationMountProvider(),
			$run->getDestinationMountId(),
			$run->getDestinationNumericStorageId(),
			$run->getRunFolderPath(),
			$run->getRunFolderStorageRootId(),
			$run->getRunFolderMountType(),
			$run->getRunFolderMountProvider(),
			$run->getRunFolderMountId(),
			$run->getRunFolderNumericStorageId(),
		];
		if (array_filter($destinationMetadata, static fn (mixed $value): bool => $value !== null) !== []) {
			// Destination metadata present but no managed folder: corrupt.
			throw new ValidationException('destination_invalid_config');
		}

		// Legacy run (started before the Files milestone): no managed folder
		// exists yet, so the upload fails closed until migration (#55).
		throw new ConflictException('destination_unavailable');
	}

	/**
	 * Write validated bytes into the run-managed folder and persist the
	 * Files-backed attachment row. Shared by the upload (#50) and
	 * copy-from-Files (#53) paths so identity resolution, metadata and cleanup
	 * stay identical.
	 *
	 * Product rule: new evidence is always stored in Nextcloud Files, never in
	 * AppData. A run without a usable managed folder fails closed instead of
	 * falling back (legacy runs are migrated by #55). If metadata persistence
	 * fails, only the file created by this attempt is removed.
	 *
	 * @throws ConflictException|ValidationException
	 */
	private function storeFilesAttachment(Run $run, int $stepId, string $filename, string $content, string $mimeType): Attachment {
		$size = strlen($content);
		$checksum = hash('sha256', $content);
		$runId = $run->getId();
		$now = $this->timeFactory->getTime();
		$ownerUid = $run->getOwner();

		$this->assertUploadDestinationUsable($run);
		$file = $this->filesStorage->write($ownerUid, $run, $filename, $content);

		try {
			$attachment = new Attachment();
			$attachment->setUuid($this->generateUuid());
			$attachment->setRunId($runId);
			$attachment->setStepId($stepId);
			$attachment->setUploaderUid($this->currentUserId());
			$attachment->setFilename($filename);
			$attachment->setMimeType($mimeType);
			$attachment->setSize($size);
			$attachment->setChecksum($checksum);
			$attachment->setCreatedAt($now);

			$descriptor = $this->filesStorage->describe($file);
			$attachment->setStorageKind(Attachment::STORAGE_KIND_FILES);
			$attachment->setStorageKey('');
			$attachment->setFileId($descriptor['fileId']);
			$attachment->setStorageId($descriptor['storageId']);
			$attachment->setStorageRootId($descriptor['storageRootId']);
			$attachment->setMountType($descriptor['mountType']);
			$attachment->setMountProvider($descriptor['mountProvider']);
			$attachment->setMountId($descriptor['mountId']);
			$attachment->setNumericStorageId($descriptor['numericStorageId']);
			$attachment->setPath($descriptor['path']);

			$attachment = $this->attachments->insert($attachment);
		} catch (\Throwable $exception) {
			// Metadata creation failed: never leave an orphan file behind.
			$this->filesStorage->deleteNode($file);
			throw $exception;
		}

		$this->activity->record($runId, $stepId, ActivityType::AttachmentUploaded, [
			'attachmentId' => $attachment->getId(),
			'filename' => $filename,
			'mimeType' => $mimeType,
			'size' => $size,
		], $this->currentUserId());

		return $attachment;
	}

	/**
	 * Read the advisory source path from the copy request body (issue #53).
	 *
	 * @param array<string, mixed> $data
	 *
	 * @throws ValidationException
	 */
	private function readSourcePath(array $data): string {
		if (!array_key_exists('sourcePath', $data) || $data['sourcePath'] === null) {
			throw new ValidationException('attachment_source_required');
		}
		if (!is_string($data['sourcePath'])) {
			throw new ValidationException('invalid_field');
		}
		$path = trim($data['sourcePath']);
		if ($path === '') {
			throw new ValidationException('attachment_source_required');
		}

		return $path;
	}

	private function requireAttachment(int $id): Attachment {
		try {
			return $this->attachments->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('attachment_not_found');
		}
	}

	private function sanitizeFilename(string $name): string {
		$name = trim($name);
		if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
			throw new ValidationException('attachment_invalid_filename');
		}

		$name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
		$name = trim($name);
		if ($name === '') {
			throw new ValidationException('attachment_invalid_filename');
		}
		if (strcasecmp($name, RunDestinationResolver::MARKER_FILE_NAME) === 0) {
			// The reserved ownership marker must never be uploaded as evidence.
			throw new ValidationException('attachment_invalid_filename');
		}
		if (mb_strlen($name) > self::MAX_FILENAME_LENGTH) {
			throw new ValidationException('attachment_filename_too_long');
		}

		return $name;
	}

	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new ForbiddenException('not_authenticated');
		}

		return $user->getUID();
	}

	private function generateUuid(): string {
		$hex = $this->secureRandom->generate(32, '0123456789abcdef');
		$hex = substr_replace($hex, '4', 12, 1);
		$variant = dechex(0x8 | ((int)hexdec($hex[16]) & 0x3));
		$hex = substr_replace($hex, $variant, 16, 1);

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr($hex, 0, 8),
			substr($hex, 8, 4),
			substr($hex, 12, 4),
			substr($hex, 16, 4),
			substr($hex, 20, 12),
		);
	}
}
