<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\ActivityEvent;
use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunSection;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Enum\AclRole;
use OCA\Runbook\Enum\ActivityType;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunAclRole;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Enum\TemplateStatus;
use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\ConflictException;
use OCA\Runbook\Service\ForbiddenException;
use OCA\Runbook\Service\NotFoundException;
use OCA\Runbook\Service\ValidationException;

class RunServiceTest extends RunTestBase {
	public function testStartRunFromPublishedTemplate(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');

		$run = $this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Release 1']);

		self::assertSame('Release 1', $run->getTitle());
		self::assertSame('alice', $run->getOwner());
		self::assertSame(RunStatus::Active->value, $run->getStatus());
		self::assertSame($template->getId(), $run->getTemplateId());
		self::assertSame($template->getVersion(), $run->getTemplateVersion());
		self::assertSame($this->now, $run->getStartedAt());
	}

	public function testStartRunRejectsDraftTemplate(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice', TemplateStatus::Draft->value);

		$this->expectException(ConflictException::class);
		$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run']);
	}

	public function testStartRunRejectsArchivedTemplate(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice', TemplateStatus::Archived->value);

		$this->expectException(ConflictException::class);
		$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run']);
	}

	public function testStartRunRejectsUserWithoutExecutionPermission(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$template = $this->addTemplate('alice');

		$this->expectException(ForbiddenException::class);
		$this->runServiceFor('bob')->startRun($template->getId(), ['title' => 'Run']);
	}

	public function testStartRunAllowedForAclExecutor(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$template = $this->addTemplate('alice');
		$this->seedAcl($template->getId(), PrincipalType::User->value, 'bob', AclRole::Executor->value);

		$run = $this->runServiceFor('bob')->startRun($template->getId(), ['title' => 'Run']);

		self::assertSame('bob', $run->getOwner());
		self::assertSame(RunStatus::Active->value, $run->getStatus());
	}

	public function testStartRunRequiresTitle(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');

		$this->expectException(ValidationException::class);
		$this->runServiceFor('alice')->startRun($template->getId(), ['title' => '   ']);
	}

	public function testStartRunCopiesSectionsAndStepsIntoAnIndependentSnapshot(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$step = $this->addTemplateStep($section->getId(), 'Check backups', 'CHECK', true, 0);

		$run = $this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run']);

		self::assertCount(1, $this->runSections);
		self::assertCount(1, $this->runSteps);
		$runSection = array_values($this->runSections)[0];
		$runStep = array_values($this->runSteps)[0];
		self::assertSame($run->getId(), $runSection->getRunId());
		self::assertSame($section->getId(), $runSection->getSourceSectionId());
		self::assertSame('Prep', $runSection->getTitle());
		self::assertSame($runSection->getId(), $runStep->getRunSectionId());
		self::assertSame($step->getId(), $runStep->getSourceStepId());
		self::assertSame('Check backups', $runStep->getTitle());
		self::assertSame('CHECK', $runStep->getType());
		self::assertTrue($runStep->getRequired());
		self::assertSame(RunStepStatus::Pending->value, $runStep->getStatus());
	}

	public function testSnapshotPreservesSourceTemplateVersion(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice', TemplateStatus::Published->value, 7);

		$run = $this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run']);

		self::assertSame(7, $run->getTemplateVersion());
	}

	public function testTemplateChangesDoNotAffectExistingRun(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$step = $this->addTemplateStep($section->getId(), 'Check backups', 'CHECK', true, 0);

		$run = $this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run']);

		$step->setTitle('Changed after start');
		$template->setTitle('Changed template');

		$runStep = array_values($this->runSteps)[0];
		self::assertSame('Check backups', $runStep->getTitle());
		self::assertSame('Run', $run->getTitle());
	}

	public function testListRunsOnlyReturnsOwnedRuns(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addRun('alice');
		$this->addRun('bob');

		$aliceRuns = $this->runServiceFor('alice')->listRuns();
		$bobRuns = $this->runServiceFor('bob')->listRuns();

		self::assertCount(1, $aliceRuns);
		self::assertSame('alice', $aliceRuns[0]['run']->getOwner());
		self::assertCount(1, $bobRuns);
		self::assertSame('bob', $bobRuns[0]['run']->getOwner());
	}

	public function testNonOwnerCannotViewRun(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');

		$this->expectException(NotFoundException::class);
		$this->runServiceFor('bob')->getRun($run->getId());
	}

	public function testProgressCalculation(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Completed->value, 0);
		$this->addRunStep($section->getId(), 'TEXT', true, RunStepStatus::Skipped->value, 1);
		$this->addRunStep($section->getId(), 'TEXT', false, RunStepStatus::Pending->value, 2);

		$progress = $this->runServiceFor('alice')->getRunDetail($run->getId())['progress'];

