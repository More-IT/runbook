<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\NotificationService;

class DueNotificationServiceTest extends RunTestBase {
	/**
	 * @return array{0: Run, 1: RunStep}
	 */
	private function runWithStep(
		string $assigneeType,
		string $assigneeId,
		?int $dueAt,
		string $runStatus = RunStatus::Active->value,
		string $stepStatus = RunStepStatus::Pending->value,
	): array {
		$run = $this->addRun('alice', $runStatus);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep(
			$section->getId(),
			'CHECK',
			true,
			$stepStatus,
			0,
			[],
			$assigneeType,
			$assigneeId,
			$dueAt,
		);

		return [$run, $step];
	}

	private function dayStart(string $timezone, int $now): int {
		return (new \DateTimeImmutable('@' . $now))
			->setTimezone(new \DateTimeZone($timezone))
			->setTime(0, 0, 0)
			->getTimestamp();
	}

	public function testDueWithinLocalTomorrowNotifies(): void {
		$this->now = 1_700_000_000;
		$this->addUser('alice');
		$this->addUser('bob');
		$dueAt = $this->dayStart('UTC', $this->now) + 86400 + 3600;
		[$run, $step] = $this->runWithStep(PrincipalType::User->value, 'bob', $dueAt);
		$this->runStepMapper->method('findAssignedStepsDueBetween')->willReturn([$step]);

		$delivered = $this->dueNotificationService()->processDueTomorrow();

		self::assertSame(1, $delivered);
		self::assertCount(1, $this->sentNotifications);
		self::assertSame('bob', $this->sentNotifications[0]['user']);
		self::assertSame(NotificationService::SUBJECT_STEP_DUE, $this->sentNotifications[0]['subject']);
	}

	public function testDueTodayDoesNotNotify(): void {
		$this->now = 1_700_000_000;
		$this->addUser('alice');
		$this->addUser('bob');
		$dueAt = $this->dayStart('UTC', $this->now) + 3600;
		[$run, $step] = $this->runWithStep(PrincipalType::User->value, 'bob', $dueAt);
		$this->runStepMapper->method('findAssignedStepsDueBetween')->willReturn([$step]);

		self::assertSame(0, $this->dueNotificationService()->processDueTomorrow());
		self::assertSame([], $this->sentNotifications);
	}

	public function testGroupMembersAreEvaluatedInTheirOwnTimezone(): void {
		$this->now = 1_700_000_000;
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addUser('carol');
		$this->addGroup('engineering');
		$this->joinGroup('bob', 'engineering');
		$this->joinGroup('carol', 'engineering');
		$this->addUserTimezone('bob', 'UTC');
		$this->addUserTimezone('carol', 'Pacific/Kiritimati');

		// 05:00 UTC on the UTC-tomorrow: still "today" for the +14 user.
		$dueAt = $this->dayStart('UTC', $this->now) + 86400 + 5 * 3600;
		[$run, $step] = $this->runWithStep(PrincipalType::Group->value, 'engineering', $dueAt);
		$this->runStepMapper->method('findAssignedStepsDueBetween')->willReturn([$step]);

		$delivered = $this->dueNotificationService()->processDueTomorrow();

		self::assertSame(1, $delivered);
		self::assertCount(1, $this->sentNotifications);
		self::assertSame('bob', $this->sentNotifications[0]['user']);
	}

	public function testUserWithoutTimezoneUsesServerFallback(): void {
		$this->now = 1_700_000_000;
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addUserTimezone('bob', '');
		$fallback = date_default_timezone_get();
		$dueAt = $this->dayStart($fallback, $this->now) + 86400 + 3600;
		[$run, $step] = $this->runWithStep(PrincipalType::User->value, 'bob', $dueAt);
		$this->runStepMapper->method('findAssignedStepsDueBetween')->willReturn([$step]);

		self::assertSame(1, $this->dueNotificationService()->processDueTomorrow());
	}

	public function testOverdueUsesLocalTodayBoundary(): void {
		$this->now = 1_700_000_000;
		$this->addUser('alice');
		$this->addUser('bob');
		$dueAt = $this->dayStart('UTC', $this->now) - 60;
		[$run, $step] = $this->runWithStep(PrincipalType::User->value, 'bob', $dueAt);
		$this->runStepMapper->method('findAssignedStepsOverdue')->willReturn([$step]);

		self::assertSame(1, $this->dueNotificationService()->processOverdue());
		self::assertSame(NotificationService::SUBJECT_STEP_OVERDUE, $this->sentNotifications[0]['subject']);
	}

