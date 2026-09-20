<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\Attachment;
use OCA\Runbook\Db\AttachmentMapper;
use OCA\Runbook\Enum\ActivityType;
use OCA\Runbook\Enum\RunStatus;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

/**
 * Evidence attachments stored in Nextcloud AppData.
 *
 * The binary content lives under an application-controlled AppData path; the
 * database only stores metadata and a random storage key that is never exposed
 * through the API.
 */
class AttachmentService {
	private const MAX_FILENAME_LENGTH = 255;
	private const STORAGE_KEY_LENGTH = 32;

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
		private readonly RunAccessService $access,
		private readonly EvidenceStorage $storage,
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
	 * Upload evidence to a step the current user may execute.
	 *
	 * @param array<array-key, mixed> $file Raw upload entry.
	 */
	public function upload(int $stepId, array $file): Attachment {
		[$run, $step] = $this->runStepService->requireExecutableStep($stepId);

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

		$checksum = hash('sha256', $content);
		$storageKey = $this->secureRandom->generate(self::STORAGE_KEY_LENGTH, '0123456789abcdef');
		$runId = $run->getId();
		$now = $this->timeFactory->getTime();

		$this->storage->write($runId, $stepId, $storageKey, $content);

		try {
			$attachment = new Attachment();
			$attachment->setUuid($this->generateUuid());
			$attachment->setRunId($runId);
			$attachment->setStepId($stepId);
			$attachment->setUploaderUid($this->currentUserId());
			$attachment->setFilename($filename);
			$attachment->setStorageKey($storageKey);
			$attachment->setMimeType($mimeType);
			$attachment->setSize($size);
			$attachment->setChecksum($checksum);
			$attachment->setCreatedAt($now);
			$attachment = $this->attachments->insert($attachment);
		} catch (\Throwable $exception) {
			// Metadata creation failed: never leave an orphan file behind.
			$this->storage->delete($runId, $stepId, $storageKey);
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
	 * @return array{attachment: Attachment, content: string}
	 */
	public function download(int $id): array {
		$attachment = $this->requireAttachment($id);
		$this->runService->requireAccessibleRun($attachment->getRunId());

		$content = $this->storage->read(
			$attachment->getRunId(),
			$attachment->getStepId(),
			$attachment->getStorageKey(),
		);

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

		// Persist the event before removing the metadata.
		$this->activity->record($run->getId(), $attachment->getStepId(), ActivityType::AttachmentDeleted, [
			'attachmentId' => $attachment->getId(),
			'filename' => $attachment->getFilename(),
		], $uid);

		$this->storage->delete($run->getId(), $attachment->getStepId(), $attachment->getStorageKey());
		$this->attachments->delete($attachment);
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
