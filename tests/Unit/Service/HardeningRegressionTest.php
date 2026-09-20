<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\NotificationDelivery;
use OCA\Runbook\Db\Run;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Service\ConflictException;
use OCA\Runbook\Service\NotificationService;

/**
 * Cross-cutting regression tests for M8 hardening.
 *
 * Covers run/step immutability, historical snapshot integrity and the retry
 * behavior of the notification delivery ledger beyond the focused unit suites.
 */
class HardeningRegressionTest extends RunTestBase {
	/**
	 * @return array{0: Run, 1: \OCA\Runbook\Db\RunStep}
	 */
	private function runWithStep(string $status): array {
		$run = $this->addRun('alice', $status);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');

		return [$run, $step];
	}

	public function testCompletedRunIsReadable(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithStep(RunStatus::Completed->value);

		$detail = $this->runServiceFor('alice')->getRunDetail($run->getId());

		self::assertSame($run->getId(), $detail['run']->getId());
		self::assertCount(1, $detail['sections']);
		self::assertSame($step->getId(), $detail['sections'][0]['steps'][0]->getId());
	}

	public function testCancelledRunRejectsStepMutations(): void {
		$this->addUser('alice');
		[, $step] = $this->runWithStep(RunStatus::Cancelled->value);
		$service = $this->runStepServiceFor('alice');

		$this->expectException(ConflictException::class);
		$service->start($step->getId());
	}

	public function testCancelledRunRejectsStepReopen(): void {
		$this->addUser('alice');
		[, $step] = $this->runWithStep(RunStatus::Cancelled->value);

		$this->expectException(ConflictException::class);
		$this->runStepServiceFor('alice')->reopen($step->getId());
	}

	public function testCompletedRunRejectsCommentMutation(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice', RunStatus::Completed->value);

		$this->expectException(ConflictException::class);
		$this->commentServiceFor('alice')->createComment($run->getId(), ['body' => 'late']);
	}

	public function testHistoricalAssigneeAndDueValuesArePreserved(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$dueAt = $this->now + 7200;
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'bob', $dueAt);

		// Change the source template after the run snapshot was created.
		$template = $this->addTemplate('alice');
		$template->setTitle('Renamed template');
		$templateSection = $this->addTemplateSection($template->getId(), 'Changed', 0);
		$this->addTemplateStep($templateSection->getId(), 'Changed step', 'CHECK', true, 0);

		$reloaded = $this->runServiceFor('alice')->requireStep($step->getId());

		self::assertSame('bob', $reloaded->getAssigneeId());
		self::assertSame($dueAt, $reloaded->getDueAt());
	}

	public function testStalePendingDeliveryBecomesRetryable(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'bob');

		$delivery = new NotificationDelivery();
		$delivery->setDedupeKey(NotificationService::SUBJECT_STEP_ASSIGNED . ':' . $run->getId() . ':' . $step->getId() . ':bob');
		$delivery->setType(NotificationService::SUBJECT_STEP_ASSIGNED);
		$delivery->setUserUid('bob');
		$delivery->setRunId($run->getId());
		$delivery->setStepId($step->getId());
		$delivery->setStatus(NotificationDelivery::STATUS_PENDING);
		$delivery->setAttempts(1);
		$delivery->setCreatedAt($this->now - 10000);
		$delivery->setUpdatedAt($this->now - 10000);
		$this->deliveryMapper->insert($delivery);

		$delivered = $this->notificationService->notifyStepAssigned($run, $step, 'alice');

		self::assertSame(1, $delivered);
		self::assertCount(1, $this->sentNotifications);
	}

	public function testEmptyAssignedWorkReturnsEmptyList(): void {
		$this->addUser('alice');

		self::assertSame([], $this->workServiceFor('alice')->myWork('all'));
	}
}
