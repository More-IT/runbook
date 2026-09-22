<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\AttachmentMapper;
use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunMapper;
use OCA\Runbook\Db\RunSectionMapper;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Db\RunStepMapper;
use OCA\Runbook\Enum\ActivityType;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Enum\StepType;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;

/**
 * Step execution and assignment for runs.
 *
 * Owners may execute every step and change assignments. Participants may view
 * the run and execute only the steps assigned to them or to one of their
 * groups. Every operation verifies the run is active and the transition is
 * allowed.
 */
class RunStepService {
	private const MAX_SKIP_REASON_LENGTH = 2000;

	public function __construct(
		private readonly RunMapper $runs,
		private readonly RunSectionMapper $runSections,
		private readonly RunStepMapper $runSteps,
		private readonly AttachmentMapper $attachments,
		private readonly EvidenceLockService $evidenceLock,
		private readonly RunAccessService $access,
		private readonly PrincipalValidator $principalValidator,
		private readonly StepResponseValidator $validator,
		private readonly ActivityService $activity,
		private readonly NotificationService $notifications,
		private readonly AdminSettings $settings,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $timeFactory,
		private readonly FlowService $flow,
	) {
	}

	public function start(int $stepId): RunStep {
		[$run, $step] = $this->requireStepForExecution($stepId);

		if (RunStepStatus::tryFrom($step->getStatus()) !== RunStepStatus::Pending) {
			throw new ConflictException('invalid_step_transition');
		}

		$now = $this->timeFactory->getTime();
		$step->setStatus(RunStepStatus::InProgress->value);
		$step->setStartedAt($now);
		$step = $this->runSteps->update($step);
		$this->touchRun($run);
		$this->activity->record($run->getId(), $step->getId(), ActivityType::StepStarted, [], $this->currentUserId());

		return $step;
	}

	/**
	 * Store a validated response and/or update the step assignment.
	 *
	 * @param array<string, mixed> $data
	 */
	public function update(int $stepId, array $data): RunStep {
		$hasAssignment = array_key_exists('assigneeType', $data)
			|| array_key_exists('assigneeId', $data)
			|| array_key_exists('dueAt', $data);
		$hasResponse = array_key_exists('response', $data);

		$previousAssignee = null;
		if ($hasAssignment) {
			[$run, $step] = $this->requireStepForAssignment($stepId);
			$previousAssignee = $step->getAssigneeId();
			$this->applyAssignment($run, $step, $data);
		} else {
			[$run, $step] = $this->requireStepForExecution($stepId);
		}

		if ($hasResponse) {
			$this->assertEditableStatus($step);
			$raw = $data['response'];
			if ($raw === null) {
				$step->setResponseValue(null);
			} else {
				$step->setResponseValue($this->validator->validate($this->stepType($step), $raw, $step->getConfigArray()));
			}
		}

		$step = $this->runSteps->update($step);
		$this->touchRun($run);

		if ($hasAssignment) {
			$this->activity->record($run->getId(), $step->getId(), ActivityType::StepAssignmentChanged, [
				'previous' => $previousAssignee,
				'new' => $step->getAssigneeId(),
			], $this->currentUserId());
			if ($step->getAssigneeType() !== null && $step->getAssigneeId() !== null) {
				$this->notifications->notifyStepAssigned($run, $step, $this->currentUserId());
			}
		}
		if ($hasResponse) {
			$this->activity->record($run->getId(), $step->getId(), ActivityType::StepResponseUpdated, [], $this->currentUserId());
		}

		return $step;
	}

	/**
	 * Complete a step, validating the supplied or stored response.
	 *
	 * @param array<string, mixed> $data
	 */
	public function complete(int $stepId, array $data): RunStep {
		[$run, $step] = $this->requireStepForExecution($stepId);
		$this->assertEditableStatus($step);

		$type = $this->stepType($step);
		$provided = array_key_exists('response', $data) && $data['response'] !== null;

		$completeStep = function () use ($step): void {
			$now = $this->timeFactory->getTime();
			$step->setStatus(RunStepStatus::Completed->value);
			$step->setCompletedAt($now);
			$step->setSkipReason(null);
			$this->runSteps->update($step);
		};

		if ($type === StepType::File) {
			// FILE steps are resolved through evidence, never a response value.
			if ($provided) {
				throw new ValidationException('invalid_file_response');
			}
			$step->setResponseValue(null);

			// The evidence check and the status update must be atomic with
			// evidence deletion, so they share the per-step evidence lock.
			$this->evidenceLock->synchronized($run->getId(), $step->getId(), function () use ($run, $step, $completeStep): void {
				if ($step->getRequired() && $this->countStepEvidence($run, $step) === 0) {
					throw new ValidationException('file_evidence_required');
				}
				$completeStep();
			});
		} else {
			if ($provided) {
				$step->setResponseValue($this->validator->validate($type, $data['response'], $step->getConfigArray()));
			}
			if ($step->getRequired() && $step->getResponseValue() === null) {
				throw new ValidationException('response_required');
			}
			$completeStep();
		}

		$this->touchRun($run);
		$this->activity->record($run->getId(), $step->getId(), ActivityType::StepCompleted, [], $this->currentUserId());

		return $step;
	}

