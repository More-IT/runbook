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
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'FILE', false, RunStepStatus::Pending->value, 0);
		$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'evidence']);

		self::assertNotSame([], $this->evidenceFiles);

		$this->runServiceFor('alice')->deleteRun($run->getId());

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
			$runStepsBySource[$runStep->getSourceStepId()] = $runStep;
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
}
