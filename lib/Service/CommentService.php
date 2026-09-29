<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\Comment;
use OCA\Runbook\Db\CommentMapper;
use OCA\Runbook\Db\Run;
use OCA\Runbook\Enum\ActivityType;
use OCA\Runbook\Enum\RunStatus;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

/**
 * Plain-text comments on runs and run steps.
 *
 * Comments are never rendered as HTML or Markdown. Mentions are parsed and
 * stored, but no notifications are sent in this milestone.
 */
class CommentService {
	private const MAX_BODY_LENGTH = 10000;

	public function __construct(
		private readonly CommentMapper $comments,
		private readonly MentionService $mentions,
		private readonly RunService $runService,
		private readonly RunAccessService $access,
		private readonly ActivityService $activity,
		private readonly NotificationService $notifications,
		private readonly AdminSettings $settings,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $timeFactory,
		private readonly ISecureRandom $secureRandom,
	) {
	}

	/**
	 * @return list<array{comment: Comment, authorDisplayName: string, mentions: list<array{uid: string, displayName: string}>}>
	 */
	public function listComments(int $runId): array {
		$this->runService->requireAccessibleRun($runId);

		$comments = $this->comments->findByRun($runId);
		$commentIds = array_map(static fn (Comment $comment): int => $comment->getId(), $comments);
		$mentionMap = $this->mentions->listForComments($commentIds);

		return array_map(
			fn (Comment $comment): array => $this->buildItem($comment, $mentionMap[$comment->getId()] ?? []),
			$comments,
		);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{comment: Comment, authorDisplayName: string, mentions: list<array{uid: string, displayName: string}>}
	 */
	public function createComment(int $runId, array $data): array {
		$this->assertCommentsEnabled();
		$run = $this->requireCommentableRun($runId);
		$stepId = $this->readStepId($data, $run->getId());
		$body = $this->validateBody($data);
		$uid = $this->currentUserId();
		$now = $this->timeFactory->getTime();

		$comment = new Comment();
		$comment->setUuid($this->generateUuid());
		$comment->setRunId($run->getId());
		$comment->setStepId($stepId);
		$comment->setAuthorUid($uid);
		$comment->setBody($body);
		$comment->setCreatedAt($now);
		$comment->setUpdatedAt($now);
		$comment = $this->comments->insert($comment);

		$uids = $this->mentions->parseMentions($body);
		$this->mentions->replaceMentions($comment->getId(), $uids);

		$this->activity->record($run->getId(), $stepId, ActivityType::CommentAdded, [
			'commentId' => $comment->getId(),
		], $uid);

		$step = $stepId !== null ? $this->runService->requireStep($stepId) : null;
		$this->notifications->notifyMention($run, $step, $comment->getId(), $uids, $uid);

		return $this->buildItem($comment, $uids);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{comment: Comment, authorDisplayName: string, mentions: list<array{uid: string, displayName: string}>}
	 */
	public function updateComment(int $commentId, array $data): array {
		$this->assertCommentsEnabled();
		$comment = $this->requireComment($commentId);
		$run = $this->runService->requireAccessibleRun($comment->getRunId());
		$this->assertRunActive($run);

		$uid = $this->currentUserId();
		if ($comment->getAuthorUid() !== $uid) {
			throw new ForbiddenException('not_comment_author');
		}

		$body = $this->validateBody($data);
		$comment->setBody($body);
		$comment->setUpdatedAt($this->timeFactory->getTime());
		$comment = $this->comments->update($comment);

		$uids = $this->mentions->parseMentions($body);
		$this->mentions->replaceMentions($comment->getId(), $uids);

		$this->activity->record($run->getId(), $comment->getStepId(), ActivityType::CommentEdited, [
			'commentId' => $comment->getId(),
		], $uid);

		return $this->buildItem($comment, $uids);
	}

	public function deleteComment(int $commentId): void {
		$this->assertCommentsEnabled();
		$comment = $this->requireComment($commentId);
		$run = $this->runService->requireAccessibleRun($comment->getRunId());
		$this->assertRunActive($run);

		$uid = $this->currentUserId();
		if ($comment->getAuthorUid() !== $uid && !$this->access->isOwner($run, $uid)) {
			throw new ForbiddenException('not_comment_author');
		}

		// Record the event before the comment row (and its mentions) disappear.
		$this->activity->record($run->getId(), $comment->getStepId(), ActivityType::CommentDeleted, [
			'commentId' => $comment->getId(),
		], $uid);

		$this->comments->delete($comment);
	}

	private function requireCommentableRun(int $runId): Run {
		$run = $this->runService->requireAccessibleRun($runId);
		$this->assertRunActive($run);

		if (!$this->access->canComment($run, $this->currentUserId())) {
			throw new ForbiddenException('not_allowed');
		}

		return $run;
	}

	/**
	 * Comments are a global feature toggle; existing comments stay readable.
	 */
	private function assertCommentsEnabled(): void {
		if (!$this->settings->isCommentsEnabled()) {
			throw new ForbiddenException('comments_disabled');
		}
	}

	private function assertRunActive(Run $run): void {
		if ($run->getStatus() !== RunStatus::Active->value) {
			throw new ConflictException('run_not_active');
		}
	}

	private function requireComment(int $id): Comment {
		try {
			return $this->comments->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('comment_not_found');
		}
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function readStepId(array $data, int $runId): ?int {
		if (!array_key_exists('stepId', $data) || $data['stepId'] === null) {
			return null;
		}

		if (is_int($data['stepId'])) {
			$stepId = $data['stepId'];
		} elseif (is_string($data['stepId']) && preg_match('/^[0-9]+$/', $data['stepId']) === 1) {
			$stepId = (int)$data['stepId'];
		} else {
			throw new ValidationException('invalid_field');
		}

		$this->runService->requireStepInRun($runId, $stepId);

		return $stepId;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function validateBody(array $data): string {
		$body = $this->readString($data, 'body');
		if ($body === null) {
			throw new ValidationException('comment_body_required');
		}

		$body = str_replace(["\r\n", "\r"], "\n", $body);
		$body = trim($body);
		if ($body === '') {
			throw new ValidationException('comment_body_required');
		}
		if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
			throw new ValidationException('comment_body_too_long');
		}
		if (preg_match('/<\s*[a-zA-Z!\/]/', $body) === 1) {
			throw new ValidationException('comment_body_invalid_html');
		}

		return $body;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function readString(array $data, string $key): ?string {
		if (!array_key_exists($key, $data) || $data[$key] === null) {
			return null;
		}
		if (!is_string($data[$key])) {
			throw new ValidationException('invalid_field');
		}

		return $data[$key];
	}

	/**
	 * @param list<string> $uids
	 * @return array{comment: Comment, authorDisplayName: string, mentions: list<array{uid: string, displayName: string}>}
	 */
	private function buildItem(Comment $comment, array $uids): array {
		return [
			'comment' => $comment,
			'authorDisplayName' => $this->mentions->displayName($comment->getAuthorUid()),
			'mentions' => array_map(
				fn (string $uid): array => ['uid' => $uid, 'displayName' => $this->mentions->displayName($uid)],
				$uids,
			),
		];
	}

	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new ForbiddenException('not_authenticated');
		}

		return $user->getUID();
	}

	private function generateUuid(): string {
		$hex = $this->secureRandom->generate(32, '0123456789abcdef');
		$hex = substr_replace($hex, '4', 12, 1);
		$variant = dechex(0x8 | ((int)hexdec($hex[16]) & 0x3));
		$hex = substr_replace($hex, $variant, 16, 1);

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr($hex, 0, 8),
			substr($hex, 8, 4),
			substr($hex, 12, 4),
			substr($hex, 16, 4),
			substr($hex, 20, 12),
		);
	}
}