	/**
	 * Skip a step with a mandatory reason.
	 *
	 * @param array<string, mixed> $data
	 */
	public function skip(int $stepId, array $data): RunStep {
		[$run, $step] = $this->requireStepForExecution($stepId);
		$this->assertEditableStatus($step);

		$reason = trim($this->readString($data, 'reason') ?? '');
		if ($reason === '' && $this->settings->isSkipReasonRequired()) {
			throw new ValidationException('skip_reason_required');
		}
		if (mb_strlen($reason) > self::MAX_SKIP_REASON_LENGTH) {
			throw new ValidationException('skip_reason_too_long');
		}

		$now = $this->timeFactory->getTime();
		$step->setStatus(RunStepStatus::Skipped->value);
		$step->setSkipReason($reason === '' ? null : $reason);
		$step->setSkippedAt($now);
		$step->setResponseValue(null);
		$step->setCompletedAt(null);
		$step = $this->runSteps->update($step);
		$this->touchRun($run);
		$this->activity->record($run->getId(), $step->getId(), ActivityType::StepSkipped, [], $this->currentUserId());

		return $step;
	}

	/**
	 * Reopen a completed or skipped step.
	 */
	public function reopen(int $stepId): RunStep {
		if (!$this->settings->isStepReopenEnabled()) {
			throw new ForbiddenException('step_reopen_disabled');
		}

		[$run, $step] = $this->requireStepForExecution($stepId);

		$status = RunStepStatus::tryFrom($step->getStatus());
		if ($status !== RunStepStatus::Completed && $status !== RunStepStatus::Skipped) {
			throw new ConflictException('invalid_step_transition');
		}

		$now = $this->timeFactory->getTime();
		$step->setStatus(RunStepStatus::Pending->value);
		$step->setResponseValue(null);
		$step->setSkipReason(null);
		$step->setStartedAt(null);
		$step->setCompletedAt(null);
		$step->setSkippedAt(null);
		$step->setReopenedAt($now);
		$step = $this->runSteps->update($step);
		$this->touchRun($run);
		$this->activity->record($run->getId(), $step->getId(), ActivityType::StepReopened, [], $this->currentUserId());

		return $step;
	}

