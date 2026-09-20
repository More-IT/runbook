<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\ActivityEvent;
use OCA\Runbook\Enum\ActivityType;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunAclRole;
use OCA\Runbook\Enum\RunStepStatus;

class RunActivityIntegrationTest extends RunTestBase {
	/**
	 * @return list<string>
	 */
	private function eventTypesFor(int $runId, bool $ascending = true): array {
		$events = array_values(array_filter(
			$this->activityEvents,
			static fn (ActivityEvent $event): bool => $event->getRunId() === $runId,
		));
		usort($events, static function (ActivityEvent $a, ActivityEvent $b) use ($ascending): int {
			$order = [$a->getCreatedAt(), $a->getId()] <=> [$b->getCreatedAt(), $b->getId()];

			return $ascending ? $order : -$order;
		});

		return array_map(static fn (ActivityEvent $event): string => $event->getEventType(), $events);
	}

	public function testRunLifecycleIsRecorded(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');

		$run = $this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Release']);
		$this->runServiceFor('alice')->completeRun($run->getId());
		$this->runServiceFor('alice')->reopenRun($run->getId());
		$this->runServiceFor('alice')->cancelRun($run->getId());

		self::assertSame([
			ActivityType::RunStarted->value,
			ActivityType::RunCompleted->value,
			ActivityType::RunReopened->value,
			ActivityType::RunCancelled->value,
		], $this->eventTypesFor($run->getId()));
	}

	public function testStepLifecycleIsRecorded(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'TEXT', true, RunStepStatus::Pending->value, 0);

		$service = $this->runStepServiceFor('alice');
		$service->start($step->getId());
		$service->update($step->getId(), ['response' => 'draft']);
		$service->complete($step->getId(), ['response' => 'final']);
		$service->reopen($step->getId());
		$service->skip($step->getId(), ['reason' => 'Not applicable']);

		self::assertSame([
			ActivityType::StepStarted->value,
			ActivityType::StepResponseUpdated->value,
			ActivityType::StepCompleted->value,
			ActivityType::StepReopened->value,
			ActivityType::StepSkipped->value,
		], $this->eventTypesFor($run->getId()));
	}

	public function testStepAssignmentChangeIsRecorded(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', false, RunStepStatus::Pending->value, 0);

		$this->runStepServiceFor('alice')->update($step->getId(), [
			'assigneeType' => PrincipalType::User->value,
			'assigneeId' => 'bob',
		]);

		self::assertSame(
			[ActivityType::StepAssignmentChanged->value],
			$this->eventTypesFor($run->getId()),
		);
	}

	public function testRunAclChangeIsRecorded(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');

		$this->runAclServiceFor('alice')->replaceAcl($run->getId(), [
			['principalType' => PrincipalType::User->value, 'principalId' => 'bob', 'role' => RunAclRole::Viewer->value],
		]);

		$events = $this->eventTypesFor($run->getId());
		self::assertSame([ActivityType::RunAclChanged->value], $events);
	}

	public function testViewerCanListActivityThroughRunService(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Viewer->value);

		$this->activityServiceFor('alice')->record($run->getId(), null, ActivityType::RunStarted);

		self::assertCount(1, $this->runServiceFor('bob')->listActivity($run->getId(), null));
	}

	public function testUnrelatedUserCannotListActivity(): void {
		$this->addUser('alice');
		$this->addUser('stranger');
		$run = $this->addRun('alice');

		$this->expectException(\OCA\Runbook\Service\NotFoundException::class);
		$this->runServiceFor('stranger')->listActivity($run->getId(), null);
	}
}
