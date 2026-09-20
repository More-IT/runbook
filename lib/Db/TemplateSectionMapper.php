<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<TemplateSection>
 */
class TemplateSectionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'runbook_template_sections', TemplateSection::class);
	}

	public function find(int $id): TemplateSection {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		/** @var TemplateSection $section */
		$section = $this->findEntity($qb);

		return $section;
	}

	/**
	 * @return list<TemplateSection>
	 */
	public function findByTemplate(int $templateId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('template_id', $qb->createNamedParameter($templateId, IQueryBuilder::PARAM_INT)))
			->orderBy('position', 'ASC')
			->addOrderBy('id', 'ASC');

		/** @var list<TemplateSection> $sections */
		$sections = $this->findEntities($qb);

		return $sections;
	}
}
