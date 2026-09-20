<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Template>
 */
class TemplateMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'runbook_templates', Template::class);
	}

	public function find(int $id): Template {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		/** @var Template $template */
		$template = $this->findEntity($qb);

		return $template;
	}

	/**
	 * Templates accessible to a user: templates they own plus the templates
	 * reached through a direct user or group ACL entry.
	 *
	 * Published templates without an ACL entry are intentionally not returned;
	 * publishing and sharing are separate actions.
	 *
	 * @param list<int> $templateIds Template ids granted through ACL entries.
	 * @return list<Template>
	 */
	public function findAccessible(string $owner, array $templateIds): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName);

		$ownerCondition = $qb->expr()->eq('owner', $qb->createNamedParameter($owner));
		if ($templateIds === []) {
			$qb->where($ownerCondition);
		} else {
			$qb->where(
				$qb->expr()->orX(
					$ownerCondition,
					$qb->expr()->in('id', $qb->createNamedParameter($templateIds, IQueryBuilder::PARAM_INT_ARRAY)),
				),
			);
		}

		$qb->orderBy('updated_at', 'DESC')
			->addOrderBy('id', 'DESC');

		/** @var list<Template> $templates */
		$templates = $this->findEntities($qb);

		return $templates;
	}

	/**
	 * Templates accessible to a user whose title or description matches the
	 * given case-insensitive term.
	 *
	 * @param list<int> $templateIds Template ids granted through ACL entries.
	 * @return list<Template>
	 */
	public function searchAccessible(string $owner, array $templateIds, string $term, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName);

		$ownerCondition = $qb->expr()->eq('owner', $qb->createNamedParameter($owner));
		if ($templateIds === []) {
			$qb->where($ownerCondition);
		} else {
			$qb->where(
				$qb->expr()->orX(
					$ownerCondition,
					$qb->expr()->in('id', $qb->createNamedParameter($templateIds, IQueryBuilder::PARAM_INT_ARRAY)),
				),
			);
		}

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

		/** @var list<Template> $templates */
		$templates = $this->findEntities($qb);

		return $templates;
	}
}
