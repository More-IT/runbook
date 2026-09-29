<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Attachment>
 */
class AttachmentMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'runbook_attachments', Attachment::class);
	}

	public function find(int $id): Attachment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$attachment = $this->findEntity($qb);

		return $attachment;
	}

	/**
	 * @return list<Attachment>
	 */
	public function findByRun(int $runId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');

		$attachments = $this->findEntities($qb);

		return $attachments;
	}

	/**
	 * @return list<Attachment>
	 */
	public function findByStep(int $stepId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('step_id', $qb->createNamedParameter($stepId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');

		$attachments = $this->findEntities($qb);

		return $attachments;
	}

	/**
	 * Count persisted evidence attached to one exact step of one run.
	 *
	 * Only committed metadata rows are counted, so a failed or in-progress
	 * upload can never satisfy a required FILE step.
	 */
	public function countByRunAndStep(int $runId, int $stepId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->tableName)
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('step_id', $qb->createNamedParameter($stepId, IQueryBuilder::PARAM_INT)));

		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Distinct run ids that still have an attachment of the given storage kind,
	 * oldest run first (issue #55 migration enumeration).
	 *
	 * @return list<int>
	 */
	public function findRunIdsByStorageKind(string $storageKind, int $limit = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('run_id')
			->from($this->tableName)
			->where($qb->expr()->eq('storage_kind', $qb->createNamedParameter($storageKind)))
			->orderBy('run_id', 'ASC');
		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}

		$result = $qb->executeQuery();
		$ids = [];
		/** @psalm-suppress MixedAssignment DB scalar result is normalized to int below. */
		while (($value = $result->fetchOne()) !== false) {
			$ids[] = (int)$value;
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * Number of attachments of the given storage kind (issue #55 progress).
	 */
	public function countByStorageKind(string $storageKind): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->tableName)
			->where($qb->expr()->eq('storage_kind', $qb->createNamedParameter($storageKind)));

		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Distinct run ids with an attachment carrying the given migration reason
	 * (issue #55 residual AppData cleanup), oldest run first.
	 *
	 * @return list<int>
	 */
	public function findRunIdsByMigrationReason(string $reason): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('run_id')
			->from($this->tableName)
			->where($qb->expr()->eq('migration_reason', $qb->createNamedParameter($reason)))
			->orderBy('run_id', 'ASC');

		$result = $qb->executeQuery();
		$ids = [];
		/** @psalm-suppress MixedAssignment DB scalar result is normalized to int below. */
		while (($value = $result->fetchOne()) !== false) {
			$ids[] = (int)$value;
		}
		$result->closeCursor();

		return $ids;
	}

	/**
	 * Number of attachments carrying the given migration reason (issue #55).
	 */
	public function countByMigrationReason(string $reason): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->tableName)
			->where($qb->expr()->eq('migration_reason', $qb->createNamedParameter($reason)));

		return (int)$qb->executeQuery()->fetchOne();
	}
}
