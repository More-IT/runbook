<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunAclRole;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\ConflictException;
use OCA\Runbook\Service\ForbiddenException;
use OCA\Runbook\Service\NotFoundException;
use OCA\Runbook\Service\ValidationException;

class RunStepServiceTest extends RunTestBase {
	/**
	 * @param array<string, mixed> $config
	 */
	private function createStep(string $type, bool $required, array $config = [], string $runStatus = RunStatus::Active->value): RunStep {
		$run = $this->addRun('alice', $runStatus);
		$section = $this->addRunSection($run->getId(), 0);

		return $this->addRunStep($section->getId(), $type, $required, RunStepStatus::Pending->value, 0, $config);
	}

	public function testStartPendingStep(): void {
		$this->addUser('alice');
		$step = $this->createStep('CHECK', false);

		$started = $this->runStepServiceFor('alice')->start($step->getId());

		self::assertSame(RunStepStatus::InProgress->value, $started->getStatus());
		self::assertSame($this->now, $started->getStartedAt());
	}

	public function testStartRejectsCompletedStep(): void {
		$this->addUser('alice');
		$step = $this->createStep('CHECK', false);
		$step->setStatus(RunStepStatus::Completed->value);

		$this->expectException(ConflictException::class);
		$this->runStepServiceFor('alice')->start($step->getId());
	}

	public function testValidResponsesForEverySupportedStepType(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$service = $this->runStepServiceFor('alice');

		$cases = [
			['CHECK', true],
			['CONFIRMATION', false],
			['TEXT', 'done'],
			['NUMBER', 42],
			['SELECT', 'B'],
			['DATE', '2026-01-31'],
			['USER', 'bob'],
		];

		foreach ($cases as [$type, $response]) {
			$config = $type === 'SELECT' ? ['options' => ['A', 'B']] : [];
			$step = $this->createStep($type, true, $config);
			$completed = $service->complete($step->getId(), ['response' => $response]);
			self::assertSame(RunStepStatus::Completed->value, $completed->getStatus(), $type);
			self::assertSame($response, $completed->getResponseValue(), $type);
		}
	}

	public function testInvalidCheckResponseIsRejected(): void {
		$this->addUser('alice');
		$step = $this->createStep('CHECK', true);

		$this->expectException(ValidationException::class);
		$this->runStepServiceFor('alice')->complete($step->getId(), ['response' => 'yes']);
	}

	public function testInvalidConfirmationResponseIsRejected(): void {
		$this->addUser('alice');
		$step = $this->createStep('CONFIRMATION', true);

		$this->expectException(ValidationException::class);
		$this->runStepServiceFor('alice')->complete($step->getId(), ['response' => 'yes']);
	}

	public function testInvalidNumberResponseIsRejected(): void {
		$this->addUser('alice');
		$step = $this->createStep('NUMBER', true);

		$this->expectException(ValidationException::class);
		$this->runStepServiceFor('alice')->complete($step->getId(), ['response' => 'not-a-number']);
	}

	public function testInvalidSelectOptionIsRejected(): void {
		$this->addUser('alice');
		$step = $this->createStep('SELECT', true, ['options' => ['A', 'B']]);

		$this->expectException(ValidationException::class);
		$this->runStepServiceFor('alice')->complete($step->getId(), ['response' => 'C']);
	}

	public function testInvalidDateResponseIsRejected(): void {
		$this->addUser('alice');
		$step = $this->createStep('DATE', true);

		$this->expectException(ValidationException::class);
		$this->runStepServiceFor('alice')->complete($step->getId(), ['response' => '31-01-2026']);
	}

	public function testInvalidUserResponseIsRejected(): void {
		$this->addUser('alice');
		$step = $this->createStep('USER', true);

		$this->expectException(ValidationException::class);
		$this->runStepServiceFor('alice')->complete($step->getId(), ['response' => 'ghost']);
	}

	public function testRequiredFileStepReportsUnsupportedUpload(): void {
		$this->addUser('alice');
		$step = $this->createStep('FILE', true);

		$this->expectException(ValidationException::class);
		$this->runStepServiceFor('alice')->complete($step->getId(), []);
	}

	public function testOptionalFileStepCanBeCompletedWithoutEvidence(): void {
		$this->addUser('alice');
		$step = $this->createStep('FILE', false);

		$completed = $this->runStepServiceFor('alice')->complete($step->getId(), []);

		self::assertSame(RunStepStatus::Completed->value, $completed->getStatus());
		self::assertNull($completed->getResponseValue());
	}

