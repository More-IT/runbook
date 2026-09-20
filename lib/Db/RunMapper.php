<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCA\Runbook\Enum\RunStatus;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Run>
 */
class RunMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'runbook_runs', Run::class);
	}

	public function find(int $id): Run {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		/** @var Run $run */
		$run = $this->findEntity($qb);

		return $run;
	}

	/**
	 * Runs owned by the given user, most recently updated first.
	 *
	 * @return list<Run>
	 */
	public function findByOwner(string $owner): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('owner', $qb->createNamedParameter($owner)))
			->orderBy('updated_at', 'DESC')
			->addOrderBy('id', 'DESC');

		/** @var list<Run> $runs */
		$runs = $this->findEntities($qb);

		return $runs;
	}

	/**
	 * Runs accessible to a user: owned runs plus runs reached through a run
	 * ACL entry or a step assignment.
	 *
	 * @param list<int> $runIds Run ids granted through ACL or assignment.
	 * @return list<Run>
	 */
	public function findAccessible(string $owner, array $runIds, ?int $limit = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName);

		$this->applyAccessCondition($qb, $owner, $runIds);

		$qb->orderBy('updated_at', 'DESC')
			->addOrderBy('id', 'DESC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		/** @var list<Run> $runs */
		$runs = $this->findEntities($qb);

		return $runs;
	}

	/**
	 * Count accessible runs, optionally filtered by status.
	 *
	 * @param list<int> $runIds
	 */
	public function countAccessible(string $owner, array $runIds, ?string $status = null): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->tableName);

		$this->applyAccessCondition($qb, $owner, $runIds);

		if ($status !== null) {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}

		$result = $qb->executeQuery();
		$count = $result->fetchOne();
		$result->closeCursor();

		return (int)$count;
	}

	/**
	 * Count accessible completed runs completed since the given timestamp.
	 *
	 * @param list<int> $runIds
	 */
	public function countAccessibleCompletedSince(string $owner, array $runIds, int $from): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->tableName);

		$this->applyAccessCondition($qb, $owner, $runIds);

		$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(RunStatus::Completed->value)))
			->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$count = $result->fetchOne();
		$result->closeCursor();

		return (int)$count;
	}

	/**
	 * Accessible runs whose title or description matches the given
	 * case-insensitive term.
	 *
	 * @param list<int> $runIds Run ids granted through ACL or assignment.
	 * @return list<Run>
	 */
	public function searchAccessible(string $owner, array $runIds, string $term, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName);

		$this->applyAccessCondition($qb, $owner, $runIds);

		$pattern = $qb->createNamedParameter('%' . $term . '%');
		$qb->andWhere(
			$qb->expr()->orX(
				$qb->expr()->iLike('title', $pattern),
				$qb->expr()->iLike('description', $pattern),
			),
		);
		$qb->orderBy('updated_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);

		/** @var list<Run> $runs */
		$runs = $this->findEntities($qb);

		return $runs;
	}

	/**
	 * @param list<int> $runIds
	 */
	private function applyAccessCondition(IQueryBuilder $qb, string $owner, array $runIds): void {
		$ownerCondition = $qb->expr()->eq('owner', $qb->createNamedParameter($owner));
		if ($runIds === []) {
			$qb->where($ownerCondition);
		} else {
			$qb->where(
				$qb->expr()->orX(
					$ownerCondition,
					$qb->expr()->in('id', $qb->createNamedParameter($runIds, IQueryBuilder::PARAM_INT_ARRAY)),
				),
			);
		}
	}

	/**
	 * @param list<int> $ids
	 * @return list<Run>
	 */
	public function findByIds(array $ids): array {
		if ($ids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		/** @var list<Run> $runs */
		$runs = $this->findEntities($qb);

		return $runs;
	}
}
