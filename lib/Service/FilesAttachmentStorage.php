<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\Attachment;
use OCA\Runbook\Db\Run;
use OCP\Constants;
use OCP\Files\AlreadyExistsException;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Evidence file storage backed by the run's managed folder in Nextcloud Files
 * (issue #50).
 *
 * The run-managed folder is resolved in the **run owner's** view by exact
 * `(storageId, fileId)`; a missing or ambiguous folder fails closed
 * (docs/folder-model.md §5.2) and is never silently recreated or replaced by
 * AppData. Identity is `(storageId, fileId)`; the remaining descriptor fields
 * are audit/display metadata only.
 */
class FilesAttachmentStorage {
	private const CREATE_MAX_ATTEMPTS = 5;

	public function __construct(
		private readonly RunDestinationResolver $destinations,
		private readonly AttachmentReconciliationService $reconciliation,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Create the evidence file inside the run-managed folder.
	 *
	 * The physical name is made unique (`Folder::getNonExistingName()`) so an
	 * upload never overwrites an existing file. The reserved ownership marker is
	 * rejected by the caller before reaching here.
	 *
	 * @return File The created node.
	 *
	 * @throws ValidationException|ConflictException
	 */
	public function write(string $ownerUid, Run $run, string $filename, string $content): File {
		$folder = $this->destinations->resolveManagedFolder(
			$ownerUid,
			$run->getRunFolderFileId(),
			$run->getRunFolderStorageId(),
		);

		for ($attempt = 0; $attempt < self::CREATE_MAX_ATTEMPTS; $attempt++) {
			try {
				$name = $folder->getNonExistingName($filename);

				return $folder->newFile($name, $content);
			} catch (\Throwable $exception) {
				// A racing create can claim the name between getNonExistingName()
				// and newFile(); retry with a fresh unique name before giving up.
				if ($exception instanceof AlreadyExistsException) {
					if ($attempt === self::CREATE_MAX_ATTEMPTS - 1) {
						throw new ConflictException('attachment_name_collision');
					}
					continue;
				}

				throw $this->destinations->translateFilesError($exception);
			}
		}

		throw new ConflictException('attachment_name_collision');
	}

	/**
	 * Find an existing child of the run-managed folder by exact name (#55
	 * migration resume). Returns null when it does not exist; a non-file node
	 * with that name is a conflict.
	 *
	 * @throws ValidationException|ConflictException
	 */
	public function findInManagedFolder(Run $run, string $name): ?File {
		$folder = $this->destinations->resolveManagedFolder(
			$run->getOwner(),
			$run->getRunFolderFileId(),
			$run->getRunFolderStorageId(),
			false,
		);

		try {
			if (!$folder->nodeExists($name)) {
				return null;
			}
			$node = $folder->get($name);
		} catch (\Throwable $exception) {
			throw $this->destinations->translateFilesError($exception);
		}
		if (!$node instanceof File) {
			throw new ConflictException('migration_target_conflict');
		}

		return $node;
	}

	/**
	 * Write content into the run-managed folder under an **exact** name (#55
	 * migration). Never overwrites: an existing name raises
	 * `migration_target_exists` so the caller can re-read and verify instead of
	 * duplicating.
	 *
	 * @throws ValidationException|ConflictException
	 */
	public function writeNamed(Run $run, string $name, string $content): File {
		$folder = $this->destinations->resolveManagedFolder(
			$run->getOwner(),
			$run->getRunFolderFileId(),
			$run->getRunFolderStorageId(),
		);

		try {
			return $folder->newFile($name, $content);
		} catch (\Throwable $exception) {
			if ($exception instanceof AlreadyExistsException) {
				throw new ConflictException('migration_target_exists');
			}

			throw $this->destinations->translateFilesError($exception);
		}
	}

	/**
	 * Read a tracked attachment file, reconciling it against the run-managed
	 * scope first. Missing, out-of-scope and unavailable evidence fail closed.
	 *
	 * @throws ConflictException|ValidationException
	 */
	public function read(Run $run, Attachment $attachment): string {
		$result = $this->reconciliation->reconcile($run, $attachment);
		if ($result['state'] !== AttachmentReconciliationService::PRESENT || $result['file'] === null) {
			throw $this->errorForState($result['state']);
		}

		try {
			return $result['file']->getContent();
		} catch (\Throwable $exception) {
			throw $this->destinations->translateFilesError($exception);
		}
	}

	/**
	 * Delete a tracked attachment file after reconciliation. A file that is
	 * already missing is treated as success so the metadata delete is
	 * idempotent; out-of-scope and unavailable evidence is never deleted.
	 *
	 * @throws ConflictException|ValidationException
	 */
	public function delete(Run $run, Attachment $attachment): void {
		$result = $this->reconciliation->reconcile($run, $attachment);
		if ($result['state'] === AttachmentReconciliationService::MISSING) {
			return;
		}
		if ($result['state'] !== AttachmentReconciliationService::PRESENT || $result['file'] === null) {
			throw $this->errorForState($result['state']);
		}

		try {
			$result['file']->delete();
		} catch (\Throwable $exception) {
			throw $this->destinations->translateFilesError($exception);
		}
	}

	/**
	 * Fail-closed deletion of a run's tracked Files evidence (issue #54).
	 *
	 * Every present, in-scope file is pre-flighted **on its own node** (never by
	 * inferring a child's deletability from its parent). If any file denies
	 * delete permission or cannot be resolved, **nothing is deleted** and the
	 * failing attachments are returned, so the caller aborts the whole run
	 * deletion with all identities intact for retry. Already-missing files are
	 * treated as gone; out-of-scope files are never deleted and never block.
	 *
	 * If a deletion fails mid-way, the files already deleted stay deleted (their
	 * rows are retained until the run-row deletion) and the remaining failures
	 * are returned; retrying skips the already-deleted (now missing) files.
	 *
	 * @param list<Attachment> $attachments
	 * @return array{deleted: list<int>, blocked: list<array{attachmentId: int, reason: string}>}
	 */
	public function deleteTrackedEvidence(Run $run, array $attachments): array {
		$toDelete = [];
		$deleted = [];
		$blocked = [];

		foreach ($attachments as $attachment) {
			$result = $this->reconciliation->reconcile($run, $attachment);
			if ($result['state'] === AttachmentReconciliationService::PRESENT && $result['file'] !== null) {
				$file = $result['file'];
				if (!$file->isDeletable() || ($file->getPermissions() & Constants::PERMISSION_DELETE) === 0) {
					$blocked[] = ['attachmentId' => (int)$attachment->getId(), 'reason' => 'not_deletable'];
					continue;
				}
				$toDelete[] = [$attachment, $file];
				continue;
			}
			if ($result['state'] === AttachmentReconciliationService::UNAVAILABLE) {
				$blocked[] = ['attachmentId' => (int)$attachment->getId(), 'reason' => 'unavailable'];
			}
			// MISSING and OUT_OF_SCOPE: nothing to delete.
		}

		if ($blocked !== []) {
			return ['deleted' => [], 'blocked' => $blocked];
		}

		foreach ($toDelete as [$attachment, $file]) {
			try {
				$file->delete();
				$deleted[] = (int)$attachment->getId();
			} catch (\Throwable $exception) {
				$this->logger->warning('Runbook could not delete tracked Files evidence', [
					'app' => 'runbook',
					'attachmentId' => (int)$attachment->getId(),
					'exception' => $exception,
				]);
				$blocked[] = ['attachmentId' => (int)$attachment->getId(), 'reason' => 'delete_failed'];
			}
		}

		return ['deleted' => $deleted, 'blocked' => $blocked];
	}

	/**
	 * Map a reconciliation state to the matching fail-closed error.
	 */
	private function errorForState(string $state): \Throwable {
		return match ($state) {
			AttachmentReconciliationService::OUT_OF_SCOPE => new ConflictException('attachment_out_of_scope'),
			AttachmentReconciliationService::UNAVAILABLE => new ConflictException('destination_unavailable'),
			default => new ConflictException('attachment_missing'),
		};
	}

	/**
	 * Identity and descriptive metadata for a created node.
	 *
	 * @return array{storageId: string, fileId: int, storageRootId: int, mountType: string, mountProvider: string, mountId: int|null, numericStorageId: int|null, path: string}
	 */
	public function describe(File $file): array {
		return $this->destinations->nodeDescriptor($file);
	}

	/**
	 * Remove a just-created node when persisting its metadata failed. Failures
	 * are logged; the original error is always rethrown by the caller.
	 */
	public function deleteNode(File $file): void {
		try {
			$file->delete();
		} catch (\Throwable $exception) {
			$this->logger->warning('Runbook could not clean up an orphaned Files evidence file', [
				'app' => 'runbook',
				'fileId' => (int)$file->getId(),
				'exception' => $exception,
			]);
		}
	}
}
