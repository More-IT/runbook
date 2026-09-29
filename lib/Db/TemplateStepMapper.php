<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<TemplateStep>
 */
class TemplateStepMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'runbook_template_steps', TemplateStep::class);
	}

	public function find(int $id): TemplateStep {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$step = $this->findEntity($qb);

		return $step;
	}

	/**
	 * @return list<TemplateStep>
	 */
	public function findBySection(int $sectionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('section_id', $qb->createNamedParameter($sectionId, IQueryBuilder::PARAM_INT)))
			->orderBy('position', 'ASC')
			->addOrderBy('id', 'ASC');

		$steps = $this->findEntities($qb);

		return $steps;
	}
}
