<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunAclRole;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Service\NotificationService;

class NotificationIntegrationTest extends RunTestBase {
	/**
	 * @return array{0: Run, 1: RunStep}
	 */
	private function runWithAssignedStep(
		string $assigneeType,
		string $assigneeId,
		?int $dueAt,
		string $runStatus = RunStatus::Active->value,
		string $stepStatus = RunStepStatus::Pending->value,
	): array {
		$run = $this->addRun('alice', $runStatus);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, $stepStatus, 0, [], $assigneeType, $assigneeId, $dueAt);

		return [$run, $step];
	}

	private function dayStart(string $timezone, int $now): int {
		return (new \DateTimeImmutable('@' . $now))
			->setTimezone(new \DateTimeZone($timezone))
			->setTime(0, 0, 0)
			->getTimestamp();
	}

	public function testStepAssignmentThroughServiceNotifiesAssignee(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0);

		$this->runStepServiceFor('alice')->update($step->getId(), [
			'assigneeType' => PrincipalType::User->value,
			'assigneeId' => 'bob',
		]);

		self::assertCount(1, $this->sentNotifications);
		self::assertSame('bob', $this->sentNotifications[0]['user']);
		self::assertSame(NotificationService::SUBJECT_STEP_ASSIGNED, $this->sentNotifications[0]['subject']);
	}

	public function testSelfAssignmentThroughServiceDoesNotNotify(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0);

		$this->runStepServiceFor('alice')->update($step->getId(), [
			'assigneeType' => PrincipalType::User->value,
			'assigneeId' => 'alice',
		]);

		self::assertSame([], $this->sentNotifications);
	}

	public function testRunAclAssignmentThroughServiceNotifiesParticipant(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');

		$this->runAclServiceFor('alice')->replaceAcl($run->getId(), [
			['principalType' => PrincipalType::User->value, 'principalId' => 'bob', 'role' => RunAclRole::Viewer->value],
		]);

		self::assertCount(1, $this->sentNotifications);
		self::assertSame('bob', $this->sentNotifications[0]['user']);
		self::assertSame(NotificationService::SUBJECT_RUN_ASSIGNED, $this->sentNotifications[0]['subject']);
	}

	public function testCommentMentionThroughServiceNotifiesRecipient(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Viewer->value);

		$this->commentServiceFor('alice')->createComment($run->getId(), ['body' => 'Please review @bob']);

		self::assertCount(1, $this->sentNotifications);
		self::assertSame(NotificationService::SUBJECT_MENTIONED, $this->sentNotifications[0]['subject']);
		self::assertSame('bob', $this->sentNotifications[0]['user']);
		self::assertStringContainsString('#/run/' . $run->getId(), $this->sentNotifications[0]['link']);
	}

	public function testStartRunNotifiesDefaultStepAssignee(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check backups', 'CHECK', true, 0, [], 'principals/users/bob');

		$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Release']);

		self::assertCount(1, $this->sentNotifications);
		self::assertSame('bob', $this->sentNotifications[0]['user']);
		self::assertSame(NotificationService::SUBJECT_STEP_ASSIGNED, $this->sentNotifications[0]['subject']);
	}

	public function testGroupAssignmentNotifiesEveryMemberOnceAndSkipsActor(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addUser('carol');
		$this->addGroup('engineering');
		$this->joinGroup('alice', 'engineering');
		$this->joinGroup('bob', 'engineering');
		$this->joinGroup('carol', 'engineering');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0);

		$this->runStepServiceFor('alice')->update($step->getId(), [
			'assigneeType' => PrincipalType::Group->value,
			'assigneeId' => 'engineering',
		]);

		$recipients = array_map(static fn (array $n): string => $n['user'], $this->sentNotifications);
		sort($recipients);
		self::assertSame(['bob', 'carol'], $recipients, 'every member except the actor is notified once');
	}

	public function testReassignmentNotifiesTheNewAssigneeAndDeduplicatesTheOldOne(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addUser('carol');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0);
		$service = $this->runStepServiceFor('alice');

		$service->update($step->getId(), ['assigneeType' => PrincipalType::User->value, 'assigneeId' => 'bob']);
		$service->update($step->getId(), ['assigneeType' => PrincipalType::User->value, 'assigneeId' => 'carol']);
		// Re-assigning back to bob must not notify him again (dedupe key sent).
		$service->update($step->getId(), ['assigneeType' => PrincipalType::User->value, 'assigneeId' => 'bob']);

		$recipients = array_map(static fn (array $n): string => $n['user'], $this->sentNotifications);
		self::assertSame(['bob', 'carol'], $recipients, 'each new assignee is notified once; the old dedupe key is suppressed');
	}

	public function testCompletedStepAndCancelledRunProduceNoScheduledNotification(): void {
		$this->now = 1_700_000_000;
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addUser('carol');
		$this->addUser('dave');
		$dueTomorrow = $this->dayStart('UTC', $this->now) + 86400 + 3600;
		[, $pending] = $this->runWithAssignedStep(PrincipalType::User->value, 'bob', $dueTomorrow);
		[, $completed] = $this->runWithAssignedStep(
			PrincipalType::User->value,
			'carol',
			$dueTomorrow,
			RunStatus::Active->value,
			RunStepStatus::Completed->value,
		);
		[, $cancelledRunStep] = $this->runWithAssignedStep(
			PrincipalType::User->value,
			'dave',
			$dueTomorrow,
			RunStatus::Cancelled->value,
			RunStepStatus::Pending->value,
		);
		$this->runStepMapper->method('findAssignedStepsDueBetween')->willReturn([$pending, $completed, $cancelledRunStep]);

		self::assertSame(1, $this->dueNotificationService()->processDueTomorrow());
		self::assertCount(1, $this->sentNotifications);
		self::assertSame('bob', $this->sentNotifications[0]['user'], 'only the pending step of an active run notifies');
	}

	public function testDueAndOverdueNotificationsReachTwoUsersAndAreDeduplicated(): void {
		$this->now = 1_700_000_000;
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addUser('carol');
		[$dueRun, $dueStep] = $this->runWithAssignedStep(
			PrincipalType::User->value,
			'bob',
			$this->dayStart('UTC', $this->now) + 86400 + 3600,
		);
		[$overdueRun, $overdueStep] = $this->runWithAssignedStep(
			PrincipalType::User->value,
			'carol',
			$this->dayStart('UTC', $this->now) - 3600,
		);
		$this->runStepMapper->method('findAssignedStepsDueBetween')->willReturn([$dueStep]);
		$this->runStepMapper->method('findAssignedStepsOverdue')->willReturn([$overdueStep]);

		$service = $this->dueNotificationService();
		self::assertSame(1, $service->processDueTomorrow());
		self::assertSame(1, $service->processOverdue());

		self::assertCount(2, $this->sentNotifications);
		self::assertSame('bob', $this->sentNotifications[0]['user']);
		self::assertSame(NotificationService::SUBJECT_STEP_DUE, $this->sentNotifications[0]['subject']);
		self::assertSame('carol', $this->sentNotifications[1]['user']);
		self::assertSame(NotificationService::SUBJECT_STEP_OVERDUE, $this->sentNotifications[1]['subject']);
		self::assertStringContainsString(
			'#/run/' . $dueRun->getId() . '/step/' . $dueStep->getId(),
			$this->sentNotifications[0]['link'],
			'the notification carries an absolute deep link to the step',
		);
		self::assertSame($overdueStep->getId(), $this->sentNotifications[1]['params']['stepId']);

		// Running the scans again is deduplicated by the delivery ledger.
		self::assertSame(0, $service->processDueTomorrow());
		self::assertSame(0, $service->processOverdue());
		self::assertCount(2, $this->sentNotifications);
	}

	public function testDisabledNotificationsProduceNoDeliveryAndNoLedgerRows(): void {
		$this->now = 1_700_000_000;
		$this->addUser('alice');
		$this->addUser('bob');
		[, $step] = $this->runWithAssignedStep(
			PrincipalType::User->value,
			'bob',
			$this->dayStart('UTC', $this->now) + 86400 + 3600,
		);
		$this->runStepMapper->method('findAssignedStepsDueBetween')->willReturn([$step]);
		$this->setAppConfig(\OCA\Runbook\Service\AdminSettings::KEY_NOTIFICATIONS_ENABLED, false);

		self::assertSame(0, $this->dueNotificationService()->processDueTomorrow());
		self::assertSame([], $this->sentNotifications);
		self::assertSame([], $this->notificationDeliveries, 'disabled notifications must not touch the ledger');
	}

	public function testFailedDueNotificationIsRetriedOnTheNextRun(): void {
		$this->now = 1_700_000_000;
		$this->addUser('alice');
		$this->addUser('bob');
		[, $step] = $this->runWithAssignedStep(
			PrincipalType::User->value,
			'bob',
			$this->dayStart('UTC', $this->now) + 86400 + 3600,
		);
		$this->runStepMapper->method('findAssignedStepsDueBetween')->willReturn([$step]);
		$this->failNotifications = 1;

		$service = $this->dueNotificationService();
		self::assertSame(0, $service->processDueTomorrow(), 'a failed delivery is not counted');
		self::assertSame([], $this->sentNotifications);

		self::assertSame(1, $service->processDueTomorrow(), 'the retry succeeds');
		self::assertCount(1, $this->sentNotifications);
		self::assertSame('bob', $this->sentNotifications[0]['user']);
	}
}
