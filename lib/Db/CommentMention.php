<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A validated user mention extracted from a comment body.
 *
 * @method int getCommentId()
 * @method void setCommentId(int $commentId)
 * @method string getMentionedUid()
 * @method void setMentionedUid(string $mentionedUid)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 *
 * @phpstan-type CommentMentionData array{
 *     id: int,
 *     commentId: int,
 *     mentionedUid: string,
 *     createdAt: int
 * }
 */
class CommentMention extends Entity {
	protected int $commentId = 0;
	protected string $mentionedUid = '';
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('commentId', Types::BIGINT);
		$this->addType('mentionedUid', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
	}

	/**
	 * @return CommentMentionData
	 */
	public function toArray(): array {
		return [
			'id' => (int)$this->id,
			'commentId' => $this->commentId,
			'mentionedUid' => $this->mentionedUid,
			'createdAt' => $this->createdAt,
		];
	}
}
