<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A plain-text comment attached to a run or to a run step.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method int getRunId()
 * @method void setRunId(int $runId)
 * @method int|null getStepId()
 * @method void setStepId(?int $stepId)
 * @method string getAuthorUid()
 * @method void setAuthorUid(string $authorUid)
 * @method string getBody()
 * @method void setBody(string $body)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 *
 * @psalm-type CommentData array{
 *     id: int,
 *     uuid: string,
 *     runId: int,
 *     stepId: int|null,
 *     authorUid: string,
 *     body: string,
 *     createdAt: int,
 *     updatedAt: int
 * }
 */
class Comment extends Entity {
	protected string $uuid = '';
	protected int $runId = 0;
	protected ?int $stepId = null;
	protected string $authorUid = '';
	protected string $body = '';
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('uuid', Types::STRING);
		$this->addType('runId', Types::BIGINT);
		$this->addType('stepId', Types::BIGINT);
		$this->addType('authorUid', Types::STRING);
		$this->addType('body', Types::TEXT);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}

	/**
	 * @return CommentData
	 */
	public function toArray(): array {
		return [
			'id' => (int)$this->id,
			'uuid' => $this->uuid,
			'runId' => $this->runId,
			'stepId' => $this->stepId,
			'authorUid' => $this->authorUid,
			'body' => $this->body,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}
}