	public function testRequiredStepCannotCompleteWithoutResponse(): void {
		$this->addUser('alice');
		$step = $this->createStep('TEXT', true);

		$this->expectException(ValidationException::class);
		$this->runStepServiceFor('alice')->complete($step->getId(), []);
	}

	public function testOptionalStepCanCompleteWithoutResponse(): void {
		$this->addUser('alice');
		$step = $this->createStep('TEXT', false);

		$completed = $this->runStepServiceFor('alice')->complete($step->getId(), []);

		self::assertSame(RunStepStatus::Completed->value, $completed->getStatus());
	}

	public function testSkipRequiresReason(): void {
		$this->addUser('alice');
		$step = $this->createStep('TEXT', true);

		$this->expectException(ValidationException::class);
		$this->runStepServiceFor('alice')->skip($step->getId(), ['reason' => '   ']);
	}

	public function testSkipStepStoresReason(): void {
		$this->addUser('alice');
		$step = $this->createStep('TEXT', true);

		$skipped = $this->runStepServiceFor('alice')->skip($step->getId(), ['reason' => 'Not applicable']);

		self::assertSame(RunStepStatus::Skipped->value, $skipped->getStatus());
		self::assertSame('Not applicable', $skipped->getSkipReason());
		self::assertSame($this->now, $skipped->getSkippedAt());
	}

	public function testReopenCompletedStepClearsState(): void {
		$this->addUser('alice');
		$step = $this->createStep('TEXT', true);
		$service = $this->runStepServiceFor('alice');
		$service->complete($step->getId(), ['response' => 'done']);
		$this->now = 2000;

		$reopened = $service->reopen($step->getId());

		self::assertSame(RunStepStatus::Pending->value, $reopened->getStatus());
		self::assertNull($reopened->getResponseValue());
		self::assertNull($reopened->getStartedAt());
		self::assertNull($reopened->getCompletedAt());
		self::assertSame(2000, $reopened->getReopenedAt());
	}

	public function testReopenSkippedStepClearsReason(): void {
		$this->addUser('alice');
		$step = $this->createStep('TEXT', true);
		$service = $this->runStepServiceFor('alice');
		$service->skip($step->getId(), ['reason' => 'Later']);

		$reopened = $service->reopen($step->getId());

		self::assertSame(RunStepStatus::Pending->value, $reopened->getStatus());
		self::assertNull($reopened->getSkipReason());
		self::assertNull($reopened->getSkippedAt());
	}

	public function testReopenPendingStepIsRejected(): void {
		$this->addUser('alice');
		$step = $this->createStep('TEXT', false);

		$this->expectException(ConflictException::class);
		$this->runStepServiceFor('alice')->reopen($step->getId());
	}

	public function testReopenDisabledRejectsReopen(): void {
		$this->addUser('alice');
		$step = $this->createStep('TEXT', false);
		$this->runStepServiceFor('alice')->complete($step->getId(), ['response' => 'done']);
		$this->setAppConfig(AdminSettings::KEY_STEP_REOPEN_ENABLED, false);

		$this->expectException(ForbiddenException::class);
		$this->runStepServiceFor('alice')->reopen($step->getId());
	}

	public function testSkipReasonOptionalAllowsEmptyReason(): void {
		$this->addUser('alice');
		$step = $this->createStep('TEXT', true);
		$this->setAppConfig(AdminSettings::KEY_REQUIRE_SKIP_REASON, false);

		$skipped = $this->runStepServiceFor('alice')->skip($step->getId(), []);

		self::assertSame(RunStepStatus::Skipped->value, $skipped->getStatus());
		self::assertNull($skipped->getSkipReason());
		self::assertSame($this->now, $skipped->getSkippedAt());
	}

	public function testCompleteSkippedStepIsRejected(): void {
		$this->addUser('alice');
		$step = $this->createStep('TEXT', false);
		$step->setStatus(RunStepStatus::Skipped->value);

		$this->expectException(ConflictException::class);
		$this->runStepServiceFor('alice')->complete($step->getId(), ['response' => 'done']);
	}

	public function testSkipCompletedStepIsRejected(): void {
		$this->addUser('alice');
		$step = $this->createStep('TEXT', false);
		$step->setStatus(RunStepStatus::Completed->value);

		$this->expectException(ConflictException::class);
		$this->runStepServiceFor('alice')->skip($step->getId(), ['reason' => 'Nope']);
	}

	public function testPatchStoresResponseWithoutChangingStatus(): void {
		$this->addUser('alice');
		$step = $this->createStep('TEXT', false);

		$updated = $this->runStepServiceFor('alice')->update($step->getId(), ['response' => 'draft']);

		self::assertSame(RunStepStatus::Pending->value, $updated->getStatus());
		self::assertSame('draft', $updated->getResponseValue());
	}

