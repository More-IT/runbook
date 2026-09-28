<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Evidence attachment metadata.
 *
 * The binary content is stored in AppData under an application-controlled
 * path. The storage_key is an internal random identifier and is never exposed
 * through the API.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method int getRunId()
 * @method void setRunId(int $runId)
 * @method int getStepId()
 * @method void setStepId(int $stepId)
 * @method string getUploaderUid()
 * @method void setUploaderUid(string $uploaderUid)
 * @method string getFilename()
 * @method void setFilename(string $filename)
 * @method string getStorageKey()
 * @method string getStorageKind()
 * @method void setStorageKind(string $storageKind)
 * @method int|null getFileId()
 * @method void setFileId(?int $fileId)
 * @method string|null getStorageId()
 * @method void setStorageId(?string $storageId)
 * @method int|null getStorageRootId()
 * @method void setStorageRootId(?int $storageRootId)
 * @method string|null getMountType()
 * @method void setMountType(?string $mountType)
 * @method string|null getMountProvider()
 * @method void setMountProvider(?string $mountProvider)
 * @method int|null getMountId()
 * @method void setMountId(?int $mountId)
 * @method int|null getNumericStorageId()
 * @method void setNumericStorageId(?int $numericStorageId)
 * @method string|null getPath()
 * @method void setPath(?string $path)
 * @method string getMimeType()
 * @method void setMimeType(string $mimeType)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method string getChecksum()
 * @method void setChecksum(string $checksum)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method string|null getMigrationState()
 * @method void setMigrationState(?string $migrationState)
 * @method string|null getMigrationReason()
 * @method void setMigrationReason(?string $migrationReason)
 *
 * @phpstan-type AttachmentData array{
 *     id: int,
 *     uuid: string,
 *     runId: int,
 *     stepId: int,
 *     uploaderUid: string,
 *     filename: string,
 *     mimeType: string,
 *     size: int,
 *     checksum: string,
 *     createdAt: int
 * }
 *
 * @phpstan-type AttachmentDataWithState array{
 *     id: int,
 *     uuid: string,
 *     runId: int,
 *     stepId: int,
 *     uploaderUid: string,
 *     filename: string,
 *     mimeType: string,
 *     size: int,
 *     checksum: string,
 *     createdAt: int,
 *     fileState: string
 * }
 */
class Attachment extends Entity {
	public const STORAGE_KIND_APPDATA = 'appdata';
	public const STORAGE_KIND_FILES = 'files';

	protected string $uuid = '';
	protected int $runId = 0;
	protected int $stepId = 0;
	protected string $uploaderUid = '';
	protected string $filename = '';
	protected string $storageKey = '';
	protected string $storageKind = self::STORAGE_KIND_APPDATA;
	protected ?int $fileId = null;
	protected ?string $storageId = null;
	protected ?int $storageRootId = null;
	protected ?string $mountType = null;
	protected ?string $mountProvider = null;
	protected ?int $mountId = null;
	protected ?int $numericStorageId = null;
	protected ?string $path = null;
	protected string $mimeType = '';
	protected int $size = 0;
	protected string $checksum = '';
	protected int $createdAt = 0;
	protected ?string $migrationState = null;
	protected ?string $migrationReason = null;

	public function __construct() {
		$this->addType('uuid', Types::STRING);
		$this->addType('runId', Types::BIGINT);
		$this->addType('stepId', Types::BIGINT);
		$this->addType('uploaderUid', Types::STRING);
		$this->addType('filename', Types::STRING);
		$this->addType('storageKey', Types::STRING);
		$this->addType('storageKind', Types::STRING);
		$this->addType('fileId', Types::BIGINT);
		$this->addType('storageId', Types::TEXT);
		$this->addType('storageRootId', Types::BIGINT);
		$this->addType('mountType', Types::TEXT);
		$this->addType('mountProvider', Types::TEXT);
		$this->addType('mountId', Types::INTEGER);
		$this->addType('numericStorageId', Types::INTEGER);
		$this->addType('path', Types::TEXT);
		$this->addType('mimeType', Types::STRING);
		$this->addType('size', Types::BIGINT);
		$this->addType('checksum', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('migrationState', Types::STRING);
		$this->addType('migrationReason', Types::STRING);
	}

	/**
	 * Explicit setter so the NOT NULL `storage_key` column is always persisted,
	 * including the empty string used by Files-backed rows (the legacy key is
	 * only meaningful for AppData rows until #55 removes it).
	 */
	public function setStorageKey(string $storageKey): void {
		$this->storageKey = $storageKey;
		$this->markFieldUpdated('storageKey');
	}

	/**
	 * Safe serialization used by the API. The storage key and any storage path
	 * are intentionally excluded.
	 *
	 * @return AttachmentData
	 */
	public function toArray(): array {
		return [
			'id' => (int)$this->id,
			'uuid' => $this->uuid,
			'runId' => $this->runId,
			'stepId' => $this->stepId,
			'uploaderUid' => $this->uploaderUid,
			'filename' => $this->filename,
			'mimeType' => $this->mimeType,
			'size' => $this->size,
			'checksum' => $this->checksum,
			'createdAt' => $this->createdAt,
		];
	}

	/**
	 * Safe serialization plus the reconciled `file_state` (issue #52). Identity
	 * (storage/file id), storage key and paths are still never exposed.
	 *
	 * @param string $fileState One of present|missing|out_of_scope|unavailable.
	 * @return AttachmentDataWithState
	 */
	public function toArrayWithState(string $fileState): array {
		return $this->toArray() + ['fileState' => $fileState];
	}
}
