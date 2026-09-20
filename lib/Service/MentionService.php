<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\CommentMention;
use OCA\Runbook\Db\CommentMentionMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

/**
 * Parses and stores plain-text @mentions found in comments.
 *
 * Supported format: "@" immediately followed by a Nextcloud user id composed
 * of letters, digits, ".", "_", "-" or "@". Mentions are validated against
 * Nextcloud; unknown usernames are ignored. Groups are never stored as
 * mentions and mentions never grant run access.
 */
class MentionService {
	private const MAX_MENTIONS_PER_COMMENT = 50;

	public function __construct(
		private readonly CommentMentionMapper $mentions,
		private readonly IUserManager $userManager,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * Extract valid, unique user ids mentioned in the given body.
	 *
	 * @return list<string>
	 */
	public function parseMentions(string $body): array {
		$result = preg_match_all('/(?<![A-Za-z0-9_@])@([A-Za-z0-9][A-Za-z0-9_.@-]*)/', $body, $matches);
		if ($result === false || $result === 0) {
			return [];
		}

		$uids = [];
		foreach ($matches[1] as $candidate) {
			if (count($uids) >= self::MAX_MENTIONS_PER_COMMENT) {
				break;
			}
			if (in_array($candidate, $uids, true)) {
				continue;
			}
			if (!$this->userManager->userExists($candidate)) {
				continue;
			}
			$uids[] = $candidate;
		}

		return $uids;
	}

	/**
	 * Replace all mention rows of a comment with the given user ids.
	 *
	 * @param list<string> $uids
	 */
	public function replaceMentions(int $commentId, array $uids): void {
		$this->mentions->deleteByComment($commentId);

		$now = $this->timeFactory->getTime();
		$stored = [];
		foreach ($uids as $uid) {
			if (isset($stored[$uid]) || !$this->userManager->userExists($uid)) {
				continue;
			}
			$stored[$uid] = true;

			$mention = new CommentMention();
			$mention->setCommentId($commentId);
			$mention->setMentionedUid($uid);
			$mention->setCreatedAt($now);
			$this->mentions->insert($mention);
		}
	}

	/**
	 * Mentioned user ids grouped by comment id.
	 *
	 * @param list<int> $commentIds
	 * @return array<int, list<string>>
	 */
	public function listForComments(array $commentIds): array {
		$grouped = [];
		foreach ($this->mentions->findByComments($commentIds) as $mention) {
			$grouped[$mention->getCommentId()][] = $mention->getMentionedUid();
		}

		return $grouped;
	}

	public function displayName(string $uid): string {
		return $this->userManager->getDisplayName($uid) ?? $uid;
	}
}