	public function testMutationsRejectedOnCompletedRun(): void {
		$this->addUser('alice');
		$step = $this->createStep('CHECK', false, [], RunStatus::Completed->value);

		$this->expectException(ConflictException::class);
		$this->runStepServiceFor('alice')->start($step->getId());
	}

	public function testMutationsRejectedOnCancelledRun(): void {
		$this->addUser('alice');
		$step = $this->createStep('CHECK', false, [], RunStatus::Cancelled->value);

		$this->expectException(ConflictException::class);
		$this->runStepServiceFor('alice')->complete($step->getId(), ['response' => true]);
	}

	public function testNonOwnerCannotMutateStep(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$step = $this->createStep('CHECK', false);

		$this->expectException(NotFoundException::class);
		$this->runStepServiceFor('bob')->start($step->getId());
	}

	/**
	 * @return array{0: \OCA\Runbook\Db\Run, 1: RunStep}
	 */
	private function runWithAssignedStep(string $assigneeType, string $assigneeId, string $status = RunStatus::Active->value, ?int $runDueAt = null): array {
		$run = $this->addRun('alice', $status, $runDueAt);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'TEXT', false, RunStepStatus::Pending->value, 0, [], $assigneeType, $assigneeId);

		return [$run, $step];
	}

	public function testDirectAssigneeCanExecuteAssignedStep(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'bob');

		$started = $this->runStepServiceFor('bob')->start($step->getId());

		self::assertSame(RunStepStatus::InProgress->value, $started->getStatus());
	}

	public function testParticipantCannotExecuteUnassignedStep(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addUser('carol');
		[$run, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'carol');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Participant->value);

		$this->expectException(ForbiddenException::class);
		$this->runStepServiceFor('bob')->complete($step->getId(), ['response' => 'done']);
	}

	public function testGroupAssignedParticipantCanExecuteStep(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addGroup('engineering');
		$this->joinGroup('bob', 'engineering');
		[, $step] = $this->runWithAssignedStep(PrincipalType::Group->value, 'engineering');

		$started = $this->runStepServiceFor('bob')->start($step->getId());

		self::assertSame(RunStepStatus::InProgress->value, $started->getStatus());
	}

	public function testOnlyOwnerMayChangeAssignment(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'bob');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Participant->value);

		$this->expectException(ForbiddenException::class);
		$this->runStepServiceFor('bob')->update($step->getId(), ['assigneeType' => 'USER', 'assigneeId' => 'alice']);
	}

	public function testOwnerCanAssignAndClearStep(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'alice');
		$service = $this->runStepServiceFor('alice');

		$assigned = $service->update($step->getId(), ['assigneeType' => 'USER', 'assigneeId' => 'bob']);
		self::assertSame('bob', $assigned->getAssigneeId());

		$cleared = $service->update($step->getId(), ['assigneeType' => null, 'assigneeId' => null]);
		self::assertNull($cleared->getAssigneeType());
		self::assertNull($cleared->getAssigneeId());
	}

	public function testAssignmentRejectsUnknownUser(): void {
		$this->addUser('alice');
		[, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'alice');

		$this->expectException(ValidationException::class);
		$this->runStepServiceFor('alice')->update($step->getId(), ['assigneeType' => 'USER', 'assigneeId' => 'ghost']);
	}

	public function testAssignmentRejectedOnCompletedRun(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'alice', RunStatus::Completed->value);

		$this->expectException(ConflictException::class);
		$this->runStepServiceFor('alice')->update($step->getId(), ['assigneeType' => 'USER', 'assigneeId' => 'bob']);
	}

	public function testAssignmentRejectedOnCancelledRun(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'alice', RunStatus::Cancelled->value);

		$this->expectException(ConflictException::class);
		$this->runStepServiceFor('alice')->update($step->getId(), ['assigneeType' => 'USER', 'assigneeId' => 'bob']);
	}

	public function testStepDueAfterRunDueIsRejected(): void {
		$this->addUser('alice');
		[, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'alice', RunStatus::Active->value, $this->now + 7200);

		$this->expectException(ValidationException::class);
		$this->runStepServiceFor('alice')->update($step->getId(), ['dueAt' => $this->now + 999999]);
	}

	public function testHistoricalAssignmentRetainedAfterCompletion(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step] = $this->runWithAssignedStep(PrincipalType::User->value, 'bob');

		$this->runServiceFor('alice')->completeRun($run->getId());

		$stored = $this->runSteps[$step->getId()];
		self::assertSame('bob', $stored->getAssigneeId());
		self::assertSame(RunStatus::Completed->value, $this->runs[$run->getId()]->getStatus());
	}
}
