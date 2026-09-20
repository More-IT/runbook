<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Service\ValidationException;

class WorkServiceTest extends RunTestBase {
	/**
	 * @return array{0: \OCA\Runbook\Db\Run, 1: \OCA\Runbook\Db\RunStep}
	 */
	private function assignedStep(string $owner, string $assigneeType, string $assigneeId, string $status = RunStepStatus::Pending->value, ?int $dueAt = null): array {
		$run = $this->addRun($owner);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'TEXT', false, $status, 0, [], $assigneeType, $assigneeId, $dueAt);

		return [$run, $step];
	}

	public function testDirectAssignmentAppearsInMyWork(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step] = $this->assignedStep('alice', PrincipalType::User->value, 'bob');

		$work = $this->workServiceFor('bob')->myWork('all');

		self::assertCount(1, $work);
		self::assertSame($run->getId(), $work[0]['run']->getId());
		self::assertSame($step->getId(), $work[0]['step']->getId());
	}

	public function testGroupAssignmentAppearsInMyWork(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addGroup('engineering');
		$this->joinGroup('bob', 'engineering');
		$this->assignedStep('alice', PrincipalType::Group->value, 'engineering');

		self::assertCount(1, $this->workServiceFor('bob')->myWork('all'));
	}

	public function testUnassignedWorkIsNotIncluded(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->assignedStep('alice', PrincipalType::User->value, 'carol');

		self::assertSame([], $this->workServiceFor('bob')->myWork('all'));
	}

	public function testOverdueFilter(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->assignedStep('alice', PrincipalType::User->value, 'bob', RunStepStatus::Pending->value, 500);

		$overdue = $this->workServiceFor('bob')->myWork('overdue');

		self::assertCount(1, $overdue);
		self::assertTrue($overdue[0]['overdue']);
	}

	public function testTodayFilter(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->assignedStep('alice', PrincipalType::User->value, 'bob', RunStepStatus::Pending->value, 3600);

		$today = $this->workServiceFor('bob')->myWork('today');

		self::assertCount(1, $today);
		self::assertTrue($today[0]['dueToday']);
		self::assertFalse($today[0]['overdue']);
	}

	public function testUpcomingFilter(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->assignedStep('alice', PrincipalType::User->value, 'bob', RunStepStatus::Pending->value, 90000);

		self::assertCount(1, $this->workServiceFor('bob')->myWork('upcoming'));
		self::assertCount(0, $this->workServiceFor('bob')->myWork('today'));
	}

	public function testCompletedFilter(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step] = $this->assignedStep('alice', PrincipalType::User->value, 'bob', RunStepStatus::Completed->value);
		$step->setCompletedAt($this->now);
		$run->setStatus(RunStatus::Completed->value);

		$completed = $this->workServiceFor('bob')->myWork('completed');

		self::assertCount(1, $completed);
		self::assertSame($step->getId(), $completed[0]['step']->getId());
	}

	public function testCompletedWorkExcludedFromActiveFilters(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->assignedStep('alice', PrincipalType::User->value, 'bob', RunStepStatus::Completed->value);

		self::assertSame([], $this->workServiceFor('bob')->myWork('all'));
	}

	public function testSkippedStepIsResolvedButNotActiveWork(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$skipped = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Skipped->value, 0, [], PrincipalType::User->value, 'bob');

		$all = $this->workServiceFor('bob')->myWork('all');
		$completed = $this->workServiceFor('bob')->myWork('completed');

		self::assertSame([], $all, 'Skipped steps must not count as active work');
		self::assertCount(1, $completed);
		self::assertSame($skipped->getId(), $completed[0]['step']->getId());
	}

	public function testInvalidFilterIsRejected(): void {
		$this->addUser('bob');

		$this->expectException(ValidationException::class);
		$this->workServiceFor('bob')->myWork('someday');
	}

	public function testOverviewCounters(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->assignedStep('alice', PrincipalType::User->value, 'bob', RunStepStatus::Pending->value, 500);
		$this->addRun('alice');

		$overview = $this->workServiceFor('bob')->overview();

		self::assertSame(1, $overview['activeRuns']);
		self::assertSame(1, $overview['assignedActiveSteps']);
		self::assertSame(1, $overview['overdue']);
		self::assertCount(1, $overview['assignedWork']);
		self::assertCount(1, $overview['recentRuns']);
	}
}
