<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\NotificationDelivery;
use OCA\Runbook\Db\NotificationDeliveryMapper;
use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Enum\PrincipalType;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Creates Runbook notifications and guarantees they are delivered at most once.
 *
 * Delivery is best effort: a failing notification must never break the run
 * mutation that triggered it. Every delivery attempt is recorded in a persistent
 * ledger keyed by a stable deduplication key, so repeated triggers (polling,
 * background jobs, repeated saves) never produce notification spam.
 */
class NotificationService {
	public const SUBJECT_STEP_ASSIGNED = 'step_assigned';
	public const SUBJECT_RUN_ASSIGNED = 'run_assigned';
	public const SUBJECT_MENTIONED = 'mentioned';
	public const SUBJECT_STEP_DUE = 'step_due';
	public const SUBJECT_STEP_OVERDUE = 'step_overdue';

	private const APP_ID = 'runbook';
	private const MAX_GROUP_RECIPIENTS = 500;

	/**
	 * A pending delivery older than this is assumed to belong to a crashed
	 * worker and may be claimed again for a retry.
	 */
	private const STALE_PENDING_SECONDS = 900;

	public function __construct(
		private readonly IManager $manager,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly NotificationDeliveryMapper $deliveries,
		private readonly RunAccessService $access,
		private readonly AdminSettings $settings,
		private readonly ITimeFactory $timeFactory,
		private readonly IURLGenerator $url,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Notify the users a run step is assigned to.
	 *
	 * @return int Number of notifications delivered.
	 */
	public function notifyStepAssigned(Run $run, RunStep $step, string $actorUid): int {
		$recipients = $this->recipientsFor($step->getAssigneeType(), $step->getAssigneeId());
		$params = $this->stepParams($run, $step, $actorUid);
		$link = $this->stepLink($run->getId(), $step->getId());

		return $this->deliverToRecipients(
			$run,
			$recipients,
			self::SUBJECT_STEP_ASSIGNED,
			$params,
			$link,
			static fn (string $uid): string => sprintf('%s:%d:%d:%s', self::SUBJECT_STEP_ASSIGNED, $run->getId(), $step->getId(), $uid),
			$actorUid,
		);
	}

	/**
	 * Notify the users a run is shared with through the run ACL.
	 *
	 * @return int Number of notifications delivered.
	 */
	public function notifyRunAssigned(Run $run, PrincipalType $type, string $principalId, string $actorUid): int {
		$recipients = $this->recipientsFor($type->value, $principalId);
		$params = [
			'runId' => $run->getId(),
			'runTitle' => $run->getTitle(),
			'actor' => $actorUid,
		];

		return $this->deliverToRecipients(
			$run,
			$recipients,
			self::SUBJECT_RUN_ASSIGNED,
			$params,
			$this->runLink($run->getId()),
			static fn (string $uid): string => sprintf('%s:%d:%s', self::SUBJECT_RUN_ASSIGNED, $run->getId(), $uid),
			$actorUid,
		);
	}

	/**
	 * Notify the persisted mention recipients of a comment.
	 *
	 * @param list<string> $recipients Persisted, validated mention recipients.
	 * @return int Number of notifications delivered.
	 */
	public function notifyMention(Run $run, ?RunStep $step, int $commentId, array $recipients, string $actorUid): int {
		$params = [
			'runId' => $run->getId(),
			'stepId' => $step?->getId(),
			'runTitle' => $run->getTitle(),
			'stepTitle' => $step?->getTitle(),
			'commentId' => $commentId,
			'actor' => $actorUid,
		];
		$link = $step !== null
			? $this->stepLink($run->getId(), $step->getId())
			: $this->runLink($run->getId());

		return $this->deliverToRecipients(
			$run,
			$recipients,
			self::SUBJECT_MENTIONED,
			$params,
			$link,
			static fn (string $uid): string => sprintf('%s:%d:%d:%s', self::SUBJECT_MENTIONED, $commentId, $run->getId(), $uid),
			$actorUid,
		);
	}

	/**
	 * Notify a single user that a step is due tomorrow.
	 *
	 * The due-day boundary is resolved per recipient by the caller so that a
	 * group-assigned step can notify different users at different times.
	 */
	public function notifyStepDue(Run $run, RunStep $step, string $uid): bool {
		return $this->notifyStepSchedule($run, $step, self::SUBJECT_STEP_DUE, $uid);
	}

	/**
	 * Notify a single user that a step is overdue.
	 */
	public function notifyStepOverdue(Run $run, RunStep $step, string $uid): bool {
		return $this->notifyStepSchedule($run, $step, self::SUBJECT_STEP_OVERDUE, $uid);
	}

	private function notifyStepSchedule(Run $run, RunStep $step, string $subject, string $uid): bool {
		if (!$this->access->canView($run, $uid)) {
			return false;
		}

		$params = $this->stepParams($run, $step, null);
		$link = $this->stepLink($run->getId(), $step->getId());
		$dedupeKey = sprintf('%s:%d:%d:%s', $subject, $run->getId(), $step->getId(), $uid);

		return $this->deliver($uid, $subject, $params, $link, $dedupeKey);
	}

	/**
	 * @param list<string> $recipients
	 * @param array<string, mixed> $params
	 * @param callable(string): string $dedupeKey
	 */
	private function deliverToRecipients(
		Run $run,
		array $recipients,
		string $subject,
		array $params,
		string $link,
		callable $dedupeKey,
		?string $actorUid,
	): int {
		$delivered = 0;
		foreach ($this->uniqueRecipients($recipients) as $uid) {
			if ($actorUid !== null && $uid === $actorUid) {
				continue;
			}
			if (!$this->access->canView($run, $uid)) {
				continue;
			}
			if ($this->deliver($uid, $subject, $params, $link, $dedupeKey($uid))) {
				$delivered++;
			}
		}

		return $delivered;
	}

	/**
	 * Deliver a notification at most once, retrying transient failures.
	 *
	 * The ledger row is the durable source of truth:
	 * - a new key is inserted as `pending` with one attempt;
	 * - a retryable (failed, or stale pending) row is atomically claimed before
	 *   delivery so concurrent workers cannot both send it;
	 * - a successful delivery is marked `sent` and never sent again;
	 * - a failed delivery is marked `failed` and stays retryable until
	 *   {@see NotificationDelivery::MAX_ATTEMPTS}, which bounds duplicates.
	 *
	 * @param array<string, mixed> $params
	 */
	private function deliver(string $uid, string $subject, array $params, string $link, string $dedupeKey): bool {
		if ($uid === '' || !$this->userManager->userExists($uid)) {
			return false;
		}
		if (!$this->settings->isNotificationsEnabled()) {
			// Disabled features must not change ledger state so that re-enabling
			// notifications resumes delivery without losing or duplicating them.
			return false;
		}

		$now = $this->timeFactory->getTime();
		$existing = $this->deliveries->findByKey($dedupeKey);

		if ($existing !== null) {
			if ($existing->getStatus() === NotificationDelivery::STATUS_SENT) {
				return false;
			}
			if ($existing->getAttempts() >= NotificationDelivery::MAX_ATTEMPTS) {
				return false;
			}

			$claimed = $this->deliveries->claimForDelivery(
				$existing->getId(),
				$now,
				NotificationDelivery::MAX_ATTEMPTS,
				$now - self::STALE_PENDING_SECONDS,
			);
			if (!$claimed) {
				// Another worker is delivering, or the row is terminal.
				return false;
			}

			$deliveryId = $existing->getId();
		} else {
			$delivery = new NotificationDelivery();
			$delivery->setDedupeKey($dedupeKey);
			$delivery->setType($subject);
			$delivery->setUserUid($uid);
			$delivery->setRunId((int)($params['runId'] ?? 0));
			$delivery->setStepId(
				isset($params['stepId']) && is_int($params['stepId']) ? $params['stepId'] : null,
			);
			$delivery->setStatus(NotificationDelivery::STATUS_PENDING);
			$delivery->setAttempts(1);
			$delivery->setCreatedAt($now);
			$delivery->setUpdatedAt($now);

			try {
				$delivery = $this->deliveries->insert($delivery);
			} catch (DbException) {
				// A concurrent worker created the row first; do not duplicate.
				return false;
			}

			$deliveryId = (int)$delivery->getId();
		}

		try {
			$notification = $this->manager->createNotification();
			$notification->setApp(self::APP_ID)
				->setUser($uid)
				->setDateTime((new \DateTime('now'))->setTimestamp($now))
				->setObject('run', (string)($params['runId'] ?? 0))
				->setSubject($subject, $params)
				->setLink($link);
			$this->manager->notify($notification);
		} catch (\Throwable $exception) {
			$this->logger->warning('Runbook notification delivery failed', [
				'app' => self::APP_ID,
				'exception' => $exception,
			]);
			$this->deliveries->markFailed($deliveryId, $now);

			return false;
		}

		$this->deliveries->markSent($deliveryId, $now);

		return true;
	}

	/**
	 * @param list<string> $recipients
	 * @return list<string>
	 */
	private function uniqueRecipients(array $recipients): array {
		$unique = [];
		foreach ($recipients as $uid) {
			if ($uid !== '' && !isset($unique[$uid])) {
				$unique[$uid] = true;
			}
		}

		return array_keys($unique);
	}

	/**
	 * Resolve the existing user recipients of a user or group principal.
	 *
	 * Group membership is resolved through Nextcloud and bounded by
	 * {@see self::MAX_GROUP_RECIPIENTS} so a very large group never produces an
	 * unbounded query or notification fan-out. Groups are never notified
	 * directly; only their current members.
	 *
	 * @return list<string>
	 */
	public function recipientsFor(?string $type, ?string $id): array {
		if ($type === null || $id === null) {
			return [];
		}

		$candidates = [];
		if ($type === PrincipalType::User->value) {
			$candidates[] = $id;
		} elseif ($type === PrincipalType::Group->value) {
			$group = $this->groupManager->get($id);
			if ($group === null) {
				return [];
			}
			foreach ($group->getUsers() as $user) {
				$candidates[] = $user->getUID();
				if (count($candidates) >= self::MAX_GROUP_RECIPIENTS) {
					break;
				}
			}
		}

		$recipients = [];
		foreach ($candidates as $uid) {
			if ($uid !== '' && !isset($recipients[$uid]) && $this->userManager->userExists($uid)) {
				$recipients[$uid] = true;
			}
		}

		return array_keys($recipients);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function stepParams(Run $run, RunStep $step, ?string $actorUid): array {
		return [
			'runId' => $run->getId(),
			'stepId' => $step->getId(),
			'runTitle' => $run->getTitle(),
			'stepTitle' => $step->getTitle(),
			'actor' => $actorUid,
		];
	}

	private function runLink(int $runId): string {
		// Nextcloud 33 requires absolute notification links.
		return $this->url->linkToRouteAbsolute('runbook.page.index') . '#/run/' . $runId;
	}

	private function stepLink(int $runId, int $stepId): string {
		return $this->runLink($runId) . '/step/' . $stepId;
	}
}
