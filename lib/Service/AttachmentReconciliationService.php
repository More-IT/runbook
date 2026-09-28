<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\Attachment;
use OCA\Runbook\Db\AttachmentMapper;
use OCA\Runbook\Db\Run;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\StorageNotAvailableException;
use Psr\Log\LoggerInterface;

/**
 * Out-of-band Files reconciliation for tracked evidence (issue #52).
 *
 * For every Files-backed attachment it recomputes the current `file_state`
 * against the run-managed scope, following docs/folder-model.md §2.2/§3/§7:
 *
 * - `present`      exactly one in-scope candidate by exact `(storageId, fileId)`;
 * - `missing`      the tracked file (or its managed folder) no longer exists;
 * - `out_of_scope` the same id+storage exists but is outside the managed folder;
 * - `unavailable`  ambiguous, moved to another storage, or a storage/mount error.
 *
 * The state is never inferred from a name, mount descriptor or permission; a
 * candidate that cannot be resolved unambiguously fails closed. Legacy AppData
 * rows (pre-#55) are reported `present` so existing runs keep working. Present
 * files have their advisory `path`/descriptor metadata refreshed best-effort.
 */
class AttachmentReconciliationService {
	public const PRESENT = 'present';
	public const MISSING = 'missing';
	public const OUT_OF_SCOPE = 'out_of_scope';
	public const UNAVAILABLE = 'unavailable';

	// Run-managed folder availability, used for truthful user-facing copy.
	public const FOLDER_AVAILABLE = 'available';
	public const FOLDER_MISSING = 'missing';
	public const FOLDER_UNAVAILABLE = 'unavailable';
	public const FOLDER_NOT_APPLICABLE = 'not_applicable';

