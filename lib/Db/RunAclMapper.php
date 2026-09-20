<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCA\Runbook\Enum\PrincipalType;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<RunAcl>
 */
class RunAclMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'runbook_run_acl', RunAcl::class);
	}

	public function find(int $id): RunAcl {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		/** @var RunAcl $entry */
		$entry = $this->findEntity($qb);

		return $entry;
	}

	/**
	 * @return list<RunAcl>
	 */
	public function findByRun(int $runId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId, IQueryBuilder::PARAM_INT)))
			->orderBy('principal_type', 'ASC')
			->addOrderBy('principal_id', 'ASC')
			->addOrderBy('id', 'ASC');

		/** @var list<RunAcl> $entries */
		$entries = $this->findEntities($qb);

		return $entries;
	}

	public function deleteByRun(int $runId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Run ids the given user can reach through a direct or group entry.
	 *
	 * @param list<string> $groupIds
	 * @return list<int>
	 */
	public function findRunIdsForPrincipal(string $uid, array $groupIds): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('run_id')
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
		$runIds = [];
		while (($row = $result->fetch()) !== false) {
			$runIds[] = (int)$row['run_id'];
		}
		$result->closeCursor();

		return $runIds;
	}
}
