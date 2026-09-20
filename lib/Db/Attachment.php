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
 * @method void setStorageKey(string $storageKey)
 * @method string getMimeType()
 * @method void setMimeType(string $mimeType)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method string getChecksum()
 * @method void setChecksum(string $checksum)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
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
 */
class Attachment extends Entity {
	protected string $uuid = '';
	protected int $runId = 0;
	protected int $stepId = 0;
	protected string $uploaderUid = '';
	protected string $filename = '';
	protected string $storageKey = '';
	protected string $mimeType = '';
	protected int $size = 0;
	protected string $checksum = '';
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('uuid', Types::STRING);
		$this->addType('runId', Types::BIGINT);
		$this->addType('stepId', Types::BIGINT);
		$this->addType('uploaderUid', Types::STRING);
		$this->addType('filename', Types::STRING);
		$this->addType('storageKey', Types::STRING);
		$this->addType('mimeType', Types::STRING);
		$this->addType('size', Types::BIGINT);
		$this->addType('checksum', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
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
}