	public function __construct(
		private readonly FilesRootProvider $filesRoot,
		private readonly AttachmentMapper $attachments,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Current availability of the run-managed folder, independent of its files.
	 *
	 * Used only to make degraded-evidence copy truthful: a missing folder cannot
	 * receive new uploads, so the UI must not promise a replacement will work.
	 * `not_applicable` means the run has no Files destination (legacy AppData).
	 */
	public function managedFolderState(Run $run): string {
		$fileId = $run->getRunFolderFileId();
		$storageId = $run->getRunFolderStorageId();
		$hasFileId = $fileId !== null && $fileId > 0;
		$hasStorageId = $storageId !== null && $storageId !== '';

		if (!$hasFileId && !$hasStorageId) {
			if ($run->getDestinationSource() === null
				&& $run->getDestinationStorageId() === null
				&& $run->getDestinationFileId() === null) {
				return self::FOLDER_NOT_APPLICABLE;
			}

			return self::FOLDER_UNAVAILABLE;
		}
		if (!$hasFileId || !$hasStorageId) {
			return self::FOLDER_UNAVAILABLE;
		}

		try {
			$view = $this->filesRoot->getUserFolder($run->getOwner());
			$folders = [];
			foreach ($view->getById($fileId) as $node) {
				if ($node instanceof Folder && $node->getStorage()->getId() === $storageId) {
					$folders[] = $node;
				}
			}
		} catch (StorageNotAvailableException) {
			return self::FOLDER_UNAVAILABLE;
		} catch (\Throwable) {
			return self::FOLDER_UNAVAILABLE;
		}

		if (count($folders) === 0) {
			return self::FOLDER_MISSING;
		}
		if (count($folders) > 1) {
			return self::FOLDER_UNAVAILABLE;
		}
		$folder = $folders[0];
		if (!$folder->isCreatable() || ($folder->getPermissions() & Constants::PERMISSION_CREATE) === 0) {
			// Resolvable but not writable: uploads are blocked.
			return self::FOLDER_UNAVAILABLE;
		}

		return self::FOLDER_AVAILABLE;
	}

	/**
	 * Current state of one attachment, with the resolved node when `present`.
	 *
	 * @return array{state: string, file: File|null}
	 */
	public function reconcile(Run $run, Attachment $attachment): array {
		if ($attachment->getStorageKind() !== Attachment::STORAGE_KIND_FILES) {
			// Legacy AppData evidence remains valid until #55 migrates it.
			return ['state' => self::PRESENT, 'file' => null];
		}

		$fileId = $attachment->getFileId();
		$storageId = $attachment->getStorageId();
		$folderFileId = $run->getRunFolderFileId();
		$folderStorageId = $run->getRunFolderStorageId();
		if ($fileId === null || $fileId <= 0 || $storageId === null || $storageId === ''
			|| $folderFileId === null || $folderFileId <= 0 || $folderStorageId === null || $folderStorageId === '') {
			return ['state' => self::UNAVAILABLE, 'file' => null];
		}

		try {
			$view = $this->filesRoot->getUserFolder($run->getOwner());
		} catch (StorageNotAvailableException) {
			return ['state' => self::UNAVAILABLE, 'file' => null];
		} catch (\Throwable $exception) {
			return $this->unavailable($attachment, $exception);
		}

		// Canonical parent: the run-managed folder by exact identity (§2.2).
		try {
			$folders = [];
			foreach ($view->getById($folderFileId) as $node) {
				if ($node instanceof Folder && $node->getStorage()->getId() === $folderStorageId) {
					$folders[] = $node;
				}
			}
		} catch (StorageNotAvailableException) {
			return ['state' => self::UNAVAILABLE, 'file' => null];
		} catch (\Throwable $exception) {
			return $this->unavailable($attachment, $exception);
		}

		if (count($folders) === 0) {
			// The managed folder was deleted or is no longer reachable: every
			// file inside it is missing (never recreated here).
			return ['state' => self::MISSING, 'file' => null];
		}
		if (count($folders) > 1) {
			return $this->unavailable($attachment, null, 'managed folder resolves ambiguously');
		}
		$folder = $folders[0];

		// Stage 1 — canonical in-scope source: the run-managed parent folder
		// (§2.2). A node returned by the parent's getById() is by construction
		// inside that folder, so only the exact storage id needs to be checked.
		try {
			$inScope = [];
			foreach ($folder->getById($fileId) as $node) {
				if (!$node instanceof File) {
					continue;
				}
				try {
					$nodeStorageId = $node->getStorage()->getId();
				} catch (StorageNotAvailableException) {
					return ['state' => self::UNAVAILABLE, 'file' => null];
				} catch (\Throwable $exception) {
					return $this->unavailable($attachment, $exception);
				}
				if ($nodeStorageId === $storageId) {
					$inScope[] = $node;
				}
			}
		} catch (StorageNotAvailableException) {
			return ['state' => self::UNAVAILABLE, 'file' => null];
		} catch (\Throwable $exception) {
			return $this->unavailable($attachment, $exception);
		}

		if (count($inScope) > 1) {
			return $this->unavailable($attachment, null, 'tracked file resolves ambiguously in the managed folder');
		}
		if (count($inScope) === 1) {
			$this->refreshMetadata($attachment, $inScope[0]);

			return ['state' => self::PRESENT, 'file' => $inScope[0]];
		}

		// Stage 2 — diagnostic root-view lookup, classification only. It is never
		// an authoritative candidate source and its result is never merged with
		// stage 1; it only explains why stage 1 found no in-scope node.
		try {
			$nodes = $view->getById($fileId);
		} catch (StorageNotAvailableException) {
			return ['state' => self::UNAVAILABLE, 'file' => null];
		} catch (\Throwable $exception) {
			return $this->unavailable($attachment, $exception);
		}

		$sameStorage = [];
		$otherStorage = 0;
		foreach ($nodes as $node) {
			if (!$node instanceof File) {
				continue;
			}
			try {
				$nodeStorageId = $node->getStorage()->getId();
			} catch (StorageNotAvailableException) {
				return ['state' => self::UNAVAILABLE, 'file' => null];
			} catch (\Throwable $exception) {
				return $this->unavailable($attachment, $exception);
			}
			if ($nodeStorageId === $storageId) {
				$sameStorage[] = $node;
			} else {
				$otherStorage++;
			}
		}

		// Ambiguity is defined only by the authoritative identity
		// `(storageId, fileId)`: several same-storage candidates are ambiguous
		// and fail closed. Nodes on other storages are not candidates and never
		// deduplicate or select one (no descriptor comparison, no first match).
		if (count($sameStorage) > 1) {
			return $this->unavailable($attachment, null, 'diagnostic lookup resolves ambiguously');
		}
		if (count($sameStorage) === 1) {
			// Exactly one same-identity node, but outside the managed folder.
			return ['state' => self::OUT_OF_SCOPE, 'file' => null];
		}
		if ($otherStorage > 0) {
			// The id is visible only on a different storage: treat as unavailable.
			return ['state' => self::UNAVAILABLE, 'file' => null];
		}

		return ['state' => self::MISSING, 'file' => null];
	}

	/**
	 * @return string One of {@see self::PRESENT}, MISSING, OUT_OF_SCOPE, UNAVAILABLE.
	 */
	public function stateFor(Run $run, Attachment $attachment): string {
		return $this->reconcile($run, $attachment)['state'];
	}

	/**
	 * Number of attachments that are currently usable as evidence (present).
	 *
	 * @param list<Attachment> $attachments
	 */
	public function presentCount(Run $run, array $attachments): int {
		$count = 0;
		foreach ($attachments as $attachment) {
			if ($this->stateFor($run, $attachment) === self::PRESENT) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Whether any attachment of the run is no longer usable as evidence.
	 *
	 * @param list<Attachment> $attachments
	 */
	public function degraded(Run $run, array $attachments): bool {
		foreach ($attachments as $attachment) {
			if ($this->stateFor($run, $attachment) !== self::PRESENT) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array{state: string, file: null}
	 */
	private function unavailable(Attachment $attachment, ?\Throwable $exception = null, ?string $reason = null): array {
		$this->logger->warning('Runbook evidence reconciliation failed closed', [
			'app' => 'runbook',
			'attachmentId' => (int)$attachment->getId(),
			'reason' => $reason,
			'exception' => $exception,
		]);

		return ['state' => self::UNAVAILABLE, 'file' => null];
	}

	/**
	 * Refresh the advisory path/descriptor metadata of a present file. Identity
	 * is never changed; failures are logged and never break reconciliation.
	 */
	private function refreshMetadata(Attachment $attachment, File $file): void {
		try {
			$mount = $file->getMountPoint();
			$path = $file->getPath();
			if ($attachment->getPath() === $path
				&& $attachment->getStorageRootId() === $mount->getStorageRootId()
				&& $attachment->getMountType() === $mount->getMountType()
				&& $attachment->getMountProvider() === $mount->getMountProvider()
				&& $attachment->getMountId() === $mount->getMountId()
				&& $attachment->getNumericStorageId() === $mount->getNumericStorageId()) {
				return;
			}

			$attachment->setPath($path);
			$attachment->setStorageRootId($mount->getStorageRootId());
			$attachment->setMountType($mount->getMountType());
			$attachment->setMountProvider($mount->getMountProvider());
			$attachment->setMountId($mount->getMountId());
			$attachment->setNumericStorageId($mount->getNumericStorageId());
			$this->attachments->update($attachment);
		} catch (\Throwable $exception) {
			$this->logger->warning('Runbook could not refresh evidence metadata', [
				'app' => 'runbook',
				'attachmentId' => (int)$attachment->getId(),
				'exception' => $exception,
			]);
		}
	}
}
