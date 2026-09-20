<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A record that a notification has already been delivered.
 *
 * The ledger is the persistent deduplication source. A stable dedupe key
 * encodes the notification type, the relevant object and the recipient so that
 * repeated triggers never deliver the same notification twice.
 *
 * A delivery moves through {@see self::STATUS_PENDING} → {@see self::STATUS_SENT}
 * on success and {@see self::STATUS_PENDING} → {@see self::STATUS_FAILED} on a
 * transient failure. Only `sent` rows suppress future attempts; `failed` rows
 * remain retryable until {@see self::MAX_ATTEMPTS} is reached.
 *
 * @method string getDedupeKey()
 * @method void setDedupeKey(string $dedupeKey)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getUserUid()
 * @method void setUserUid(string $userUid)
 * @method int getRunId()
 * @method void setRunId(int $runId)
 * @method int|null getStepId()
 * @method void setStepId(?int $stepId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class NotificationDelivery extends Entity {
	public const STATUS_PENDING = 'pending';
	public const STATUS_SENT = 'sent';
	public const STATUS_FAILED = 'failed';

	/**
	 * Upper bound on delivery attempts for transient failures. Once reached the
	 * row is treated as terminal and never retried, which bounds duplicates.
	 */
	public const MAX_ATTEMPTS = 5;

	protected string $dedupeKey = '';
	protected string $type = '';
	protected string $userUid = '';
	protected int $runId = 0;
	protected ?int $stepId = null;
	protected string $status = self::STATUS_PENDING;
	protected int $attempts = 0;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('dedupeKey', Types::STRING);
		$this->addType('type', Types::STRING);
		$this->addType('userUid', Types::STRING);
		$this->addType('runId', Types::BIGINT);
		$this->addType('stepId', Types::BIGINT);
		$this->addType('status', Types::STRING);
		$this->addType('attempts', Types::INTEGER);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}
}
