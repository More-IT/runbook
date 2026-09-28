<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A durable record that a managed run folder still has to be removed after its
 * run rows are gone (issue #54, docs/folder-model.md §8.2).
 *
 * The row is written in the **same transaction** as the run-row deletion, so the
 * intent is durable before the run disappears. The identity
 * `(view_uid, storage_id, file_id)` is authoritative; the descriptor fields are
 * audit/display metadata only (§2.1.1). `status` is `pending` while a retry may
 * still remove the folder, `blocked` when human action is required (for example
 * untracked content), and `done` once it has been removed. The row is normally
 * deleted on success, so `done` is only a defensive terminal state.
 *
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getViewUid()
 * @method void setViewUid(string $viewUid)
 * @method string getStorageId()
 * @method void setStorageId(string $storageId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
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
 * @method string|null getReason()
 * @method void setReason(?string $reason)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method int|null getLastAttemptAt()
 * @method void setLastAttemptAt(?int $lastAttemptAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class FilesCleanup extends Entity {
	public const KIND_FOLDER = 'folder';

	public const STATUS_PENDING = 'pending';
	public const STATUS_DONE = 'done';
	public const STATUS_BLOCKED = 'blocked';

	/** Untracked content remains inside the managed folder. */
	public const REASON_NOT_EMPTY = 'not_empty';
	/**
	 * The folder still exists (marker-only or empty) but the public Files API
	 * only offers recursive folder deletion, which cannot be made safe against a
	 * concurrent user write. Runbook preserves the folder instead.
	 */
	public const REASON_REMOVAL_UNSUPPORTED = 'removal_unsupported';
	/** The mount/storage could not be resolved (retryable). */
	public const REASON_UNAVAILABLE = 'unavailable';
	/** The folder identity resolved ambiguously (never act). */
	public const REASON_AMBIGUOUS = 'ambiguous';
	/** An unexpected failure (retryable). */
	public const REASON_ERROR = 'error';

	protected string $kind = self::KIND_FOLDER;
	protected string $status = self::STATUS_PENDING;
	protected string $viewUid = '';
	protected string $storageId = '';
	protected int $fileId = 0;
	protected ?int $storageRootId = null;
	protected ?string $mountType = null;
	protected ?string $mountProvider = null;
	protected ?int $mountId = null;
	protected ?int $numericStorageId = null;
	protected ?string $path = null;
	protected ?string $reason = null;
	protected int $attempts = 0;
	protected ?int $lastAttemptAt = null;
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('kind', Types::STRING);
		$this->addType('status', Types::STRING);
		$this->addType('viewUid', Types::STRING);
		$this->addType('storageId', Types::TEXT);
		$this->addType('fileId', Types::BIGINT);
		$this->addType('storageRootId', Types::BIGINT);
		$this->addType('mountType', Types::TEXT);
		$this->addType('mountProvider', Types::TEXT);
		$this->addType('mountId', Types::INTEGER);
		$this->addType('numericStorageId', Types::INTEGER);
		$this->addType('path', Types::TEXT);
		$this->addType('reason', Types::STRING);
		$this->addType('attempts', Types::INTEGER);
		$this->addType('lastAttemptAt', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
	}
}
