<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\NotificationDelivery;
use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunAclRole;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\NotificationService;

class NotificationServiceTest extends RunTestBase {
	/**
	 * @return array{0: Run, 1: RunStep}
	 */
	private function runWithAssignedStep(string $assigneeType, string $assigneeId, ?int $dueAt = null): array {
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep(
			$section->getId(),
			'CHECK',
			true,
			RunStepStatus::Pending->value,
			0,
			[],
			$assigneeType,
			$assigneeId,
			$dueAt,
		);

		return [$run, $step];
	}

	public function testStepAssignmentNotifiesAssignedUser(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'bob');

		$sent = $this->notificationService->notifyStepAssigned($run, $step, 'alice');

		self::assertSame(1, $sent);
		self::assertCount(1, $this->sentNotifications);
		self::assertSame('bob', $this->sentNotifications[0]['user']);
		self::assertSame(NotificationService::SUBJECT_STEP_ASSIGNED, $this->sentNotifications[0]['subject']);
		self::assertStringContainsString(
			sprintf('#/run/%d/step/%d', $run->getId(), $step->getId()),
			$this->sentNotifications[0]['link'],
		);
		self::assertSame($run->getTitle(), $this->sentNotifications[0]['params']['runTitle']);
	}

	public function testAssignmentDoesNotNotifyTheActor(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'alice');

		$sent = $this->notificationService->notifyStepAssigned($run, $step, 'alice');

		self::assertSame(0, $sent);
		self::assertSame([], $this->sentNotifications);
	}

	public function testAssignmentIsDeduplicated(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'bob');

		self::assertSame(1, $this->notificationService->notifyStepAssigned($run, $step, 'alice'));
		self::assertSame(0, $this->notificationService->notifyStepAssigned($run, $step, 'alice'));
		self::assertCount(1, $this->sentNotifications);
		self::assertCount(1, $this->notificationDeliveries);
	}

	public function testMissingUserIsSkipped(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'ghost');

		$sent = $this->notificationService->notifyStepAssigned($run, $step, 'alice');

		self::assertSame(0, $sent);
		self::assertSame([], $this->sentNotifications);
		self::assertSame([], $this->notificationDeliveries);
	}

	public function testGroupAssignmentNotifiesAllCurrentMembers(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addUser('carol');
		$this->addGroup('engineering');
		$this->joinGroup('bob', 'engineering');
		$this->joinGroup('carol', 'engineering');
		[$run, $step] = $this->runWithAssignedStep(PrincipalType::Group->value, 'engineering');

		$sent = $this->notificationService->notifyStepAssigned($run, $step, 'alice');

		self::assertSame(2, $sent);
		$recipients = array_map(static fn (array $n): string => $n['user'], $this->sentNotifications);
		sort($recipients);
		self::assertSame(['bob', 'carol'], $recipients);
	}

	public function testMissingGroupIsSkipped(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithAssignedStep(PrincipalType::Group->value, 'ghost-group');

		self::assertSame(0, $this->notificationService->notifyStepAssigned($run, $step, 'alice'));
	}

	public function testRunAssignmentNotifiesParticipant(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Viewer->value);

		$sent = $this->notificationService->notifyRunAssigned($run, PrincipalType::User, 'bob', 'alice');

		self::assertSame(1, $sent);
		self::assertSame(NotificationService::SUBJECT_RUN_ASSIGNED, $this->sentNotifications[0]['subject']);
		self::assertStringContainsString(sprintf('#/run/%d', $run->getId()), $this->sentNotifications[0]['link']);
	}

	public function testRunAssignmentSkipsInaccessibleUser(): void {
		$this->addUser('alice');
		$this->addUser('stranger');
		$run = $this->addRun('alice');

		$sent = $this->notificationService->notifyRunAssigned($run, PrincipalType::User, 'stranger', 'alice');

		self::assertSame(0, $sent);
		self::assertSame([], $this->sentNotifications);
	}

	public function testMentionNotifiesRecipientWithStepLink(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'bob');

		$sent = $this->notificationService->notifyMention($run, $step, 42, ['bob'], 'alice');

		self::assertSame(1, $sent);
		self::assertSame(NotificationService::SUBJECT_MENTIONED, $this->sentNotifications[0]['subject']);
		self::assertStringContainsString('#/run/' . $run->getId() . '/step/' . $step->getId(), $this->sentNotifications[0]['link']);
	}

	public function testMentionSkipsActorAndInaccessibleUsers(): void {
		$this->addUser('alice');
		$this->addUser('stranger');
		$run = $this->addRun('alice');

		$sent = $this->notificationService->notifyMention($run, null, 42, ['alice', 'stranger'], 'alice');

		self::assertSame(0, $sent);
		self::assertSame([], $this->sentNotifications);
	}

	public function testDueNotificationNotifiesAssigneeOnce(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'bob', $this->now + 3600);

		self::assertTrue($this->notificationService->notifyStepDue($run, $step, 'bob'));
		self::assertFalse($this->notificationService->notifyStepDue($run, $step, 'bob'));
		self::assertTrue($this->notificationService->notifyStepOverdue($run, $step, 'bob'));
		self::assertFalse($this->notificationService->notifyStepOverdue($run, $step, 'bob'));

		self::assertCount(2, $this->sentNotifications);
		self::assertSame(NotificationService::SUBJECT_STEP_DUE, $this->sentNotifications[0]['subject']);
		self::assertSame(NotificationService::SUBJECT_STEP_OVERDUE, $this->sentNotifications[1]['subject']);
	}

	public function testSuccessfulNotificationIsNotSentTwice(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'bob');

		self::assertSame(1, $this->notificationService->notifyStepAssigned($run, $step, 'alice'));
		self::assertSame(0, $this->notificationService->notifyStepAssigned($run, $step, 'alice'));
		self::assertCount(1, $this->sentNotifications);
	}

	public function testFailedNotificationCanBeRetried(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'bob');

		$this->failNotifications = 1;
		self::assertSame(0, $this->notificationService->notifyStepAssigned($run, $step, 'alice'));
		self::assertSame([], $this->sentNotifications);

		$delivery = array_values($this->notificationDeliveries)[0];
		self::assertSame(NotificationDelivery::STATUS_FAILED, $delivery->getStatus());
		self::assertSame(1, $delivery->getAttempts());

		// A later trigger retries the delivery and succeeds.
		self::assertSame(1, $this->notificationService->notifyStepAssigned($run, $step, 'alice'));
		self::assertCount(1, $this->sentNotifications);
		self::assertSame(NotificationDelivery::STATUS_SENT, array_values($this->notificationDeliveries)[0]->getStatus());
	}

	public function testPendingDeliverySuppressesConcurrentDuplicate(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'bob');

		// Simulate a concurrent worker that has already claimed the delivery.
		$delivery = new NotificationDelivery();
		$delivery->setDedupeKey(NotificationService::SUBJECT_STEP_ASSIGNED . ':' . $run->getId() . ':' . $step->getId() . ':bob');
		$delivery->setType(NotificationService::SUBJECT_STEP_ASSIGNED);
		$delivery->setUserUid('bob');
		$delivery->setRunId($run->getId());
		$delivery->setStepId($step->getId());
		$delivery->setStatus(NotificationDelivery::STATUS_PENDING);
		$delivery->setAttempts(1);
		$delivery->setCreatedAt($this->now);
		$delivery->setUpdatedAt($this->now);
		$this->deliveryMapper->insert($delivery);

		self::assertSame(0, $this->notificationService->notifyStepAssigned($run, $step, 'alice'));
		self::assertSame([], $this->sentNotifications);
	}

	public function testDeliveryStopsAfterMaximumAttempts(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'bob');

		$delivery = new NotificationDelivery();
		$delivery->setDedupeKey(NotificationService::SUBJECT_STEP_ASSIGNED . ':' . $run->getId() . ':' . $step->getId() . ':bob');
		$delivery->setType(NotificationService::SUBJECT_STEP_ASSIGNED);
		$delivery->setUserUid('bob');
		$delivery->setRunId($run->getId());
		$delivery->setStepId($step->getId());
		$delivery->setStatus(NotificationDelivery::STATUS_FAILED);
		$delivery->setAttempts(NotificationDelivery::MAX_ATTEMPTS);
		$delivery->setCreatedAt($this->now);
		$delivery->setUpdatedAt($this->now);
		$this->deliveryMapper->insert($delivery);

		self::assertSame(0, $this->notificationService->notifyStepAssigned($run, $step, 'alice'));
		self::assertSame([], $this->sentNotifications);
	}

	public function testPayloadContainsNoSensitiveData(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'bob');

		$this->notificationService->notifyStepAssigned($run, $step, 'alice');

		$params = $this->sentNotifications[0]['params'];
		self::assertArrayNotHasKey('storageKey', $params);
		self::assertArrayNotHasKey('body', $params);
	}

	public function testDisabledNotificationsPreventEventDelivery(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'bob');
		$this->setAppConfig(AdminSettings::KEY_NOTIFICATIONS_ENABLED, false);

		self::assertSame(0, $this->notificationService->notifyStepAssigned($run, $step, 'alice'));
		self::assertSame([], $this->sentNotifications);
		// Disabled delivery must not consume ledger state.
		self::assertSame([], $this->notificationDeliveries);
	}

	public function testNotificationsRequireActiveRunAccess(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice', RunStatus::Completed->value);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'bob');

		// bob is assigned, so canView is true even for a read-only run; links stay protected.
		self::assertSame(1, $this->notificationService->notifyStepAssigned($run, $step, 'alice'));
		unset($run);
	}
}
