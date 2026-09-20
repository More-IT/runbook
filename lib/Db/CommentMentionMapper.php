<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<CommentMention>
 */
class CommentMentionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'runbook_comment_mentions', CommentMention::class);
	}

	public function find(int $id): CommentMention {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		/** @var CommentMention $mention */
		$mention = $this->findEntity($qb);

		return $mention;
	}

	/**
	 * @return list<CommentMention>
	 */
	public function findByComment(int $commentId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('comment_id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		/** @var list<CommentMention> $mentions */
		$mentions = $this->findEntities($qb);

		return $mentions;
	}

	/**
	 * Mentions for a set of comments.
	 *
	 * @param list<int> $commentIds
	 * @return list<CommentMention>
	 */
	public function findByComments(array $commentIds): array {
		if ($commentIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->in(
				'comment_id',
				$qb->createNamedParameter($commentIds, IQueryBuilder::PARAM_INT_ARRAY),
			))
			->orderBy('id', 'ASC');

		/** @var list<CommentMention> $mentions */
		$mentions = $this->findEntities($qb);

		return $mentions;
	}

	public function deleteByComment(int $commentId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where($qb->expr()->eq('comment_id', $qb->createNamedParameter($commentId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
