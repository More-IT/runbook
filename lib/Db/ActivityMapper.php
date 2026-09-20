<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ActivityEvent>
 */
class ActivityMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'runbook_activity', ActivityEvent::class);
	}

	public function find(int $id): ActivityEvent {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		/** @var ActivityEvent $event */
		$event = $this->findEntity($qb);

		return $event;
	}

	/**
	 * Activity of a run. Newest first when $descending is true.
	 *
	 * @return list<ActivityEvent>
	 */
	public function findByRun(int $runId, int $limit, bool $descending = true): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', $descending ? 'DESC' : 'ASC')
			->addOrderBy('id', $descending ? 'DESC' : 'ASC')
			->setMaxResults($limit);

		/** @var list<ActivityEvent> $events */
		$events = $this->findEntities($qb);

		return $events;
	}
}