		self::assertSame(3, $progress['total']);
		self::assertSame(1, $progress['completed']);
		self::assertSame(1, $progress['skipped']);
		self::assertSame(1, $progress['pending']);
		self::assertSame(66, $progress['percentage']);
		self::assertTrue($progress['canComplete']);
	}

	public function testProgressForEmptyRunIsComplete(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');

		$progress = $this->runServiceFor('alice')->getRunDetail($run->getId())['progress'];

		self::assertSame(0, $progress['total']);
		self::assertSame(0, $progress['pending']);
		self::assertSame(100, $progress['percentage']);
		self::assertTrue($progress['canComplete']);
	}

	public function testProgressCountsSkippedAsResolvedNotPending(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Completed->value, 0);
		$this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Skipped->value, 1);
		$this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Skipped->value, 2);
		$this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 3);

		$progress = $this->runServiceFor('alice')->getRunDetail($run->getId())['progress'];

		self::assertSame(4, $progress['total']);
		self::assertSame(1, $progress['completed']);
		self::assertSame(2, $progress['skipped']);
		self::assertSame(1, $progress['pending']);
		self::assertSame(75, $progress['percentage']);
		// A required pending step blocks completion; skipped required steps do not.
		self::assertFalse($progress['canComplete']);
	}

	public function testProgressAllSkippedIsFullyResolved(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Skipped->value, 0);
		$this->addRunStep($section->getId(), 'TEXT', false, RunStepStatus::Skipped->value, 1);

		$progress = $this->runServiceFor('alice')->getRunDetail($run->getId())['progress'];

		self::assertSame(2, $progress['skipped']);
		self::assertSame(0, $progress['pending']);
		self::assertSame(100, $progress['percentage']);
		self::assertTrue($progress['canComplete']);
	}

	public function testProgressAllCompletedIsFullyResolved(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Completed->value, 0);
		$this->addRunStep($section->getId(), 'TEXT', false, RunStepStatus::Completed->value, 1);

		$progress = $this->runServiceFor('alice')->getRunDetail($run->getId())['progress'];

		self::assertSame(2, $progress['completed']);
		self::assertSame(0, $progress['pending']);
		self::assertSame(100, $progress['percentage']);
		self::assertTrue($progress['canComplete']);
	}

	public function testCompleteRunRejectsUnresolvedRequiredSteps(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0);

		$this->expectException(ConflictException::class);
		$this->runServiceFor('alice')->completeRun($run->getId());
	}

	public function testCompleteRun(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0);
		$step->setStatus(RunStepStatus::Completed->value);
		$step->setCompletedAt($this->now);

		$completed = $this->runServiceFor('alice')->completeRun($run->getId());

		self::assertSame(RunStatus::Completed->value, $completed->getStatus());
		self::assertSame($this->now, $completed->getCompletedAt());
		self::assertSame('alice', $completed->getCompletedBy());
	}

	public function testCancelRun(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');

		$cancelled = $this->runServiceFor('alice')->cancelRun($run->getId());

		self::assertSame(RunStatus::Cancelled->value, $cancelled->getStatus());
		self::assertSame($this->now, $cancelled->getCancelledAt());
		self::assertSame('alice', $cancelled->getCancelledBy());
	}

	public function testCannotCancelCompletedRun(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice', RunStatus::Completed->value);

		$this->expectException(ConflictException::class);
		$this->runServiceFor('alice')->cancelRun($run->getId());
	}

	public function testReopenCompletedRun(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice', RunStatus::Completed->value);
		$run->setCompletedAt(500);
		$run->setCompletedBy('alice');
		$this->now = 2000;

		$reopened = $this->runServiceFor('alice')->reopenRun($run->getId());

		self::assertSame(RunStatus::Active->value, $reopened->getStatus());
		self::assertSame(2000, $reopened->getReopenedAt());
		// Historical completion data is preserved.
		self::assertSame(500, $reopened->getCompletedAt());
		self::assertSame('alice', $reopened->getCompletedBy());
	}

	public function testRunReopenDisabledRejectsReopen(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice', RunStatus::Completed->value);
		$this->setAppConfig(AdminSettings::KEY_RUN_REOPEN_ENABLED, false);

		$this->expectException(ForbiddenException::class);
		$this->runServiceFor('alice')->reopenRun($run->getId());
	}

	public function testCannotReopenCancelledRun(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice', RunStatus::Cancelled->value);

		$this->expectException(ConflictException::class);
		$this->runServiceFor('alice')->reopenRun($run->getId());
	}

	public function testNonOwnerCannotMutateRun(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');

		$this->expectException(NotFoundException::class);
		$this->runServiceFor('bob')->cancelRun($run->getId());
	}

	public function testDeletingSourceTemplateKeepsHistoricalRun(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check backups', 'CHECK', true, 0);

		$run = $this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run']);
		$runStepCount = count($this->runSteps);

		// Simulate deleting the source template (sections/steps cascade away).
		$this->templateMapper->delete($template);

		self::assertSame([], $this->templates);
		self::assertSame([], $this->templateSections);
		self::assertArrayHasKey($run->getId(), $this->runs);
		self::assertCount($runStepCount, $this->runSteps);
		self::assertInstanceOf(RunStep::class, array_values($this->runSteps)[0]);
		self::assertInstanceOf(Run::class, $this->runs[$run->getId()]);
	}

	public function testStartRunCopiesDefaultAssigneeAndDueOffset(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0, [], 'principals/users/bob', '60');

		$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run']);

		$runStep = array_values($this->runSteps)[0];
		self::assertSame(PrincipalType::User->value, $runStep->getAssigneeType());
		self::assertSame('bob', $runStep->getAssigneeId());
		self::assertSame($this->now + 3600, $runStep->getDueAt());
	}

	public function testStartRunAssignsUnassignedStepsToStarter(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Unassigned', 'CHECK', true, 0);

		$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run']);

		$runStep = array_values($this->runSteps)[0];
		self::assertSame(PrincipalType::User->value, $runStep->getAssigneeType());
		self::assertSame('alice', $runStep->getAssigneeId());
	}

	public function testAutoAssignedStepAppearsInMyWork(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Unassigned', 'CHECK', true, 0);

		$run = $this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run']);
		$runStep = array_values($this->runSteps)[0];

		$work = $this->workServiceFor('alice')->myWork('all');

		self::assertCount(1, $work);
		self::assertSame($run->getId(), $work[0]['run']->getId());
		self::assertSame($runStep->getId(), $work[0]['step']->getId());
		self::assertSame('alice', $work[0]['step']->getAssigneeId());
	}

	public function testStartRunPreservesConfiguredAssigneeAndAssignsOthers(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Assigned', 'CHECK', true, 0, [], 'principals/users/bob');
		$this->addTemplateStep($section->getId(), 'Unassigned', 'TEXT', false, 1);

		$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run']);

		$steps = array_values($this->runSteps);
		usort($steps, static fn (RunStep $a, RunStep $b): int => $a->getPosition() <=> $b->getPosition());
		self::assertSame('bob', $steps[0]->getAssigneeId());
		self::assertSame(PrincipalType::User->value, $steps[0]->getAssigneeType());
		self::assertSame('alice', $steps[1]->getAssigneeId());
	}

	public function testStartRunRecordsAutomaticAssignmentInActivity(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Unassigned', 'CHECK', true, 0);

		$run = $this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run']);

		$assignmentEvents = array_values(array_filter(
			$this->activityEvents,
			static fn (ActivityEvent $event): bool => $event->getRunId() === $run->getId()
				&& $event->getEventType() === ActivityType::StepAssignmentChanged->value,
		));

		self::assertCount(1, $assignmentEvents);
		self::assertNotNull($assignmentEvents[0]->getStepId());
		self::assertSame('alice', $assignmentEvents[0]->getActorUid());
		self::assertSame('alice', $assignmentEvents[0]->getMetadataArray()['assigneeId']);
		self::assertTrue($assignmentEvents[0]->getMetadataArray()['automatic']);
	}

	public function testStartRunSnapshotPreservesNumberUnit(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Wait', 'NUMBER', false, 0, ['unit' => 'minutes']);

		$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run']);

		$runStep = array_values($this->runSteps)[0];
		self::assertSame(['unit' => 'minutes'], $runStep->getConfigArray());
	}

	public function testStartRunSnapshotPreservesMissingNumberUnit(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Count', 'NUMBER', false, 0);

		$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run']);

		$runStep = array_values($this->runSteps)[0];
		self::assertSame([], $runStep->getConfigArray());
	}

	public function testStartRunSnapshotCopiesSectionNotes(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$section->setNotes('Bring the spare key.');
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);

		$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run']);

		$runSection = array_values($this->runSections)[0];
		self::assertSame('Bring the spare key.', $runSection->getNotes());
	}

	public function testUpdateSectionNotesByOwner(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);

		$updated = $this->runServiceFor('alice')->updateSectionNotes($section->getId(), ['notes' => 'Handover at 18:00']);

		self::assertSame('Handover at 18:00', $updated->getNotes());
	}

	public function testUpdateSectionNotesRecordsActivity(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);

		$this->runServiceFor('alice')->updateSectionNotes($section->getId(), ['notes' => 'Note']);

		$events = array_values(array_filter(
			$this->activityEvents,
			static fn (ActivityEvent $event): bool => $event->getEventType() === ActivityType::SectionNotesUpdated->value,
		));
		self::assertCount(1, $events);
		self::assertSame($section->getId(), $events[0]->getMetadataArray()['sectionId']);
	}

	public function testUpdateSectionNotesRejectedForNonOwner(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);

		$this->expectException(NotFoundException::class);
		$this->runServiceFor('bob')->updateSectionNotes($section->getId(), ['notes' => 'x']);
	}

	public function testUpdateSectionNotesRejectedWhenRunNotActive(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice', RunStatus::Completed->value);
		$section = $this->addRunSection($run->getId(), 0);

		$this->expectException(ConflictException::class);
		$this->runServiceFor('alice')->updateSectionNotes($section->getId(), ['notes' => 'x']);
	}

	public function testUpdateSectionNotesTooLong(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);

		$this->expectException(ValidationException::class);
		$this->runServiceFor('alice')->updateSectionNotes($section->getId(), ['notes' => str_repeat('a', 10001)]);
	}

	public function testStartRunIgnoresInvalidLegacyDueOffset(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0, [], null, 'soon');

		$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run']);

		self::assertNull(array_values($this->runSteps)[0]->getDueAt());
	}

	public function testStartRunPersistsRunDueDate(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');
		$dueAt = $this->now + 100000;

		$run = $this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run', 'dueAt' => $dueAt]);

		self::assertSame($dueAt, $run->getDueAt());
	}

	public function testStartRunRejectsStepDueAfterRunDue(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0, [], null, '60');

		$this->expectException(ValidationException::class);
		$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run', 'dueAt' => $this->now + 600]);
	}

	public function testListRunsIncludesParticipantRuns(): void {
		$this->addUser('alice');
		$owned = $this->addRun('alice');
		$shared = $this->addRun('carol');
		$this->seedRunAcl($shared->getId(), PrincipalType::User->value, 'alice', RunAclRole::Participant->value);

		$ids = array_map(
			static fn (array $entry): int => $entry['run']->getId(),
			$this->runServiceFor('alice')->listRuns(),
		);

		self::assertContains($owned->getId(), $ids);
		self::assertContains($shared->getId(), $ids);
	}

	public function testParticipantCanViewRun(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Participant->value);

		self::assertSame($run->getId(), $this->runServiceFor('bob')->getRun($run->getId())->getId());
	}

	public function testStepAssigneeCanViewRun(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$this->addRunStep($section->getId(), 'TEXT', false, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'bob');

		self::assertSame($run->getId(), $this->runServiceFor('bob')->getRun($run->getId())->getId());
	}

	public function testOwnerCanDeleteActiveRun(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');

		$this->runServiceFor('alice')->deleteRun($run->getId());

		self::assertArrayNotHasKey($run->getId(), $this->runs);
	}

	public function testOwnerCanDeleteCompletedRun(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice', RunStatus::Completed->value);

		$this->runServiceFor('alice')->deleteRun($run->getId());

		self::assertArrayNotHasKey($run->getId(), $this->runs);
	}

	public function testOwnerCanDeleteCancelledRun(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice', RunStatus::Cancelled->value);

		$this->runServiceFor('alice')->deleteRun($run->getId());

		self::assertArrayNotHasKey($run->getId(), $this->runs);
	}

	public function testParticipantCannotDeleteRun(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Participant->value);

		$this->expectException(NotFoundException::class);
		$this->runServiceFor('bob')->deleteRun($run->getId());
	}

	public function testAdminCanDeleteAnyRun(): void {
		$this->addUser('alice');
		$this->addUser('admin');
		$this->adminUsers[] = 'admin';
		$run = $this->addRun('alice');

		$this->runServiceFor('admin')->deleteRun($run->getId());

		self::assertArrayNotHasKey($run->getId(), $this->runs);
	}

	public function testDeleteRunRemovesEvidenceFiles(): void {
		$this->addUser('alice');
		// A legacy run with a pre-existing AppData attachment (compatibility path).
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'FILE', false, RunStepStatus::Pending->value, 0);
		$this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'evidence', 'alice');

		self::assertNotSame([], $this->evidenceFiles);

		$this->runServiceFor('alice')->deleteRun($run->getId());

		self::assertSame([], $this->evidenceFiles);
	}

	public function testDeleteRunRemovesFilesEvidence(): void {
		$this->addUser('alice');
		[$run, $folder] = $this->addFilesRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', false, RunStepStatus::Pending->value, 0);
		$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'evidence']);

		self::assertCount(1, $folder->getDirectoryListing());

		$this->runServiceFor('alice')->deleteRun($run->getId());

		self::assertCount(0, $folder->getDirectoryListing(), 'Files evidence is removed with the run');
		self::assertSame([], $this->evidenceFiles);
	}

	public function testNonOwnerCannotDeleteMissingRun(): void {
		$this->addUser('alice');
		$this->addUser('bob');

		$this->expectException(NotFoundException::class);
		$this->runServiceFor('bob')->deleteRun(999999);
	}

	public function testStartRunSnapshotMapsFlowConfiguration(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');
		$first = $this->addTemplateSection($template->getId(), 'A', 0);
		$second = $this->addTemplateSection($template->getId(), 'B', 1);
		$second->setDependsOnIds([$first->getId()]);
		$control = $this->addTemplateStep($first->getId(), 'Approved?', 'CONFIRMATION', true, 0);
		$second->setCondition(['stepId' => $control->getId(), 'operator' => 'is_true']);

		$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run']);

		$runSections = array_values($this->runSections);
		usort($runSections, static fn (RunSection $a, RunSection $b): int => $a->getPosition() <=> $b->getPosition());
		self::assertSame([$runSections[0]->getId()], $runSections[1]->getDependsOnIds());

		$runControl = null;
		foreach ($this->runSteps as $runStep) {
			if ($runStep->getSourceStepId() === $control->getId()) {
				$runControl = $runStep;
			}
		}
		self::assertNotNull($runControl);
		$condition = $runSections[1]->getCondition();
		self::assertNotNull($condition);
		self::assertSame($runControl->getId(), $condition['stepId']);
	}

	public function testStartRunSnapshotMapsEveryCondition(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');
		$first = $this->addTemplateSection($template->getId(), 'A', 0);
		$control = $this->addTemplateStep($first->getId(), 'Approved?', 'CONFIRMATION', true, 0);
		$amount = $this->addTemplateStep($first->getId(), 'Amount', 'NUMBER', true, 1);
		$second = $this->addTemplateSection($template->getId(), 'B', 1);
		$second->setConditions([
			['stepId' => $control->getId(), 'operator' => 'is_true'],
			['stepId' => $amount->getId(), 'operator' => 'greater_than', 'value' => 5.0],
		]);

		$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Run']);

		$runSections = array_values($this->runSections);
		usort($runSections, static fn (RunSection $a, RunSection $b): int => $a->getPosition() <=> $b->getPosition());
		$runStepsBySource = [];
		foreach ($this->runSteps as $runStep) {
			$sourceStepId = $runStep->getSourceStepId();
			self::assertNotNull($sourceStepId);
			$runStepsBySource[$sourceStepId] = $runStep;
		}

		$conditions = $runSections[1]->getConditions();
		self::assertCount(2, $conditions);
		self::assertSame($runStepsBySource[$control->getId()]->getId(), $conditions[0]['stepId']);
		self::assertSame($runStepsBySource[$amount->getId()]->getId(), $conditions[1]['stepId']);
		self::assertSame('greater_than', $conditions[1]['operator']);
		self::assertEquals(5.0, $conditions[1]['value']);
	}

	public function testBlockedSectionStateAndExecutableIds(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$a = $this->addRunSection($run->getId(), 0);
		$b = $this->addRunSection($run->getId(), 1);
		$b->setDependsOnIds([$a->getId()]);
		$aStep = $this->addRunStep($a->getId(), 'TEXT', true, RunStepStatus::Pending->value, 0);
		$bStep = $this->addRunStep($b->getId(), 'TEXT', true, RunStepStatus::Pending->value, 1);

		$detail = $this->runServiceFor('alice')->getRunDetail($run->getId());

		self::assertSame('available', $detail['sections'][0]['state']);
		self::assertSame('blocked', $detail['sections'][1]['state']);
		self::assertNotSame([], $detail['sections'][1]['blockedBy']);
		self::assertContains($aStep->getId(), $detail['permissions']['executableStepIds']);
		self::assertNotContains($bStep->getId(), $detail['permissions']['executableStepIds']);
	}

	public function testBlockedSectionStepCannotBeStarted(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$a = $this->addRunSection($run->getId(), 0);
		$b = $this->addRunSection($run->getId(), 1);
		$b->setDependsOnIds([$a->getId()]);
		$this->addRunStep($a->getId(), 'TEXT', true, RunStepStatus::Pending->value, 0);
		$bStep = $this->addRunStep($b->getId(), 'TEXT', true, RunStepStatus::Pending->value, 1);

		$this->expectException(ConflictException::class);
		$this->runStepServiceFor('alice')->start($bStep->getId());
	}

	public function testInapplicableSectionDoesNotBlockCompletion(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$start = $this->addRunSection($run->getId(), 0);
		$control = $this->addRunStep($start->getId(), 'CONFIRMATION', true, RunStepStatus::Completed->value, 0);
		$control->setResponseValue(false);
		$branch = $this->addRunSection($run->getId(), 1);
		$branch->setCondition(['stepId' => $control->getId(), 'operator' => 'is_true']);
		$this->addRunStep($branch->getId(), 'TEXT', true, RunStepStatus::Pending->value, 1);

		$detail = $this->runServiceFor('alice')->getRunDetail($run->getId());

		self::assertSame('inapplicable', $detail['sections'][1]['state']);
		self::assertSame(0, $detail['progress']['pending']);
		self::assertTrue($detail['progress']['canComplete']);
	}

	public function testReturnSectionReopensStepsAndPreservesResponses(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'TEXT', true, RunStepStatus::Completed->value, 0);
		$step->setResponseValue('answer');

		$returned = $this->runServiceFor('alice')->returnSection($section->getId(), ['reason' => 'Fix typo']);

		self::assertCount(1, $returned);
		self::assertSame(RunStepStatus::Pending->value, $returned[0]->getStatus());
		self::assertSame('answer', $returned[0]->getResponseValue());
	}

	public function testReturnSectionRequiresReason(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$this->addRunStep($section->getId(), 'TEXT', true, RunStepStatus::Completed->value, 0);

		$this->expectException(ValidationException::class);
		$this->runServiceFor('alice')->returnSection($section->getId(), ['reason' => '  ']);
	}

	public function testReturnedResolvedSectionBecomesAvailableAgain(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'TEXT', true, RunStepStatus::Completed->value, 0);
		$step->setResponseValue('answer');

		$before = $this->runServiceFor('alice')->getRunDetail($run->getId());
		self::assertSame('resolved', $before['sections'][0]['state']);

		$this->runServiceFor('alice')->returnSection($section->getId(), ['reason' => 'Fix typo']);

		$after = $this->runServiceFor('alice')->getRunDetail($run->getId());
		self::assertSame('available', $after['sections'][0]['state']);
		self::assertSame([], $after['sections'][0]['reason']);
		self::assertFalse($after['progress']['canComplete']);
	}

	public function testReturnSectionRequiresOwnership(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$this->addRunStep($section->getId(), 'TEXT', true, RunStepStatus::Completed->value, 0);

		// A non-owner is denied; the run is hidden behind a not-found error so
		// the API never leaks another user's run.
		$this->expectException(NotFoundException::class);
		$this->runServiceFor('bob')->returnSection($section->getId(), ['reason' => 'nope']);
	}

	public function testStartRunPersistsDefaultFilesDestination(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);

		$run = $this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Deploy']);

		self::assertSame('default', $run->getDestinationSource());
		self::assertSame('alice', $run->getDestinationViewUid());
		self::assertSame('home::test', $run->getDestinationStorageId());
		self::assertGreaterThan(0, $run->getDestinationFileId());
		self::assertSame(7, $run->getDestinationStorageRootId());
		self::assertSame('local', $run->getDestinationMountType());
		self::assertNull($run->getDestinationMountId(), 'mount id is non-authoritative metadata');
		self::assertSame(1, $run->getDestinationNumericStorageId());
		self::assertGreaterThan(0, $run->getRunFolderFileId());
		self::assertSame('home::test', $run->getRunFolderStorageId());
		self::assertStringStartsWith('Deploy (', (string)$run->getRunFolderPath());
	}

	public function testStartRunPreservesManagedFolderWhenRunInsertFails(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);
		$this->failRunInsert = true;

		try {
			$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Deploy']);
			self::fail('an insert failure must abort the run start');
		} catch (\OCP\DB\Exception) {
		}

		self::assertCount(0, $this->runs, 'no run row is left behind');
		self::assertSame([], $this->deletedFolderIds, 'the folder is preserved, never recursively deleted');
		self::assertNotContains($this->baseFolder()->getId(), $this->deletedFolderIds, 'the base folder is never removed');
		self::assertCount(1, $this->baseFolder()->getDirectoryListing(), 'the folder is preserved as an orphan requiring manual cleanup');
	}

	public function testAFailedStartLeavesAnOrphanFolderThatANewStartDoesNotAdopt(): void {
		// `startRun()` generates a fresh UUID each call, so a later start (even
		// with the same title) must create a new folder and never adopt the one
		// preserved by the failed attempt.
		$this->addUser('alice');
		$this->useDistinctUuids();
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);
		$service = $this->runServiceFor('alice');

		$this->failRunInsert = true;
		try {
			$service->startRun($template->getId(), ['title' => 'Deploy']);
			self::fail('an insert failure must abort the run start');
		} catch (\OCP\DB\Exception) {
		}

		$orphans = $this->baseFolder()->getDirectoryListing();
		self::assertCount(1, $orphans, 'the failed attempt preserved its folder');
		$orphanId = $orphans[0]->getId();

		$this->failRunInsert = false;
		$run = $service->startRun($template->getId(), ['title' => 'Deploy']);

		self::assertNotSame($orphanId, $run->getRunFolderFileId(), 'a new start never adopts the preserved orphan');
		self::assertCount(2, $this->baseFolder()->getDirectoryListing(), 'the orphan and the new folder both exist');
		self::assertSame([], $this->deletedFolderIds, 'nothing is ever deleted');
	}

	public function testExistingRunsRemainLegacy(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');

		self::assertNull($run->getDestinationSource());
		self::assertNull($run->getDestinationViewUid());
		self::assertNull($run->getDestinationStorageId());
		self::assertNull($run->getDestinationFileId());
		self::assertNull($run->getRunFolderFileId());
	}

	public function testStartRunLeavesExistingAppDataAttachmentsUntouched(): void {
		$this->addUser('alice');
		$legacyRun = $this->addRun('alice');
		$legacySection = $this->addRunSection($legacyRun->getId(), 0);
		$legacyStep = $this->addRunStep($legacySection->getId(), 'FILE', false, RunStepStatus::Pending->value, 0);
		// A pre-existing AppData attachment (compatibility path for legacy runs).
		$legacyAttachment = $this->seedAppDataAttachment($legacyRun, $legacyStep->getId(), 'old.txt', 'old', 'alice');
		$attachmentCount = count($this->attachments);
		$legacyStorageKey = $legacyAttachment->getStorageKey();

		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);
		$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'New run']);

		self::assertCount($attachmentCount, $this->attachments, 'existing AppData attachments are unchanged');
		self::assertSame($legacyStorageKey, $this->attachments[$legacyAttachment->getId()]->getStorageKey());
	}

	public function testTwoRunsWithSameTitleGetDistinctManagedFolders(): void {
		$this->addUser('alice');
		$this->useDistinctUuids();
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);
		$service = $this->runServiceFor('alice');

		$first = $service->startRun($template->getId(), ['title' => 'Deploy']);
		$second = $service->startRun($template->getId(), ['title' => 'Deploy']);

		self::assertNotSame($first->getRunFolderFileId(), $second->getRunFolderFileId());
		self::assertNotSame($first->getRunFolderPath(), $second->getRunFolderPath());
		self::assertCount(2, $this->baseFolder()->getDirectoryListing());
	}

	public function testFailedStartDoesNotRemoveAnotherRunsFolder(): void {
		$this->addUser('alice');
		$this->useDistinctUuids();
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);
		$service = $this->runServiceFor('alice');
		$existing = $service->startRun($template->getId(), ['title' => 'Deploy']);
		$this->failRunInsert = true;

		try {
			$service->startRun($template->getId(), ['title' => 'Deploy']);
			self::fail('an insert failure must abort the run start');
		} catch (\OCP\DB\Exception) {
		}

		self::assertCount(1, $this->runs, 'only the pre-existing run remains');
		self::assertSame([], $this->deletedFolderIds, 'no folder is ever removed on a failed start');
		self::assertNotContains($existing->getRunFolderFileId(), $this->deletedFolderIds, 'the other run\'s folder is never removed');
		self::assertTrue($this->baseFolder()->nodeExists((string)$existing->getRunFolderPath()));
	}

	/**
	 * Give every `ISecureRandom::generate()` call a distinct value so generated
	 * run UUIDs differ, as they do in production (the default test double
	 * returns a constant, which would make separate runs collide on one UUID).
	 */
	private function useDistinctUuids(): void {
		$counter = 0;
		$random = $this->createMock(\OCP\Security\ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			static function (int $length, string $characters = '') use (&$counter): string {
				$counter++;
				return str_pad(dechex($counter), $length, '0', STR_PAD_LEFT);
			},
		);
		$this->secureRandom = $random;
	}

	public function testStartRunWithAdminDestinationFreezesSourceAndConfiguredBy(): void {
		$this->addUser('alice');
		$configured = $this->addUserFolder('Shared', 7001);
		$this->setAdminDestination('home::test', 7001, '/Shared', 'admin');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);

		$run = $this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Deploy']);

		self::assertSame('admin', $run->getDestinationSource());
		self::assertSame('admin', $run->getDestinationConfiguredBy());
		self::assertSame(7001, $run->getDestinationFileId());
		self::assertSame('home::test', $run->getDestinationStorageId());
		self::assertCount(1, $configured->getDirectoryListing(), 'the managed folder is created in the configured folder');
		self::assertArrayNotHasKey('Runbook', $this->userRootChildren, 'the default folder is not used');
	}

	public function testStartRunFailsClosedWhenAdminDestinationIsMissingForOwner(): void {
		$this->addUser('alice');
		$this->setAdminDestination('home::test', 9999, '/Shared', 'admin');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);

		try {
			$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Deploy']);
			self::fail('a missing configured destination must abort the run start');
		} catch (ConflictException $exception) {
			self::assertSame('destination_no_access', $exception->getReason());
		}

		self::assertCount(0, $this->runs, 'no run row is created when the destination cannot be resolved');
		self::assertSame([], $this->deletedFolderIds);
	}

	public function testStartRunFailsClosedWhenAdminDestinationStoredStateIsCorrupt(): void {
		$this->addUser('alice');
		$this->setAppConfig(AdminSettings::KEY_DESTINATION_REFERENCE, '{not valid json');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);

		try {
			$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Deploy']);
			self::fail('a corrupt stored destination must abort the run start');
		} catch (ValidationException $exception) {
			self::assertSame('destination_invalid_config', $exception->getReason());
		}

		self::assertCount(0, $this->runs, 'no run row is created and there is no fallback to Files/Runbook');
		self::assertArrayNotHasKey('Runbook', $this->userRootChildren);
	}

	public function testStartRunWithTemplateDestinationFreezesSourceAndConfiguredBy(): void {
		$this->addUser('alice');
		$this->addUserFolder('AdminShared', 7001);
		$templateFolder = $this->addUserFolder('TemplateShared', 7002);
		$this->setAdminDestination('home::test', 7001, '/AdminShared', 'admin');
		$template = $this->addTemplate('alice');
		$this->setTemplateDestination($template, 'home::test', 7002, '/TemplateShared', 'editor');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);

		$run = $this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Deploy']);

		self::assertSame('template', $run->getDestinationSource());
		self::assertSame('editor', $run->getDestinationConfiguredBy());
		self::assertSame(7002, $run->getDestinationFileId());
		self::assertSame('home::test', $run->getDestinationStorageId());
		self::assertCount(1, $templateFolder->getDirectoryListing(), 'the managed folder is created in the template folder');
		self::assertArrayNotHasKey('Runbook', $this->userRootChildren);
	}

	public function testUnsetTemplateDestinationFallsThroughToAdminDestination(): void {
		$this->addUser('alice');
		$adminFolder = $this->addUserFolder('AdminShared', 7001);
		$this->setAdminDestination('home::test', 7001, '/AdminShared', 'admin');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);

		$run = $this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Deploy']);

		self::assertSame('admin', $run->getDestinationSource());
		self::assertSame('admin', $run->getDestinationConfiguredBy());
		self::assertSame(7001, $run->getDestinationFileId());
		self::assertCount(1, $adminFolder->getDirectoryListing());
	}

	public function testConfiguredTemplateDestinationMissingForOwnerFailsClosedWithoutFallthrough(): void {
		$this->addUser('alice');
		$this->addUserFolder('AdminShared', 7001);
		$this->setAdminDestination('home::test', 7001, '/AdminShared', 'admin');
		$template = $this->addTemplate('alice');
		$this->setTemplateDestination($template, 'home::test', 9999, '/Missing', 'editor');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);

		try {
			$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Deploy']);
			self::fail('a missing template destination must abort the run start');
		} catch (ConflictException $exception) {
			self::assertSame('destination_no_access', $exception->getReason());
		}

		self::assertCount(0, $this->runs);
		self::assertArrayNotHasKey('Runbook', $this->userRootChildren, 'no fallback to the admin or default destination');
	}

	public function testPartialTemplateDestinationFailsClosed(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');
		// Only the file id is set: a partial, corrupt reference.
		$template->setDestinationFileId(7002);
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);

		try {
			$this->runServiceFor('alice')->startRun($template->getId(), ['title' => 'Deploy']);
			self::fail('a partial template destination must abort the run start');
		} catch (ValidationException $exception) {
			self::assertSame('destination_invalid_config', $exception->getReason());
		}

		self::assertCount(0, $this->runs);
		self::assertArrayNotHasKey('Runbook', $this->userRootChildren);
	}

	public function testStartRunRuntimeDestinationOverridesTemplateAndAdmin(): void {
		$this->addUser('alice');
		$this->addUserFolder('AdminShared', 7001);
		$templateFolder = $this->addUserFolder('TemplateShared', 7002);
		$runtimeFolder = $this->addUserFolder('RuntimeShared', 7003);
		$this->setAdminDestination('home::test', 7001, '/AdminShared', 'admin');
		$template = $this->addTemplate('alice');
		$this->setTemplateDestination($template, 'home::test', 7002, '/TemplateShared', 'editor');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);

		$run = $this->runServiceFor('alice')->startRun($template->getId(), [
			'title' => 'Deploy',
			'destinationPath' => '/RuntimeShared',
		]);

		self::assertSame('runtime', $run->getDestinationSource());
		self::assertSame('alice', $run->getDestinationConfiguredBy());
		self::assertSame(7003, $run->getDestinationFileId());
		self::assertSame('home::test', $run->getDestinationStorageId());
		self::assertCount(1, $runtimeFolder->getDirectoryListing());
		self::assertCount(0, $templateFolder->getDirectoryListing());
		self::assertArrayNotHasKey('Runbook', $this->userRootChildren);

		// The run-time choice is not written back to the template or admin setting.
		self::assertSame(7002, $template->getDestinationFileId());
		self::assertSame(7001, $this->adminSettings->getDestinationReference()?->fileId);
	}

	public function testStartRunRuntimeIgnoresClientSuppliedIdentity(): void {
		$this->addUser('alice');
		$this->addUserFolder('RuntimeShared', 7003);
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);

		$run = $this->runServiceFor('alice')->startRun($template->getId(), [
			'title' => 'Deploy',
			'destinationPath' => '/RuntimeShared',
			'destinationStorageId' => 'evil::storage',
			'destinationFileId' => 999999,
		]);

		self::assertSame('runtime', $run->getDestinationSource());
		self::assertSame(7003, $run->getDestinationFileId(), 'only the server-captured identity is used');
		self::assertSame('home::test', $run->getDestinationStorageId());
	}

	public function testStartRunRuntimeMissingPathFailsClosedWithoutFallthrough(): void {
		$this->addUser('alice');
		$adminFolder = $this->addUserFolder('AdminShared', 7001);
		$templateFolder = $this->addUserFolder('TemplateShared', 7002);
		$this->setAdminDestination('home::test', 7001, '/AdminShared', 'admin');
		$template = $this->addTemplate('alice');
		$this->setTemplateDestination($template, 'home::test', 7002, '/TemplateShared', 'editor');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);

		try {
			$this->runServiceFor('alice')->startRun($template->getId(), [
				'title' => 'Deploy',
				'destinationPath' => '/Missing',
			]);
			self::fail('a missing run-time destination must abort the run start');
		} catch (ValidationException $exception) {
			self::assertSame('destination_invalid', $exception->getReason());
		}

		self::assertCount(0, $this->runs);
		self::assertCount(0, $adminFolder->getDirectoryListing());
		self::assertCount(0, $templateFolder->getDirectoryListing());
		self::assertArrayNotHasKey('Runbook', $this->userRootChildren, 'no fallback to template, admin or default');
	}

	public function testStartRunRuntimeUnwritableFailsClosed(): void {
		$this->addUser('alice');
		$this->addUserFolder('ReadOnly', 7003, false);
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);

		try {
			$this->runServiceFor('alice')->startRun($template->getId(), [
				'title' => 'Deploy',
				'destinationPath' => '/ReadOnly',
			]);
			self::fail('an unwritable run-time destination must abort the run start');
		} catch (ConflictException $exception) {
			self::assertSame('destination_not_writable', $exception->getReason());
		}
		self::assertCount(0, $this->runs);
	}

	public function testStartRunRuntimeFileSelectionFailsClosed(): void {
		$this->addUser('alice');
		$children = [];
		$this->userRootChildren['note.txt'] = $this->makeFileMock('note.txt', 7003, $children);
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);

		try {
			$this->runServiceFor('alice')->startRun($template->getId(), [
				'title' => 'Deploy',
				'destinationPath' => '/note.txt',
			]);
			self::fail('a file selection must abort the run start');
		} catch (ValidationException $exception) {
			self::assertSame('destination_invalid', $exception->getReason());
		}
		self::assertCount(0, $this->runs);
	}

	public function testStartRunRuntimeMalformedInputFailsClosed(): void {
		$this->addUser('alice');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);

		try {
			$this->runServiceFor('alice')->startRun($template->getId(), [
				'title' => 'Deploy',
				'destinationPath' => 123,
			]);
			self::fail('a malformed run-time destination must abort the run start');
		} catch (ValidationException $exception) {
			self::assertSame('invalid_field', $exception->getReason());
		}
		self::assertCount(0, $this->runs);
	}

	public function testStartRunRuntimeInsertFailurePreservesManagedFolder(): void {
		$this->addUser('alice');
		$this->addUserFolder('RuntimeShared', 7003);
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);
		$this->failRunInsert = true;

		try {
			$this->runServiceFor('alice')->startRun($template->getId(), [
				'title' => 'Deploy',
				'destinationPath' => '/RuntimeShared',
			]);
			self::fail('an insert failure must abort the run start');
		} catch (\OCP\DB\Exception) {
		}

		self::assertCount(0, $this->runs);
		self::assertSame([], $this->deletedFolderIds, 'the managed folder is preserved, never recursively deleted');
		self::assertNotContains(7003, $this->deletedFolderIds, 'the selected destination folder itself is never removed');
	}

	public function testRuntimeChoiceDoesNotAffectAnotherRun(): void {
		$this->addUser('alice');
		$this->useDistinctUuids();
		$adminFolder = $this->addUserFolder('AdminShared', 7001);
		$this->addUserFolder('RuntimeShared', 7003);
		$this->setAdminDestination('home::test', 7001, '/AdminShared', 'admin');
		$template = $this->addTemplate('alice');
		$section = $this->addTemplateSection($template->getId(), 'Prep', 0);
		$this->addTemplateStep($section->getId(), 'Check', 'CHECK', true, 0);
		$service = $this->runServiceFor('alice');

		$first = $service->startRun($template->getId(), ['title' => 'With runtime', 'destinationPath' => '/RuntimeShared']);
		$second = $service->startRun($template->getId(), ['title' => 'Inherited']);

		self::assertSame('runtime', $first->getDestinationSource());
		self::assertSame(7003, $first->getDestinationFileId());
		self::assertSame('admin', $second->getDestinationSource(), 'a later run without a run-time choice inherits again');
		self::assertSame(7001, $second->getDestinationFileId());
		self::assertCount(1, $adminFolder->getDirectoryListing());
	}
}
