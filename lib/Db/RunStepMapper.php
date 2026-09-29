<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<RunStep>
 */
class RunStepMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'runbook_run_steps', RunStep::class);
	}

	public function find(int $id): RunStep {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$step = $this->findEntity($qb);

		return $step;
	}

	/**
	 * @return list<RunStep>
	 */
	public function findBySection(int $runSectionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('run_section_id', $qb->createNamedParameter($runSectionId, IQueryBuilder::PARAM_INT)))
			->orderBy('position', 'ASC')
			->addOrderBy('id', 'ASC');

		$steps = $this->findEntities($qb);

		return $steps;
	}

	/**
	 * Steps belonging to the given run sections.
	 *
	 * @param list<int> $runSectionIds
	 * @return list<RunStep>
	 */
	public function findBySections(array $runSectionIds): array {
		if ($runSectionIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->in(
				'run_section_id',
				$qb->createNamedParameter($runSectionIds, IQueryBuilder::PARAM_INT_ARRAY),
			))
			->orderBy('position', 'ASC')
			->addOrderBy('id', 'ASC');

		$steps = $this->findEntities($qb);

		return $steps;
	}

	/**
	 * All steps of a run, joined through its sections.
	 *
	 * @return list<RunStep>
	 */
	public function findByRun(int $runId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.*')
			->from($this->tableName, 's')
			->innerJoin('s', 'runbook_run_sections', 'sec', $qb->expr()->eq('s.run_section_id', 'sec.id'))
			->where($qb->expr()->eq('sec.run_id', $qb->createNamedParameter($runId, IQueryBuilder::PARAM_INT)))
			->orderBy('s.position', 'ASC')
			->addOrderBy('s.id', 'ASC');

		$steps = $this->findEntities($qb);

		return $steps;
	}

	/**
	 * Steps assigned to the given user or one of the given groups.
	 *
	 * @param list<string> $groupIds
	 * @return list<RunStep>
	 */
	public function findAssignedTo(string $uid, array $groupIds, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($this->assigneeCondition($qb, $uid, $groupIds))
			->orderBy('due_at', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults($limit);

		$steps = $this->findEntities($qb);

		return $steps;
	}

	/**
	 * Steps of a single run assigned to the given user or one of the groups.
	 *
	 * @param list<string> $groupIds
	 * @return list<RunStep>
	 */
	public function findAssignedInRun(int $runId, string $uid, array $groupIds): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.*')
			->from($this->tableName, 's')
			->innerJoin('s', 'runbook_run_sections', 'sec', $qb->expr()->eq('s.run_section_id', 'sec.id'))
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('sec.run_id', $qb->createNamedParameter($runId, IQueryBuilder::PARAM_INT)),
					$this->assigneeCondition($qb, $uid, $groupIds),
				),
			)
			->setMaxResults(1);

		$steps = $this->findEntities($qb);

		return $steps;
	}

	/**
	 * Run ids that contain a step assigned to the given user or one of the groups.
	 *
	 * @param list<string> $groupIds
	 * @return list<int>
	 */
	public function findDistinctRunIdsForPrincipal(string $uid, array $groupIds): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('sec.run_id')
			->from($this->tableName, 's')
			->innerJoin('s', 'runbook_run_sections', 'sec', $qb->expr()->eq('s.run_section_id', 'sec.id'))
			->where($this->assigneeCondition($qb, $uid, $groupIds));

		$result = $qb->executeQuery();
		$runIds = [];
		while (($row = $result->fetch()) !== false) {
			$runIds[] = (int)$row['run_id'];
		}
		$result->closeCursor();

		return $runIds;
	}

	/**
	 * Count of active assigned steps across accessible active runs.
	 *
	 * @param list<string> $groupIds
	 */
	public function countAssignedActive(string $uid, array $groupIds): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->tableName, 's')
			->innerJoin('s', 'runbook_run_sections', 'sec', $qb->expr()->eq('s.run_section_id', 'sec.id'))
			->innerJoin('sec', 'runbook_runs', 'r', $qb->expr()->eq('sec.run_id', 'r.id'))
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('r.status', $qb->createNamedParameter(RunStatus::Active->value)),
					$qb->expr()->in(
						's.status',
						$qb->createNamedParameter(
							[RunStepStatus::Pending->value, RunStepStatus::InProgress->value],
							IQueryBuilder::PARAM_STR_ARRAY,
						),
					),
					$this->assigneeCondition($qb, $uid, $groupIds),
				),
			);

		$result = $qb->executeQuery();
		$count = $result->fetchOne();
		$result->closeCursor();

		return (int)$count;
	}

	/**
	 * Count of assigned active steps whose due date has passed.
	 *
	 * @param list<string> $groupIds
	 */
	public function countAssignedOverdue(string $uid, array $groupIds, int $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->tableName, 's')
			->innerJoin('s', 'runbook_run_sections', 'sec', $qb->expr()->eq('s.run_section_id', 'sec.id'))
			->innerJoin('sec', 'runbook_runs', 'r', $qb->expr()->eq('sec.run_id', 'r.id'))
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('r.status', $qb->createNamedParameter(RunStatus::Active->value)),
					$qb->expr()->in(
						's.status',
						$qb->createNamedParameter(
							[RunStepStatus::Pending->value, RunStepStatus::InProgress->value],
							IQueryBuilder::PARAM_STR_ARRAY,
						),
					),
					$qb->expr()->isNotNull('s.due_at'),
					$qb->expr()->lt('s.due_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)),
					$this->assigneeCondition($qb, $uid, $groupIds),
				),
			);

		$result = $qb->executeQuery();
		$count = $result->fetchOne();
		$result->closeCursor();

		return (int)$count;
	}

	/**
	 * Count of assigned steps completed since the given timestamp.
	 *
	 * @param list<string> $groupIds
	 */
	public function countAssignedCompletedSince(string $uid, array $groupIds, int $from): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->tableName, 's')
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('s.status', $qb->createNamedParameter(RunStepStatus::Completed->value)),
					$qb->expr()->gte('s.completed_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)),
					$this->assigneeCondition($qb, $uid, $groupIds),
				),
			);

		$result = $qb->executeQuery();
		$count = $result->fetchOne();
		$result->closeCursor();

		return (int)$count;
	}

	/**
	 * Active, unresolved, assigned steps whose due date falls within a global
	 * time window.
	 *
	 * The window is a bounded superset of every recipient's local "tomorrow";
	 * the exact per-recipient boundary is applied by the notification service,
	 * which is why the delivery ledger is intentionally not joined here.
	 *
	 * @return list<RunStep>
	 */
	public function findAssignedStepsDueBetween(int $from, int $to, int $limit): array {
		$qb = $this->scheduledStepsQuery($limit);
		$qb->andWhere($qb->expr()->gte('s.due_at', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('s.due_at', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)))
			->orderBy('s.due_at', 'ASC')
			->addOrderBy('s.id', 'ASC');

		$steps = $this->findEntities($qb);

		return $steps;
	}

	/**
	 * Active, unresolved, assigned steps that became overdue within a bounded
	 * lookback window.
	 *
	 * @return list<RunStep>
	 */
	public function findAssignedStepsOverdue(int $after, int $before, int $limit): array {
		$qb = $this->scheduledStepsQuery($limit);
		$qb->andWhere($qb->expr()->gt('s.due_at', $qb->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('s.due_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)))
			->orderBy('s.due_at', 'DESC')
			->addOrderBy('s.id', 'DESC');

		$steps = $this->findEntities($qb);

		return $steps;
	}

	/**
	 * Bounded query for assigned, unresolved steps of active runs with a due
	 * date.
	 */
	private function scheduledStepsQuery(int $limit): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.*')
			->from($this->tableName, 's')
			->innerJoin('s', 'runbook_run_sections', 'sec', $qb->expr()->eq('s.run_section_id', 'sec.id'))
			->innerJoin('sec', 'runbook_runs', 'r', $qb->expr()->eq('sec.run_id', 'r.id'))
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('r.status', $qb->createNamedParameter(RunStatus::Active->value)),
					$qb->expr()->in(
						's.status',
						$qb->createNamedParameter(
							[RunStepStatus::Pending->value, RunStepStatus::InProgress->value],
							IQueryBuilder::PARAM_STR_ARRAY,
						),
					),
					$qb->expr()->isNotNull('s.assignee_type'),
					$qb->expr()->isNotNull('s.assignee_id'),
					$qb->expr()->isNotNull('s.due_at'),
				),
			)
			->setMaxResults($limit);

		return $qb;
	}

	/**
	 * @param list<string> $groupIds
	 */
	private function assigneeCondition(IQueryBuilder $qb, string $uid, array $groupIds): ICompositeExpression {
		$condition = $qb->expr()->orX(
			$qb->expr()->andX(
				$qb->expr()->eq('assignee_type', $qb->createNamedParameter(PrincipalType::User->value)),
				$qb->expr()->eq('assignee_id', $qb->createNamedParameter($uid)),
			),
		);

		if ($groupIds !== []) {
			$condition->add(
				$qb->expr()->andX(
					$qb->expr()->eq('assignee_type', $qb->createNamedParameter(PrincipalType::Group->value)),
					$qb->expr()->in('assignee_id', $qb->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY)),
				),
			);
		}

		return $condition;
	}
}