	/**
	 * Return a completed or skipped step to execution for correction.
	 *
	 * Unlike a plain reopen, a return requires a reason and keeps the previous
	 * response, attached evidence and activity history intact.
	 *
	 * @param array<string, mixed> $data
	 */
	public function returnStep(int $stepId, array $data): RunStep {
		[$run, $step] = $this->requireStepForExecution($stepId);

		$status = RunStepStatus::tryFrom($step->getStatus());
		if ($status !== RunStepStatus::Completed && $status !== RunStepStatus::Skipped) {
			throw new ConflictException('invalid_step_transition');
		}

		$reason = trim($this->readString($data, 'reason') ?? '');
		if ($reason === '') {
			throw new ValidationException('return_reason_required');
		}
		if (mb_strlen($reason) > self::MAX_SKIP_REASON_LENGTH) {
			throw new ValidationException('return_reason_too_long');
		}

		$previousStatus = $step->getStatus();
		$now = $this->timeFactory->getTime();
		$step->setStatus(RunStepStatus::Pending->value);
		// The stored response is preserved on purpose; only the skip reason is
		// cleared because the step is no longer skipped.
		$step->setSkipReason(null);
		$step->setStartedAt(null);
		$step->setCompletedAt(null);
		$step->setSkippedAt(null);
		$step->setReopenedAt($now);
		$step = $this->runSteps->update($step);
		$this->touchRun($run);
		$this->activity->record($run->getId(), $step->getId(), ActivityType::StepReturned, [
			'reason' => $reason,
			'previous' => $previousStatus,
			'new' => RunStepStatus::Pending->value,
		], $this->currentUserId());

		return $step;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function applyAssignment(Run $run, RunStep $step, array $data): void {
		$hasType = array_key_exists('assigneeType', $data);
		$hasId = array_key_exists('assigneeId', $data);

		if ($hasType || $hasId) {
			if ($hasType !== $hasId) {
				throw new ValidationException('invalid_field');
			}

			$type = $data['assigneeType'];
			$id = $data['assigneeId'];
			if ($type === null && $id === null) {
				$step->setAssigneeType(null);
				$step->setAssigneeId(null);
			} else {
				$principal = $this->principalValidator->validate($type, $id);
				$step->setAssigneeType($principal['type']->value);
				$step->setAssigneeId($principal['id']);
			}
		}

		if (array_key_exists('dueAt', $data)) {
			$dueAt = $this->readTimestampOrNull($data, 'dueAt');
			$runDueAt = $run->getDueAt();
			if ($dueAt !== null && $runDueAt !== null && $dueAt > $runDueAt) {
				throw new ValidationException('step_due_after_run_due');
			}
			$step->setDueAt($dueAt);
		}
	}

	/**
	 * Resolve a step the current user may execute, verifying the run is active.
	 *
	 * @return array{0: Run, 1: RunStep}
	 */
	public function requireExecutableStep(int $stepId): array {
		return $this->requireStepForExecution($stepId);
	}

	/**
	 * Load a run step by id for read-only callers that already checked access
	 * (for example evidence deletion in {@see AttachmentService}).
	 */
	public function findStep(int $stepId): RunStep {
		try {
			return $this->runSteps->find($stepId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('run_step_not_found');
		}
	}

	/**
	 * Number of persisted attachments belonging to this exact step and run.
	 */
	private function countStepEvidence(Run $run, RunStep $step): int {
		return $this->attachments->countByRunAndStep($run->getId(), $step->getId());
	}

	/**
	 * @return array{0: Run, 1: RunStep}
	 */
	private function requireStepContext(int $stepId): array {
		try {
			$step = $this->runSteps->find($stepId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('run_step_not_found');
		}

		try {
			$section = $this->runSections->find($step->getRunSectionId());
		} catch (DoesNotExistException) {
			throw new NotFoundException('run_section_not_found');
		}

		try {
			$run = $this->runs->find($section->getRunId());
		} catch (DoesNotExistException) {
			throw new NotFoundException('run_not_found');
		}

		return [$run, $step];
	}

	/**
	 * @return array{0: Run, 1: RunStep}
	 */
	private function requireStepForExecution(int $stepId): array {
		[$run, $step] = $this->requireStepContext($stepId);
		$uid = $this->currentUserId();

		if (!$this->access->canView($run, $uid)) {
			throw new NotFoundException('run_step_not_found');
		}
		if ($run->getStatus() !== RunStatus::Active->value) {
			throw new ConflictException('run_not_active');
		}
		if (!$this->access->canExecuteStep($run, $step, $uid)) {
			throw new ForbiddenException('not_allowed');
		}

		$this->assertSectionAvailable($run, $step);

		return [$run, $step];
	}

	/**
	 * Reject execution of steps whose section is blocked or inapplicable.
	 */
	private function assertSectionAvailable(Run $run, RunStep $step): void {
		$flow = $this->flow->evaluate(
			$this->runSections->findByRun($run->getId()),
			$this->runSteps->findByRun($run->getId()),
		);
		$state = $flow['states'][$step->getRunSectionId()] ?? FlowService::STATE_AVAILABLE;
		if ($state === FlowService::STATE_BLOCKED || $state === FlowService::STATE_INAPPLICABLE) {
			throw new ConflictException('section_not_available');
		}
	}

	/**
	 * @return array{0: Run, 1: RunStep}
	 */
	private function requireStepForAssignment(int $stepId): array {
		[$run, $step] = $this->requireStepContext($stepId);
		$uid = $this->currentUserId();

		if (!$this->access->canView($run, $uid)) {
			throw new NotFoundException('run_step_not_found');
		}
		if ($run->getStatus() !== RunStatus::Active->value) {
			throw new ConflictException('run_not_active');
		}
		if (!$this->access->canManage($run, $uid)) {
			throw new ForbiddenException('not_allowed');
		}

		return [$run, $step];
	}

	private function assertEditableStatus(RunStep $step): void {
		$status = RunStepStatus::tryFrom($step->getStatus());
		if ($status !== RunStepStatus::Pending && $status !== RunStepStatus::InProgress) {
			throw new ConflictException('invalid_step_transition');
		}
	}

	private function stepType(RunStep $step): StepType {
		$type = StepType::tryFrom($step->getType());
		if ($type === null) {
			throw new ValidationException('invalid_step_type');
		}

		return $type;
	}

	private function touchRun(Run $run): void {
		$run->setUpdatedAt($this->timeFactory->getTime());
		$this->runs->update($run);
	}

	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new ForbiddenException('not_authenticated');
		}

		return $user->getUID();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function readString(array $data, string $key): ?string {
		if (!array_key_exists($key, $data) || $data[$key] === null) {
			return null;
		}
		if (!is_string($data[$key])) {
			throw new ValidationException('invalid_field');
		}

		return $data[$key];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function readTimestampOrNull(array $data, string $key): ?int {
		if (!array_key_exists($key, $data) || $data[$key] === null) {
			return null;
		}
		$value = $data[$key];
		if (is_int($value) && $value > 0) {
			return $value;
		}
		if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1 && (int)$value > 0) {
			return (int)$value;
		}

		throw new ValidationException('invalid_field');
	}
}
