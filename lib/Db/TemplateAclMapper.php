<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCA\Runbook\Enum\PrincipalType;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<TemplateAcl>
 */
class TemplateAclMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'runbook_template_acl', TemplateAcl::class);
	}

	public function find(int $id): TemplateAcl {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		/** @var TemplateAcl $entry */
		$entry = $this->findEntity($qb);

		return $entry;
	}

	/**
	 * @return list<TemplateAcl>
	 */
	public function findByTemplate(int $templateId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId, IQueryBuilder::PARAM_INT)))
			->orderBy('principal_type', 'ASC')
			->addOrderBy('principal_id', 'ASC')
			->addOrderBy('id', 'ASC');

		/** @var list<TemplateAcl> $entries */
		$entries = $this->findEntities($qb);

		return $entries;
	}

	public function deleteByTemplate(int $templateId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Template ids the given user can reach through a direct or group entry.
	 *
	 * @param list<string> $groupIds
	 * @return list<int>
	 */
	public function findTemplateIdsForPrincipal(string $uid, array $groupIds): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('template_id')
			->from($this->tableName);

		$condition = $qb->expr()->orX(
			$qb->expr()->andX(
				$qb->expr()->eq('principal_type', $qb->createNamedParameter(PrincipalType::User->value)),
				$qb->expr()->eq('principal_id', $qb->createNamedParameter($uid)),
			),
		);

		if ($groupIds !== []) {
			$condition->add(
				$qb->expr()->andX(
					$qb->expr()->eq('principal_type', $qb->createNamedParameter(PrincipalType::Group->value)),
					$qb->expr()->in('principal_id', $qb->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY)),
				),
			);
		}

		$qb->where($condition);

		$result = $qb->executeQuery();
		$templateIds = [];
		while (($row = $result->fetch()) !== false) {
			$templateIds[] = (int)$row['template_id'];
		}
		$result->closeCursor();

		return $templateIds;
	}
}