	public function testNotYetOverdueTodayDoesNotNotify(): void {
		$this->now = 1_700_000_000;
		$this->addUser('alice');
		$this->addUser('bob');
		$dueAt = $this->dayStart('UTC', $this->now) + 60;
		[$run, $step] = $this->runWithStep(PrincipalType::User->value, 'bob', $dueAt);
		$this->runStepMapper->method('findAssignedStepsOverdue')->willReturn([$step]);

		self::assertSame(0, $this->dueNotificationService()->processOverdue());
	}

	public function testOverdueIsDeduplicatedPerRecipient(): void {
		$this->now = 1_700_000_000;
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addUser('carol');
		$this->addGroup('engineering');
		$this->joinGroup('bob', 'engineering');
		$this->joinGroup('carol', 'engineering');
		$dueAt = $this->dayStart('UTC', $this->now) - 60;
		[$run, $step] = $this->runWithStep(PrincipalType::Group->value, 'engineering', $dueAt);
		$this->runStepMapper->method('findAssignedStepsOverdue')->willReturn([$step]);

		$service = $this->dueNotificationService();
		self::assertSame(2, $service->processOverdue());
		self::assertSame(0, $service->processOverdue());
		self::assertCount(2, $this->sentNotifications);
	}

	public function testFailedDueNotificationIsRetriedOnLaterRun(): void {
		$this->now = 1_700_000_000;
		$this->addUser('alice');
		$this->addUser('bob');
		$dueAt = $this->dayStart('UTC', $this->now) + 86400 + 3600;
		[$run, $step] = $this->runWithStep(PrincipalType::User->value, 'bob', $dueAt);
		$this->runStepMapper->method('findAssignedStepsDueBetween')->willReturn([$step]);

		$this->failNotifications = 1;
		$service = $this->dueNotificationService();
		self::assertSame(0, $service->processDueTomorrow());
		self::assertSame([], $this->sentNotifications);

		// A later job run retries the failed delivery.
		self::assertSame(1, $service->processDueTomorrow());
		self::assertCount(1, $this->sentNotifications);
	}

	public function testCompletedStepProducesNoNotification(): void {
		$this->now = 1_700_000_000;
		$this->addUser('alice');
		$this->addUser('bob');
		$dueAt = $this->dayStart('UTC', $this->now) + 86400 + 3600;
		[, $step] = $this->runWithStep(PrincipalType::User->value, 'bob', $dueAt, RunStatus::Active->value, RunStepStatus::Completed->value);
		$this->runStepMapper->method('findAssignedStepsDueBetween')->willReturn([$step]);

		self::assertSame(0, $this->dueNotificationService()->processDueTomorrow());
		self::assertSame([], $this->sentNotifications);
	}

	public function testCancelledRunProducesNoNotification(): void {
		$this->now = 1_700_000_000;
		$this->addUser('alice');
		$this->addUser('bob');
		$dueAt = $this->dayStart('UTC', $this->now) - 60;
		[, $step] = $this->runWithStep(PrincipalType::User->value, 'bob', $dueAt, RunStatus::Cancelled->value, RunStepStatus::Pending->value);
		$this->runStepMapper->method('findAssignedStepsOverdue')->willReturn([$step]);

		self::assertSame(0, $this->dueNotificationService()->processOverdue());
	}

	public function testDisabledNotificationsSkipBackgroundDelivery(): void {
		$this->now = 1_700_000_000;
		$this->addUser('alice');
		$this->addUser('bob');
		$dueAt = $this->dayStart('UTC', $this->now) + 86400 + 3600;
		[, $step] = $this->runWithStep(PrincipalType::User->value, 'bob', $dueAt);
		$this->runStepMapper->expects(self::never())->method('findAssignedStepsDueBetween');
		$this->setAppConfig(AdminSettings::KEY_NOTIFICATIONS_ENABLED, false);

		self::assertSame(0, $this->dueNotificationService()->processDueTomorrow());
		self::assertSame([], $this->sentNotifications);
	}

	public function testBatchQueriesAreBounded(): void {
		$captured = [];
		$this->runStepMapper->method('findAssignedStepsDueBetween')->willReturnCallback(
			function (int $from, int $to, int $limit) use (&$captured): array {
				$captured[] = $limit;

				return [];
			},
		);
		$this->runStepMapper->method('findAssignedStepsOverdue')->willReturnCallback(
			function (int $after, int $before, int $limit) use (&$captured): array {
				$captured[] = $limit;

				return [];
			},
		);

		$this->dueNotificationService()->process();

		self::assertSame([200, 200], $captured);
	}

	public function testProcessRunsBothScans(): void {
		$this->runStepMapper->expects(self::once())->method('findAssignedStepsDueBetween')->willReturn([]);
		$this->runStepMapper->expects(self::once())->method('findAssignedStepsOverdue')->willReturn([]);

		$this->dueNotificationService()->process();
	}
}
