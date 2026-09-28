<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<FilesCleanup>
 */
class FilesCleanupMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'runbook_files_cleanup', FilesCleanup::class);
	}

	public function find(int $id): FilesCleanup {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		/** @var FilesCleanup $cleanup */
		$cleanup = $this->findEntity($qb);

		return $cleanup;
	}

	/**
	 * Records that still need a retry, oldest first.
	 *
	 * @return list<FilesCleanup>
	 */
	public function findPending(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('status', $qb->createNamedParameter(FilesCleanup::STATUS_PENDING)))
			->orderBy('created_at', 'ASC')
			->addOrderBy('id', 'ASC');

		/** @var list<FilesCleanup> $cleanups */
		$cleanups = $this->findEntities($qb);

		return $cleanups;
	}
}
