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

		/** @var Attachment $attachment */
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

		/** @var list<Attachment> $attachments */
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

		/** @var list<Attachment> $attachments */
		$attachments = $this->findEntities($qb);

		return $attachments;
	}
}
