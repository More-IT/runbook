<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Access control entry granting a role to a user or group principal.
 *
 * @method int getTemplateId()
 * @method void setTemplateId(int $templateId)
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
 * @psalm-type AclData array{
 *     id: int,
 *     templateId: int,
 *     principalType: string,
 *     principalId: string,
 *     role: string,
 *     createdAt: int,
 *     updatedAt: int
 * }
 */
class TemplateAcl extends Entity {
	protected int $templateId = 0;
	protected string $principalType = '';
	protected string $principalId = '';
	protected string $role = '';
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('templateId', Types::BIGINT);
		$this->addType('principalType', Types::STRING);
		$this->addType('principalId', Types::STRING);
		$this->addType('role', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}

	/**
	 * @return AclData
	 */
	public function toArray(): array {
		return [
			'id' => (int)$this->id,
			'templateId' => $this->templateId,
			'principalType' => $this->principalType,
			'principalId' => $this->principalId,
			'role' => $this->role,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}
}
