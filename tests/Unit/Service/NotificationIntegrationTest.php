<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunAclRole;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Service\NotificationService;

class NotificationIntegrationTest extends RunTestBase {
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
}
