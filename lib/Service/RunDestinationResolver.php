<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\Run;
use OCP\Constants;
use OCP\Files\AlreadyExistsException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException as FilesForbiddenException;
use OCP\Files\InvalidPathException;
use OCP\Files\Node;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\StorageNotAvailableException;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Resolves and prepares the default Files destination for a new run (issue #46).
 *
 * Behaviour is defined by docs/folder-model.md:
 * - the default base folder is `Files/Runbook` inside the run owner's own view;
 * - one managed subfolder is created per run, named from the run title plus the
 *   full run UUID (the deterministic, per-run collision discriminator);
 * - every managed folder carries a small ownership marker (full run UUID +
 *   format/version) so a retry can verify the folder belongs to the run and an
 *   unrelated folder with a matching name is never adopted;
 * - identity is `viewUid + (storageId, fileId)`; every other field is
 *   non-authoritative descriptor metadata;
 * - failures are actionable and never fall back to another user's view or to
 *   AppData;
 * - base and per-run folder creation are guarded by the shared locking provider.
 *
 * Destination precedence follows docs/folder-model.md §1. Only two levels are
 * implemented: the global administration reference (#47, `source = admin`) when
 * one is configured, otherwise the `default` `Files/Runbook` folder. The
 * run-time (#49) and template (#48) levels are not implemented yet and must be
 * added above the administration level.
 */
class RunDestinationResolver {
	public const MARKER_FILE_NAME = '.runbook-run.json';
	public const MARKER_FORMAT = 'runbook-run-folder';
	public const MARKER_VERSION = 1;

	private const BASE_FOLDER_NAME = 'Runbook';
	private const FALLBACK_FOLDER_NAME = 'Run';
	private const MAX_FOLDER_NAME_LENGTH = 255;

	private const LOCK_MAX_ATTEMPTS = 20;
	private const LOCK_RETRY_MICROSECONDS = 25000;

	public function __construct(
		private readonly FilesRootProvider $filesRoot,
		private readonly ILockingProvider $locking,
		private readonly IUserManager $userManager,
		private readonly AdminSettings $adminSettings,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Resolve and prepare the destination and managed folder for a run.
	 *
	 * Destination precedence (docs/folder-model.md §1), highest first:
	 * run-time choice (#49) > template destination (#48) >
	 * global administration destination (#47) > default `Files/Runbook` (#46).
	 *
	 * A configured reference at any level is authoritative: it is re-resolved in
	 * the run owner's view by exact `(storageId, fileId)` and fails closed when it
	 * cannot be resolved safely. It never falls through to a lower level. When no
	 * reference is configured the #46 default folder is used/created.
	 *
	 * @param DestinationReference|null $templateReference Optional per-template
	 *                                                     reference (#48). `null` means "unset"; an incomplete reference must
	 *                                                     already have been rejected by the caller (fail closed).
	 * @param DestinationReference|null $runtimeReference Optional one-time run
	 *                                                    start choice (#49), already captured from the authenticated starter's view.
	 *                                                    When present it is authoritative and never falls through.
	 *
	 * @throws ValidationException When the configured reference is incomplete or
	 *                             the resolved folder/name is invalid.
	 * @throws ConflictException When permissions, availability, ambiguity or a
	 *                           missing/inaccessible configured folder prevent a
	 *                           safe destination.
	 */
	public function resolveForRun(
		string $viewUid,
		string $runUuid,
		string $runTitle,
		?DestinationReference $templateReference = null,
		?DestinationReference $runtimeReference = null,
	): ResolvedRunDestination {
		$view = $this->view($viewUid);

		if ($runtimeReference !== null) {
			$base = $this->resolveConfiguredFolder($view, $runtimeReference);
			$source = ResolvedRunDestination::SOURCE_RUNTIME;
			$configuredBy = $runtimeReference->configuredBy;
		} elseif ($templateReference !== null) {
			$base = $this->resolveConfiguredFolder($view, $templateReference);
			$source = ResolvedRunDestination::SOURCE_TEMPLATE;
			$configuredBy = $templateReference->configuredBy;
		} else {
			$reference = $this->adminSettings->getDestinationReference();
			if ($reference !== null) {
				$base = $this->resolveConfiguredFolder($view, $reference);
				$source = ResolvedRunDestination::SOURCE_ADMIN;
				$configuredBy = $reference->configuredBy;
			} else {
				$base = $this->withLock(
					'runbook/destination/' . $viewUid . '/' . self::BASE_FOLDER_NAME,
					fn (): Folder => $this->ensureBaseFolder($view),
				);
				$source = ResolvedRunDestination::SOURCE_DEFAULT;
				$configuredBy = null;
			}
		}
		$baseDescriptor = $this->descriptor($base);

		$folderName = $this->folderName($runTitle, $runUuid);
		[$folder, $created] = $this->withLock(
			'runbook/run-folder/' . $runUuid,
			fn (): array => $this->ensureRunFolder($base, $folderName, $runUuid),
		);
		$folderDescriptor = $this->descriptor($folder);

		return new ResolvedRunDestination(
			runUuid: $runUuid,
			viewUid: $viewUid,
			source: $source,
			configuredBy: $configuredBy,
			storageId: $baseDescriptor['storageId'],
			fileId: $baseDescriptor['fileId'],
			path: $baseDescriptor['path'],
			storageRootId: $baseDescriptor['storageRootId'],
			mountType: $baseDescriptor['mountType'],
			mountProvider: $baseDescriptor['mountProvider'],
			mountId: $baseDescriptor['mountId'],
			numericStorageId: $baseDescriptor['numericStorageId'],
			folderFileId: $folderDescriptor['fileId'],
			folderStorageId: $folderDescriptor['storageId'],
			folderPath: $folderDescriptor['path'],
			folderStorageRootId: $folderDescriptor['storageRootId'],
			folderMountType: $folderDescriptor['mountType'],
			folderMountProvider: $folderDescriptor['mountProvider'],
			folderMountId: $folderDescriptor['mountId'],
			folderNumericStorageId: $folderDescriptor['numericStorageId'],
			folderCreated: $created,
		);
	}

	/**
	 * Persist the resolved destination on a run row.
	 */
	public function applyToRun(Run $run, ResolvedRunDestination $destination): void {
		$run->setDestinationViewUid($destination->viewUid);
		$run->setDestinationSource($destination->source);
		$run->setDestinationStorageId($destination->storageId);
		$run->setDestinationFileId($destination->fileId);
		$run->setDestinationPath($destination->path);
		$run->setDestinationConfiguredBy($destination->configuredBy);
		$run->setDestinationStorageRootId($destination->storageRootId);
		$run->setDestinationMountType($destination->mountType);
		$run->setDestinationMountProvider($destination->mountProvider);
		$run->setDestinationMountId($destination->mountId);
		$run->setDestinationNumericStorageId($destination->numericStorageId);
		$run->setRunFolderFileId($destination->folderFileId);
		$run->setRunFolderStorageId($destination->folderStorageId);
		$run->setRunFolderPath($destination->folderPath);
		$run->setRunFolderStorageRootId($destination->folderStorageRootId);
		$run->setRunFolderMountType($destination->folderMountType);
		$run->setRunFolderMountProvider($destination->folderMountProvider);
		$run->setRunFolderMountId($destination->folderMountId);
		$run->setRunFolderNumericStorageId($destination->folderNumericStorageId);
	}

	/**
	 * Compensating action for a failed run start.
	 *
	 * Runbook must **not** remove the managed folder it just created: the public
	 * Nextcloud Files API only offers recursive `Folder::delete()`, which cannot
	 * be made atomic against a user adding a file after any emptiness check
	 * (issues #46/#54). The folder, its ownership marker and any content are
	 * therefore **preserved**.
	 *
	 * Preservation is an **orphan, not a resume**: `RunService::startRun()`
	 * generates a fresh run UUID on every invocation (there is no start
	 * idempotency key) and the folder name embeds that UUID, so a later start —
	 * even with the same title — creates a *new* folder and never adopts this one
	 * (adoption requires the marker UUID to match the current attempt). The
	 * preserved folder therefore requires manual cleanup; its authoritative
	 * identity is logged so an administrator can locate it. No content is ever
	 * deleted and the original start error is rethrown by the caller.
	 */
	public function compensate(ResolvedRunDestination $destination): void {
		if (!$destination->folderCreated) {
			return;
		}

		$this->logger->warning('Runbook preserved an orphaned managed run folder after a failed start', [
			'app' => 'runbook',
			'viewUid' => $destination->viewUid,
			'storageId' => $destination->folderStorageId,
			'fileId' => $destination->folderFileId,
		]);
	}

	/**
	 * Resolve exactly one folder by id in a user's view using a single canonical
	 * `Folder::getById()` source. Multiple in-scope candidates fail closed.
	 *
	 * @throws ConflictException When no or more than one candidate exists.
	 */
	public function resolveSingleFolder(string $viewUid, int $fileId): Folder {
		$view = $this->view($viewUid);
		try {
			$nodes = $view->getById($fileId);
		} catch (\Throwable $exception) {
			throw $this->mapFilesError($exception);
		}

		$candidates = [];
		foreach ($nodes as $node) {
			if ($node instanceof Folder) {
				$candidates[] = $node;
			}
		}

		if (count($candidates) === 0) {
			throw new ConflictException('destination_unavailable');
		}
		if (count($candidates) > 1) {
			throw new ConflictException('destination_ambiguous');
		}

		return $candidates[0];
	}

	/**
	 * @throws ConflictException When the user is missing or the view is unavailable.
	 */
	private function view(string $viewUid): Folder {
		$user = $this->userManager->get($viewUid);
		if ($user === null || !$user->isEnabled()) {
			throw new ConflictException('destination_owner_missing');
		}

		try {
			return $this->filesRoot->getUserFolder($viewUid);
		} catch (\Throwable $exception) {
			throw $this->mapFilesError($exception);
		}
	}

	/**
	 * Re-resolve a configured global administration reference (#47) in the given
	 * user's view, by exact `file_id` and exact `storage_id`.
	 *
	 * The chooser's (administrator's) mount descriptor is never consulted: two
	 * users can reach the same folder through different mounts, so only the
	 * owner's own `getById()` candidate that matches the stored storage id is
	 * authoritative. Missing/inaccessible (zero candidates) and ambiguous (more
	 * than one in-scope candidate) both fail closed, as does a non-writable
	 * folder.
	 *
	 * @throws ValidationException|ConflictException
	 */
	private function resolveConfiguredFolder(Folder $view, DestinationReference $reference): Folder {
		try {
			$nodes = $view->getById($reference->fileId);
		} catch (\Throwable $exception) {
			throw $this->mapFilesError($exception);
		}

		$candidates = [];
		foreach ($nodes as $node) {
			if (!$node instanceof Folder) {
				continue;
			}
			if ($this->storageIdOf($node) !== $reference->storageId) {
				continue;
			}
			$candidates[] = $node;
		}

		if (count($candidates) === 0) {
			throw new ConflictException('destination_no_access');
		}
		if (count($candidates) > 1) {
			throw new ConflictException('destination_ambiguous');
		}

		$base = $candidates[0];
		if (!$base->isCreatable() || ($base->getPermissions() & Constants::PERMISSION_CREATE) === 0) {
			throw new ConflictException('destination_not_writable');
		}

		return $base;
	}

	/**
	 * Capture a folder reference from a user-visible path in the given user's own
	 * view (configuration time, #47).
	 *
	 * The client only supplies a locator path inside the authenticated
	 * administrator's Files; identity (`storageId`, `fileId`) is always captured
	 * server-side from their view and the path becomes advisory display text.
	 *
	 * @throws ValidationException|ConflictException
	 */
	public function captureReference(string $viewUid, string $path): DestinationReference {
		$view = $this->view($viewUid);
		$relative = ltrim(str_replace('\\', '/', $path), '/');

		try {
			$node = $relative === '' ? $view : $view->get($relative);
		} catch (NotPermittedException) {
			throw new ConflictException('destination_no_access');
		} catch (FilesNotFoundException) {
			throw new ValidationException('destination_invalid');
		} catch (\Throwable $exception) {
			throw $this->mapFilesError($exception);
		}

		if (!$node instanceof Folder) {
			throw new ValidationException('destination_invalid');
		}
		if (!$node->isCreatable() || ($node->getPermissions() & Constants::PERMISSION_CREATE) === 0) {
			throw new ConflictException('destination_not_writable');
		}

		try {
			$storageId = $node->getStorage()->getId();
			$displayPath = $view->getRelativePath($node->getPath());
		} catch (\Throwable $exception) {
			throw $this->mapFilesError($exception);
		}

		return new DestinationReference($storageId, (int)$node->getId(), $displayPath, $viewUid);
	}

	/**
	 * Resolve the frozen run-managed folder in the run owner's view by exact
	 * `(storageId, fileId)` (issue #50).
	 *
	 * The folder must resolve exactly once; it is never silently recreated
	 * (docs/folder-model.md §5.2). Writability is required only by operations that
	 * create files; reads and deletes rely on the specific node's Files checks.
	 *
	 * @throws ConflictException|ValidationException
	 */
	public function resolveManagedFolder(string $viewUid, ?int $fileId, ?string $storageId, bool $requireWritable = true): Folder {
		if ($fileId === null || $fileId <= 0 || $storageId === null || $storageId === '') {
			throw new ConflictException('destination_unavailable');
		}

		$view = $this->view($viewUid);
		try {
			$nodes = $view->getById($fileId);
		} catch (\Throwable $exception) {
			throw $this->mapFilesError($exception);
		}

		$candidates = [];
		foreach ($nodes as $node) {
			if ($node instanceof Folder && $this->storageIdOf($node) === $storageId) {
				$candidates[] = $node;
			}
		}

		if (count($candidates) === 0) {
			throw new ConflictException('destination_unavailable');
		}
		if (count($candidates) > 1) {
			throw new ConflictException('destination_ambiguous');
		}

		$folder = $candidates[0];
		if ($requireWritable && (!$folder->isCreatable() || ($folder->getPermissions() & Constants::PERMISSION_CREATE) === 0)) {
			throw new ConflictException('destination_not_writable');
		}

		return $folder;
	}

	/**
	 * Resolve a tracked attachment file in the run owner's view by exact
	 * `(storageId, fileId)` (issue #50).
	 *
	 * @throws ConflictException When the file is missing, ambiguous or unreadable.
	 */
	public function resolveTrackedFile(string $viewUid, ?string $storageId, ?int $fileId): File {
		if ($fileId === null || $fileId <= 0 || $storageId === null || $storageId === '') {
			throw new ConflictException('attachment_missing');
		}

		$view = $this->view($viewUid);
		try {
			$nodes = $view->getById($fileId);
		} catch (\Throwable $exception) {
			throw $this->mapFilesError($exception);
		}

		$candidates = [];
		foreach ($nodes as $node) {
			if ($node instanceof File && $this->storageIdOf($node) === $storageId) {
				$candidates[] = $node;
			}
		}

		if (count($candidates) === 0) {
			throw new ConflictException('attachment_missing');
		}
		if (count($candidates) > 1) {
			throw new ConflictException('destination_ambiguous');
		}

		return $candidates[0];
	}

	/**
	 * Find the exact managed folder in the given view by identity, or `null` when
	 * it no longer exists (issue #54 folder cleanup).
	 *
	 * A single canonical `Folder::getById()` source is used; exactly one
	 * same-storage candidate is required. Zero candidates means the folder is
	 * already gone (`null`); more than one fails closed with
	 * `destination_ambiguous`. The folder is never recreated and its descriptor
	 * fields are never compared.
	 *
	 * @throws ConflictException|ValidationException
	 */
	public function findManagedFolder(string $viewUid, ?string $storageId, ?int $fileId): ?Folder {
		if ($fileId === null || $fileId <= 0 || $storageId === null || $storageId === '') {
			throw new ConflictException('destination_unavailable');
		}

		$view = $this->view($viewUid);
		try {
			$nodes = $view->getById($fileId);
		} catch (\Throwable $exception) {
			throw $this->mapFilesError($exception);
		}

		$candidates = [];
		foreach ($nodes as $node) {
			if ($node instanceof Folder && $this->storageIdOf($node) === $storageId) {
				$candidates[] = $node;
			}
		}

		if (count($candidates) === 0) {
			return null;
		}
		if (count($candidates) > 1) {
			throw new ConflictException('destination_ambiguous');
		}

		return $candidates[0];
	}

	/**
	 * Resolve a user-selected source file in the acting user's own Files view
	 * (issue #53).
	 *
	 * The client supplies only an advisory locator path; it is **never** trusted
	 * as proof of access or scope. The path is resolved in the acting user's own
	 * view, the node is re-verified by its authoritative identity
	 * `(storageId, fileId)` (exactly one same-storage candidate is required) and
	 * read permission is enforced. A path that is empty, missing, ambiguous,
	 * unreadable or not a file fails closed; nothing is copied.
	 *
	 * @throws ValidationException|ConflictException
	 */
	public function resolveSourceFile(string $viewUid, string $path): File {
		$view = $this->view($viewUid);
		$relative = ltrim(str_replace('\\', '/', $path), '/');
		if ($relative === '') {
			throw new ValidationException('attachment_source_invalid');
		}

		try {
			$node = $view->get($relative);
		} catch (FilesNotFoundException) {
			throw new ConflictException('attachment_source_missing');
		} catch (\Throwable $exception) {
			throw $this->translateSourceFilesError($exception);
		}

		if (!$node instanceof File) {
			throw new ValidationException('attachment_source_invalid');
		}

		$storageId = $this->storageIdOf($node);
		$fileId = (int)$node->getId();
		try {
			$nodes = $view->getById($fileId);
		} catch (\Throwable $exception) {
			throw $this->translateSourceFilesError($exception);
		}

		$candidates = [];
		foreach ($nodes as $candidate) {
			if ($candidate instanceof File && $this->storageIdOf($candidate) === $storageId) {
				$candidates[] = $candidate;
			}
		}

		if (count($candidates) === 0) {
			throw new ConflictException('attachment_source_missing');
		}
		if (count($candidates) > 1) {
			// Never pick a first match: an ambiguous identity fails closed.
			throw new ConflictException('attachment_source_ambiguous');
		}

		$file = $candidates[0];
		if (!$file->isReadable() || ($file->getPermissions() & Constants::PERMISSION_READ) === 0) {
			throw new ConflictException('attachment_source_no_access');
		}

		return $file;
	}

	/**
	 * Identity and descriptive metadata for a Files node (issue #50). The
	 * identity is `storageId` + `fileId`; the rest is audit/display metadata.
	 *
	 * @return array{storageId: string, fileId: int, storageRootId: int, mountType: string, mountProvider: string, mountId: int|null, numericStorageId: int|null, path: string}
	 */
	public function nodeDescriptor(Node $node): array {
		return $this->descriptor($node);
	}

	/**
	 * @throws ConflictException When the storage id cannot be read.
	 */
	private function storageIdOf(Node $node): string {
		try {
			return $node->getStorage()->getId();
		} catch (\Throwable $exception) {
			throw $this->mapFilesError($exception);
		}
	}

	/**
	 * @throws ValidationException|ConflictException
	 */
	private function ensureBaseFolder(Folder $view): Folder {
		$name = self::BASE_FOLDER_NAME;

		if ($view->nodeExists($name)) {
			$base = $view->get($name);
			if (!$base instanceof Folder) {
				throw new ValidationException('destination_invalid');
			}
		} else {
			try {
				$base = $view->newFolder($name);
			} catch (\Throwable $exception) {
				if ($exception instanceof AlreadyExistsException) {
					$base = $view->get($name);
					if (!$base instanceof Folder) {
						throw new ValidationException('destination_invalid');
					}
				} else {
					throw $this->mapFilesError($exception);
				}
			}
		}

		if (!$base instanceof Folder) {
			throw new ValidationException('destination_invalid');
		}
		if (!$base->isCreatable() || ($base->getPermissions() & Constants::PERMISSION_CREATE) === 0) {
			throw new ConflictException('destination_not_writable');
		}

		return $base;
	}

	/**
	 * Ensure the managed folder for one run exists and is owned by that run.
	 *
	 * A folder left by a previous attempt is reused **only** when its ownership
	 * marker is present, well-formed and carries this exact run UUID. Anything
	 * else (missing, malformed, unreadable or mismatched marker) fails closed
	 * with `destination_ownership_conflict`; the folder is never adopted or
	 * overwritten.
	 *
	 * @return array{0: Folder, 1: bool} The folder and whether it was created now.
	 *
	 * @throws ValidationException|ConflictException
	 */
	private function ensureRunFolder(Folder $base, string $name, string $runUuid): array {
		try {
			$base->verifyPath($name);
		} catch (\Throwable $exception) {
			if ($exception instanceof InvalidPathException) {
				throw new ValidationException('destination_invalid_name');
			}
			throw $this->mapFilesError($exception);
		}

		if ($base->nodeExists($name)) {
			return [$this->adoptOwnedFolder($base->get($name), $runUuid), false];
		}

		try {
			$folder = $base->newFolder($name);
		} catch (\Throwable $exception) {
			if ($exception instanceof AlreadyExistsException) {
				return [$this->adoptOwnedFolder($base->get($name), $runUuid), false];
			}
			throw $this->mapFilesError($exception);
		}

		try {
			$this->writeMarker($folder, $runUuid);
		} catch (\Throwable $exception) {
			// The folder itself is visible in Files, so it must never be
			// deleted recursively: only content this attempt provably wrote is
			// removed. The original (mapped) marker-write error is preserved.
			$this->cleanUpFailedMarkerWrite($folder, $runUuid);
			throw $this->mapFilesError($exception);
		}

		return [$folder, true];
	}

	/**
	 * Return an existing folder only when its marker proves it belongs to this
	 * run; otherwise fail closed without mutating it.
	 *
	 * @throws ValidationException|ConflictException
	 */
	private function adoptOwnedFolder(Node $node, string $runUuid): Folder {
		if (!$node instanceof Folder) {
			throw new ValidationException('destination_invalid');
		}
		if ($this->readMarkerUuid($node) !== $runUuid) {
			throw new ConflictException('destination_ownership_conflict');
		}

		return $node;
	}

	/**
	 * Read and validate the ownership marker; returns the recorded run UUID or
	 * null when the marker is missing, malformed, unreadable or of an unknown
	 * format/version.
	 */
	private function readMarkerUuid(Folder $folder): ?string {
		try {
			if (!$folder->nodeExists(self::MARKER_FILE_NAME)) {
				return null;
			}
			$node = $folder->get(self::MARKER_FILE_NAME);
		} catch (\Throwable) {
			return null;
		}
		if (!$node instanceof File) {
			return null;
		}

		return $this->parseMarkerUuid($node);
	}

	/**
	 * Parse and validate a marker file. Returns the recorded run UUID or null
	 * when the content is unreadable, malformed or of an unknown format/version.
	 */
	private function parseMarkerUuid(File $file): ?string {
		try {
			$raw = $file->getContent();
		} catch (\Throwable) {
			return null;
		}

		$data = json_decode($raw, true);
		if (!is_array($data)) {
			return null;
		}
		if (($data['format'] ?? null) !== self::MARKER_FORMAT) {
			return null;
		}
		if (($data['version'] ?? null) !== self::MARKER_VERSION) {
			return null;
		}
		$uuid = $data['uuid'] ?? null;
		if (!is_string($uuid) || $uuid === '') {
			return null;
		}

		return $uuid;
	}

	/**
	 * Write the ownership marker into a freshly created managed folder.
	 *
	 * @throws ConflictException When the marker already exists (never overwrite).
	 * @throws \JsonException When the payload cannot be encoded.
	 */
	private function writeMarker(Folder $folder, string $runUuid): void {
		if ($folder->nodeExists(self::MARKER_FILE_NAME)) {
			throw new ConflictException('destination_ownership_conflict');
		}
		$payload = json_encode([
			'format' => self::MARKER_FORMAT,
			'version' => self::MARKER_VERSION,
			'uuid' => $runUuid,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

		$folder->newFile(self::MARKER_FILE_NAME, $payload);
	}

	/**
	 * Cleanup after a failed ownership-marker write on a folder this attempt
	 * just created.
	 *
	 * The folder is visible in Nextcloud Files, so Runbook must **not** remove
	 * it: the public Files API only offers recursive `Folder::delete()`, which
	 * cannot be made atomic against a user adding a file after any emptiness
	 * check (issue #54). The folder is therefore preserved in every case; the
	 * only thing this attempt may remove is the **owned** marker file it
	 * provably wrote (a single, non-recursive file delete):
	 * - absent marker: nothing is listed or deleted;
	 * - the exact valid marker written by this attempt: only that marker file is
	 *   removed;
	 * - a partial/foreign/unreadable marker, a non-file marker, or any other
	 *   content: nothing is touched (fail closed).
	 *
	 * Cleanup failures are logged and never mask the original marker-write error.
	 */
	private function cleanUpFailedMarkerWrite(Folder $folder, string $runUuid): void {
		try {
			if (!$folder->nodeExists(self::MARKER_FILE_NAME)) {
				return;
			}

			$node = $folder->get(self::MARKER_FILE_NAME);
			if (!$node instanceof File) {
				return;
			}
			if ($this->parseMarkerUuid($node) !== $runUuid) {
				// Partial, foreign or user-modified marker: never touched.
				return;
			}

			// Single-file delete of our own marker only; the folder (and any
			// user content) is always preserved.
			$node->delete();
		} catch (\Throwable $exception) {
			$this->logger->warning('Runbook could not clean up after a failed ownership-marker write', [
				'app' => 'runbook',
				'fileId' => (int)$folder->getId(),
				'exception' => $exception,
			]);
		}
	}

	/**
	 * @return array{storageId: string, fileId: int, storageRootId: int, mountType: string, mountProvider: string, mountId: int|null, numericStorageId: int|null, path: string}
	 */
	private function descriptor(Node $node): array {
		try {
			$mount = $node->getMountPoint();

			return [
				'storageId' => $node->getStorage()->getId(),
				'fileId' => (int)$node->getId(),
				'storageRootId' => $mount->getStorageRootId(),
				'mountType' => $mount->getMountType(),
				'mountProvider' => $mount->getMountProvider(),
				'mountId' => $mount->getMountId(),
				'numericStorageId' => $mount->getNumericStorageId(),
				'path' => $node->getPath(),
			];
		} catch (\Throwable $exception) {
			throw $this->mapFilesError($exception);
		}
	}

	/**
	 * Map a Files/storage failure to a stable Runbook error; unknown throwables
	 * are returned unchanged so unexpected errors are not swallowed.
	 */
	private function mapFilesError(\Throwable $exception): \Throwable {
		return match (true) {
			$exception instanceof NotPermittedException,
			$exception instanceof FilesForbiddenException => new ConflictException('destination_not_writable'),
			$exception instanceof NotEnoughSpaceException => new ConflictException('destination_quota_exceeded'),
			$exception instanceof StorageNotAvailableException => new ConflictException('destination_unavailable'),
			$exception instanceof InvalidPathException => new ValidationException('destination_invalid_name'),
			default => $exception,
		};
	}

	/**
	 * Translate a Files/storage throwable into a stable Runbook error. Exposed
	 * for the attachment file storage (issue #50) so it shares one mapping.
	 */
	public function translateFilesError(\Throwable $exception): \Throwable {
		return $this->mapFilesError($exception);
	}

	/**
	 * Translate a Files/storage throwable that occurred while resolving or
	 * reading a **source** file (issue #53).
	 *
	 * A read/access denial must be reported as a source error, never as a
	 * destination error. `OCP\Files\ForbiddenException` is a sibling of
	 * `NotPermittedException` (both extend `\Exception`) and is **not** declared
	 * on `Folder::get()`, so a dedicated `catch` clause for it would be an
	 * unreachable dead catch; it is mapped here instead. Destination operations
	 * keep using {@see self::translateFilesError()} unchanged.
	 */
	public function translateSourceFilesError(\Throwable $exception): \Throwable {
		if ($exception instanceof NotPermittedException || $exception instanceof FilesForbiddenException) {
			return new ConflictException('attachment_source_no_access');
		}

		return $this->mapFilesError($exception);
	}

	/**
	 * Deterministic managed-folder display name: `<sanitized title> (<run uuid>)`.
	 *
	 * The **full** run UUID is embedded verbatim as the collision discriminator.
	 * It is stable for a given run, so a retry resolves the exact folder created
	 * for that run, and it is unique per run, so two runs can never derive the
	 * same name even when their titles and UUID prefixes match. The
	 * discriminator is never truncated by the length bound; only the readable
	 * title is trimmed to respect the 255-character Files filename limit.
	 */
	public function folderName(string $title, string $runUuid): string {
		$name = str_replace(['/', '\\'], '-', $title);
		$name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
		$name = preg_replace('/\s+/u', ' ', $name) ?? '';
		$name = trim($name);
		if ($name === '' || $name === '.' || $name === '..' || preg_match('/^[.\-\s]+$/', $name) === 1) {
			$name = self::FALLBACK_FOLDER_NAME;
		}

		$discriminator = strtolower((string)preg_replace('/[^0-9a-fA-F-]/', '', $runUuid));
		if ($discriminator === '') {
			$discriminator = self::FALLBACK_FOLDER_NAME;
		}

		$suffix = ' (' . $discriminator . ')';
		$maxNameLength = self::MAX_FOLDER_NAME_LENGTH - mb_strlen($suffix);
		if ($maxNameLength < 1) {
			$maxNameLength = 1;
		}
		if (mb_strlen($name) > $maxNameLength) {
			$name = trim(mb_substr($name, 0, $maxNameLength));
		}
		if ($name === '') {
			$name = self::FALLBACK_FOLDER_NAME;
		}

		return $name . $suffix;
	}

	/**
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 *
	 * @throws ConflictException When the lock cannot be acquired in time.
	 */
	private function withLock(string $key, callable $operation): mixed {
		$acquired = false;
		for ($attempt = 0; $attempt < self::LOCK_MAX_ATTEMPTS; $attempt++) {
			try {
				$this->locking->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
				$acquired = true;
				break;
			} catch (LockedException) {
				if ($attempt < self::LOCK_MAX_ATTEMPTS - 1) {
					usleep(self::LOCK_RETRY_MICROSECONDS);
				}
			}
		}

		if (!$acquired) {
			throw new ConflictException('destination_locked');
		}

		try {
			return $operation();
		} finally {
			$this->locking->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
