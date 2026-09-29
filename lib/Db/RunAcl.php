<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Access control entry granting a participant or viewer role on a run.
 *
 * @method int getRunId()
 * @method void setRunId(int $runId)
 * @method string getPrincipalType()
 * @method void setPrincipalType(string $principalType)
 * @method string getPrincipalId()
 * @method void setPrincipalId(string $principalId)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 *
 * @psalm-type RunAclData array{
 *     id: int,
 *     runId: int,
 *     principalType: string,
 *     principalId: string,
 *     role: string,
 *     createdAt: int,
 *     updatedAt: int
 * }
 */
class RunAcl extends Entity {
	protected int $runId = 0;
	protected string $principalType = '';
	protected string $principalId = '';
	protected string $role = '';
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('runId', Types::BIGINT);
		$this->addType('principalType', Types::STRING);
		$this->addType('principalId', Types::STRING);
		$this->addType('role', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}

	/**
	 * @return RunAclData
	 */
	public function toArray(): array {
		return [
			'id' => (int)$this->id,
			'runId' => $this->runId,
			'principalType' => $this->principalType,
			'principalId' => $this->principalId,
			'role' => $this->role,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}
}
