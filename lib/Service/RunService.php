<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\ActivityEvent;
use OCA\Runbook\Db\AttachmentMapper;
use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunAclMapper;
use OCA\Runbook\Db\RunMapper;
use OCA\Runbook\Db\RunSection;
use OCA\Runbook\Db\RunSectionMapper;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Db\RunStepMapper;
use OCA\Runbook\Db\Template;
use OCA\Runbook\Db\TemplateMapper;
use OCA\Runbook\Db\TemplateSectionMapper;
use OCA\Runbook\Db\TemplateStepMapper;
use OCA\Runbook\Enum\ActivityType;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Enum\TemplateStatus;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

/**
 * Business logic for the run lifecycle.
 *
 * Starting a run copies the published template into independent run records.
 * The snapshot is authoritative for execution: later template changes never
 * affect an existing run, and deleting the source template never deletes the
 * historical run.
 */
class RunService {
	private const MAX_TITLE_LENGTH = 255;
	private const MAX_DESCRIPTION_LENGTH = 10000;
	private const MAX_SECTION_NOTES_LENGTH = 10000;

	public function __construct(
		private readonly TemplateMapper $templates,
		private readonly TemplateSectionMapper $templateSections,
		private readonly TemplateStepMapper $templateSteps,
		private readonly PermissionService $permissionService,
		private readonly RunMapper $runs,
		private readonly RunSectionMapper $runSections,
		private readonly RunStepMapper $runSteps,
		private readonly RunAclMapper $runAclMapper,
		private readonly RunAccessService $access,
		private readonly PrincipalValidator $principalValidator,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $timeFactory,
		private readonly ISecureRandom $secureRandom,
		private readonly TransactionRunner $transactionRunner,
		private readonly ActivityService $activity,
		private readonly NotificationService $notifications,
		private readonly AdminSettings $settings,
		private readonly AttachmentMapper $attachments,
		private readonly EvidenceStorage $evidenceStorage,
		private readonly IGroupManager $groupManager,
		private readonly FlowService $flow,
	) {
	}

	/**
	 * Runs accessible to the current user with their progress.
	 *
	 * @return list<array{run: Run, progress: array{total: int, completed: int, skipped: int, pending: int, percentage: int, canComplete: bool}}>
	 */
	public function listRuns(): array {
		$uid = $this->currentUserId();
		$runIds = $this->accessibleRunIds($uid);

		$result = [];
		foreach ($this->runs->findAccessible($uid, $runIds) as $run) {
			$result[] = [
				'run' => $run,
				'progress' => $this->calculateProgress($run, $this->runSteps->findByRun($run->getId())),
			];
		}

		return $result;
	}

	/**
	 * Start a run from a published template.
	 *
	 * @param array<string, mixed> $data
	 */
	public function startRun(int $templateId, array $data): Run {
		$uid = $this->currentUserId();
		$template = $this->loadTemplate($templateId);

		if ($template->getStatus() !== TemplateStatus::Published->value) {
			throw new ConflictException('template_not_published');
		}
		if (!$this->permissionService->canExecute($template, $uid)) {
			throw new ForbiddenException('not_allowed');
		}

		$title = trim($this->readString($data, 'title') ?? '');
		if ($title === '') {
			throw new ValidationException('run_title_required');
		}
		if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
			throw new ValidationException('title_too_long');
		}

		$description = $this->readString($data, 'description') ?? '';
		if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
			throw new ValidationException('description_too_long');
		}

		$now = $this->timeFactory->getTime();
		$dueAt = $this->readTimestamp($data, 'dueAt');
		if ($dueAt !== null && $dueAt < $now) {
			throw new ValidationException('due_date_in_past');
		}

		$run = new Run();
		$run->setUuid($this->generateUuid());
		$run->setTemplateId($template->getId());
		$run->setTemplateVersion($template->getVersion());
		$run->setTitle($title);
		$run->setDescription($description);
		$run->setOwner($uid);
		$run->setStatus(RunStatus::Active->value);
		$run->setCreatedAt($now);
		$run->setStartedAt($now);
		$run->setDueAt($dueAt);
		$run->setUpdatedAt($now);

		/** @var list<RunStep> $assignedSteps */
		$assignedSteps = [];
		/** @var list<RunStep> $autoAssignedSteps */
		$autoAssignedSteps = [];
		$stored = $this->transactionRunner->run(function () use ($template, $run, $now, $dueAt, &$assignedSteps, &$autoAssignedSteps, $uid): Run {
			$stored = $this->runs->insert($run);

			/** @var array<int, int> $sectionIdMap template section id => run section id */
			$sectionIdMap = [];
			/** @var array<int, int> $stepIdMap template step id => run step id */
			$stepIdMap = [];
			/** @var list<array{0: \OCA\Runbook\Db\TemplateSection, 1: RunSection}> $createdSections */
			$createdSections = [];

			foreach ($this->templateSections->findByTemplate($template->getId()) as $section) {
				$runSection = new RunSection();
				$runSection->setRunId($stored->getId());
				$runSection->setSourceSectionId($section->getId());
				$runSection->setTitle($section->getTitle());
				$runSection->setDescription($section->getDescription());
				$runSection->setNotes($section->getNotes());
				$runSection->setPosition($section->getPosition());
				$storedSection = $this->runSections->insert($runSection);
				$sectionIdMap[$section->getId()] = $storedSection->getId();
				$createdSections[] = [$section, $storedSection];

				foreach ($this->templateSteps->findBySection($section->getId()) as $step) {
					$runStep = new RunStep();
					$runStep->setRunSectionId($storedSection->getId());
					$runStep->setSourceStepId($step->getId());
					$runStep->setUuid($this->generateUuid());
					$runStep->setTitle($step->getTitle());
					$runStep->setDescription($step->getDescription());
					$runStep->setType($step->getType());
					$runStep->setRequired($step->getRequired());
					$runStep->setPosition($step->getPosition());
					$runStep->setConfigArray($step->getConfigArray());
					$runStep->setStatus(RunStepStatus::Pending->value);

					// Unassigned template steps are assigned to the user who
					// starts the run, so every step has an owner and appears in
					// "My Work". Steps with a configured assignee keep it.
					$assignee = $this->resolveDefaultAssignee($step->getDefaultAssignee());
					$autoAssigned = false;
					if ($assignee === null) {
						$assignee = ['type' => PrincipalType::User, 'id' => $uid];
						$autoAssigned = true;
					}
					$runStep->setAssigneeType($assignee['type']->value);
					$runStep->setAssigneeId($assignee['id']);

					$dueMinutes = $this->parseDueOffsetMinutes($step->getDueOffset());
					if ($dueMinutes !== null) {
						$stepDueAt = $now + $dueMinutes * 60;
						if ($dueAt !== null && $stepDueAt > $dueAt) {
							throw new ValidationException('step_due_after_run_due');
						}
						$runStep->setDueAt($stepDueAt);
					}

					$storedStep = $this->runSteps->insert($runStep);
					$stepIdMap[$step->getId()] = $storedStep->getId();
					if ($runStep->getAssigneeType() !== null && $runStep->getAssigneeId() !== null) {
						$assignedSteps[] = $runStep;
					}
					if ($autoAssigned) {
						$autoAssignedSteps[] = $runStep;
					}
				}
			}

			// Second pass: the flow configuration references template section and
			// step ids, which only exist for the run after the first pass.
			foreach ($createdSections as [$templateSection, $runSection]) {
				$dependsOn = [];
				foreach ($templateSection->getDependsOnIds() as $depId) {
					if (isset($sectionIdMap[$depId])) {
						$dependsOn[] = $sectionIdMap[$depId];
					}
				}

				$mappedConditions = [];
				foreach ($templateSection->getConditions() as $condition) {
					$sourceStepId = (int)($condition['stepId'] ?? 0);
					if (isset($stepIdMap[$sourceStepId])) {
						$condition['stepId'] = $stepIdMap[$sourceStepId];
						$mappedConditions[] = $condition;
					}
				}

				$runSection->setDependsOnIds($dependsOn);
				$runSection->setConditions($mappedConditions);
				$this->runSections->update($runSection);
			}

			return $stored;
		});

		$this->activity->record($stored->getId(), null, ActivityType::RunStarted, ['title' => $title], $uid);

		foreach ($autoAssignedSteps as $autoAssignedStep) {
			$this->activity->record(
				$stored->getId(),
				$autoAssignedStep->getId(),
				ActivityType::StepAssignmentChanged,
				[
					'automatic' => true,
					'assigneeType' => $autoAssignedStep->getAssigneeType(),
					'assigneeId' => $autoAssignedStep->getAssigneeId(),
				],
				$uid,
			);
		}

		foreach ($assignedSteps as $assignedStep) {
			$this->notifications->notifyStepAssigned($stored, $assignedStep, $uid);
		}

		return $stored;
	}

	/**
	 * Load a run the current user may view (owner, ACL or step assignment).
	 */
	public function requireAccessibleRun(int $id): Run {
		try {
			$run = $this->runs->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('run_not_found');
		}

		if (!$this->access->canView($run, $this->currentUserId())) {
			throw new NotFoundException('run_not_found');
		}

		return $run;
	}

	/**
	 * Load a run owned by the current user.
	 */
	public function requireOwnedRun(int $id): Run {
		try {
			$run = $this->runs->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('run_not_found');
		}

		if (!$this->access->canManage($run, $this->currentUserId())) {
			throw new NotFoundException('run_not_found');
		}

		return $run;
	}

	public function getRun(int $id): Run {
		return $this->requireAccessibleRun($id);
	}

	/**
	 * Activity history of a run the current user may view.
	 *
	 * @param string|null $order 'asc' or 'desc'; defaults to 'desc' (newest first).
	 * @return list<ActivityEvent>
	 */
	public function listActivity(int $runId, ?int $limit, ?string $order = null): array {
		$this->requireAccessibleRun($runId);

		if ($order !== null && $order !== 'asc' && $order !== 'desc') {
			throw new ValidationException('invalid_order');
		}

		return $this->activity->listForRun($runId, $limit, $order !== 'asc');
	}

	/**
	 * Load a run step by id.
	 */
	public function requireStep(int $stepId): RunStep {
		try {
			return $this->runSteps->find($stepId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('run_step_not_found');
		}
	}

	/**
	 * Run id a step belongs to.
	 */
	public function runIdForStep(RunStep $step): int {
		try {
			return $this->runSections->find($step->getRunSectionId())->getRunId();
		} catch (DoesNotExistException) {
			throw new NotFoundException('run_section_not_found');
		}
	}

	/**
	 * Load a step and verify it belongs to the given run.
	 */
	public function requireStepInRun(int $runId, int $stepId): RunStep {
		$step = $this->requireStep($stepId);
		if ($this->runIdForStep($step) !== $runId) {
			throw new NotFoundException('run_step_not_found');
		}

		return $step;
	}

	/**
	 * @return array{run: Run, sections: list<array{section: RunSection, steps: list<RunStep>, state: string, blockedBy: list<string>, reason: list<array<string, mixed>>}>, progress: array{total: int, completed: int, skipped: int, pending: int, percentage: int, canComplete: bool}, permissions: array{uid: string, role: string|null, canManage: bool, canModify: bool, canCancel: bool, canReopen: bool, canManageAssignments: bool, canComment: bool, canDelete: bool, executableStepIds: list<int>}}
	 */
	public function getRunDetail(int $id): array {
		$run = $this->requireAccessibleRun($id);

		$sections = [];
		$allSteps = [];
		foreach ($this->runSections->findByRun($run->getId()) as $section) {
			$steps = $this->runSteps->findBySection($section->getId());
			$sections[] = ['section' => $section, 'steps' => $steps];
			foreach ($steps as $step) {
				$allSteps[] = $step;
			}
		}

		$flow = $this->flow->evaluate(
			array_map(static fn (array $entry): RunSection => $entry['section'], $sections),
			$allSteps,
		);
		$unavailableStepIds = array_values(array_unique(array_merge(
			$flow['blockedStepIds'],
			$flow['inapplicableStepIds'],
		)));

		$flowSections = [];
		foreach ($sections as $entry) {
			$sectionId = $entry['section']->getId();
			$flowSections[] = [
				'section' => $entry['section'],
				'steps' => $entry['steps'],
				'state' => $flow['states'][$sectionId] ?? FlowService::STATE_AVAILABLE,
				'blockedBy' => $flow['blockedBy'][$sectionId] ?? [],
				'reason' => $flow['reasons'][$sectionId] ?? [],
			];
		}

		return [
			'run' => $run,
			'sections' => $flowSections,
			'progress' => $this->calculateProgress($run, $allSteps, $flow['inapplicableStepIds']),
			'permissions' => $this->getPermissions($run, $allSteps, $unavailableStepIds),
		];
	}

	/**
	 * @param list<RunStep> $steps
	 * @param list<int> $unavailableStepIds Steps in blocked/inapplicable sections.
	 * @return array{uid: string, role: string|null, canManage: bool, canModify: bool, canCancel: bool, canReopen: bool, canManageAssignments: bool, canComment: bool, canDelete: bool, executableStepIds: list<int>}
	 */
	public function getPermissions(Run $run, array $steps, array $unavailableStepIds = []): array {
		$uid = $this->currentUserId();
		$isOwner = $this->access->isOwner($run, $uid);
		$isActive = $run->getStatus() === RunStatus::Active->value;

		$executableStepIds = $this->access->executableStepIds($run, $steps, $uid);
		if ($unavailableStepIds !== []) {
			$executableStepIds = array_values(array_diff($executableStepIds, $unavailableStepIds));
		}

		return [
			'uid' => $uid,
			'role' => $this->access->getEffectiveRole($run, $uid)?->value,
			'canManage' => $isOwner,
			'canModify' => $isOwner && $isActive,
			'canCancel' => $isOwner && $isActive,
			'canReopen' => $isOwner && $run->getStatus() === RunStatus::Completed->value,
			'canManageAssignments' => $isOwner && $isActive,
			'canComment' => $isActive && $this->access->canComment($run, $uid),
			'canDelete' => $isOwner || $this->groupManager->isAdmin($uid),
			'executableStepIds' => $executableStepIds,
		];
	}

	/**
	 * Update the notes of a run section.
	 *
	 * Only the run owner may change section notes and only while the run is
	 * active. The run snapshot stays authoritative: this changes the run's own
	 * copy of the notes, never the source template.
	 *
	 * @param array<string, mixed> $data
	 */
	public function updateSectionNotes(int $sectionId, array $data): RunSection {
		try {
			$section = $this->runSections->find($sectionId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('run_section_not_found');
		}

		$run = $this->requireOwnedRun($section->getRunId());
		if ($run->getStatus() !== RunStatus::Active->value) {
			throw new ConflictException('run_not_active');
		}

		$notes = $data['notes'] ?? null;
		if (!is_string($notes)) {
			throw new ValidationException('invalid_field');
		}
		if (mb_strlen($notes) > self::MAX_SECTION_NOTES_LENGTH) {
			throw new ValidationException('notes_too_long');
		}

		if ($notes === $section->getNotes()) {
			return $section;
		}

		$section->setNotes($notes);
		$section = $this->runSections->update($section);

		// Notes content is deliberately not persisted in the activity log;
		// only the affected section and whether notes are present are recorded.
		$this->activity->record($run->getId(), null, ActivityType::SectionNotesUpdated, [
			'sectionId' => $section->getId(),
			'sectionTitle' => $section->getTitle(),
			'hasNotes' => $notes !== '',
		], $this->currentUserId());

		return $section;
	}

	/**
	 * Return every completed or skipped step of a run section to execution.
	 *
	 * Only the run owner may do this while the run is active, and a reason is
	 * mandatory. Responses, evidence and activity are preserved; dependencies
	 * and conditions are re-evaluated automatically because flow state is
	 * derived from the current snapshot.
	 *
	 * @param array<string, mixed> $data
	 * @return list<RunStep>
	 */
	public function returnSection(int $sectionId, array $data): array {
		try {
			$section = $this->runSections->find($sectionId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('run_section_not_found');
		}

		$run = $this->requireOwnedRun($section->getRunId());
		if ($run->getStatus() !== RunStatus::Active->value) {
			throw new ConflictException('run_not_active');
		}

		$reason = trim($this->readString($data, 'reason') ?? '');
		if ($reason === '') {
			throw new ValidationException('return_reason_required');
		}

		$now = $this->timeFactory->getTime();
		$returned = [];
		foreach ($this->runSteps->findBySection($sectionId) as $step) {
			$status = RunStepStatus::tryFrom($step->getStatus());
			if ($status !== RunStepStatus::Completed && $status !== RunStepStatus::Skipped) {
				continue;
			}
			$step->setStatus(RunStepStatus::Pending->value);
			$step->setSkipReason(null);
			$step->setStartedAt(null);
			$step->setCompletedAt(null);
			$step->setSkippedAt(null);
			$step->setReopenedAt($now);
			$returned[] = $this->runSteps->update($step);
		}

		if ($returned === []) {
			throw new ConflictException('section_has_no_resolved_steps');
		}

		$this->activity->record($run->getId(), null, ActivityType::SectionReturned, [
			'sectionId' => $section->getId(),
			'sectionTitle' => $section->getTitle(),
			'reason' => $reason,
			'count' => count($returned),
		], $this->currentUserId());

		return $returned;
	}

	public function completeRun(int $id): Run {
		$run = $this->requireOwnedRun($id);
		if ($run->getStatus() !== RunStatus::Active->value) {
			throw new ConflictException('run_not_active');
		}

		$progress = $this->calculateProgress($run, $this->runSteps->findByRun($run->getId()));
		if (!$progress['canComplete']) {
			throw new ConflictException('required_steps_unresolved');
		}

		$now = $this->timeFactory->getTime();
		$run->setStatus(RunStatus::Completed->value);
		$run->setCompletedAt($now);
		$run->setCompletedBy($this->currentUserId());
		$run->setUpdatedAt($now);
		$run = $this->runs->update($run);

		$this->activity->record($run->getId(), null, ActivityType::RunCompleted, [], $this->currentUserId());

		return $run;
	}

	public function cancelRun(int $id): Run {
		$run = $this->requireOwnedRun($id);
		if ($run->getStatus() !== RunStatus::Active->value) {
			throw new ConflictException('run_not_active');
		}

		$now = $this->timeFactory->getTime();
		$run->setStatus(RunStatus::Cancelled->value);
		$run->setCancelledAt($now);
		$run->setCancelledBy($this->currentUserId());
		$run->setUpdatedAt($now);
		$run = $this->runs->update($run);

		$this->activity->record($run->getId(), null, ActivityType::RunCancelled, [], $this->currentUserId());

		return $run;
	}

	public function reopenRun(int $id): Run {
		if (!$this->settings->isRunReopenEnabled()) {
			throw new ForbiddenException('run_reopen_disabled');
		}

		$run = $this->requireOwnedRun($id);
		if ($run->getStatus() !== RunStatus::Completed->value) {
			throw new ConflictException('run_not_completed');
		}

		$now = $this->timeFactory->getTime();
		$run->setStatus(RunStatus::Active->value);
		$run->setReopenedAt($now);
		$run->setUpdatedAt($now);
		$run = $this->runs->update($run);

		$this->activity->record($run->getId(), null, ActivityType::RunReopened, [], $this->currentUserId());

		return $run;
	}

	/**
	 * Permanently delete a run and every related record.
	 *
	 * The run owner or a Nextcloud administrator may delete a run in any state.
	 * Evidence files are removed from AppData before the run row is deleted so
	 * no orphaned files remain; sections, steps, ACL, activity, comments,
	 * mentions and notification ledger rows cascade with the run.
	 */
	public function deleteRun(int $id): void {
		try {
			$run = $this->runs->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('run_not_found');
		}

		$uid = $this->currentUserId();
		if (!$this->access->isOwner($run, $uid) && !$this->groupManager->isAdmin($uid)) {
			throw new NotFoundException('run_not_found');
		}

		foreach ($this->attachments->findByRun($id) as $attachment) {
			$this->evidenceStorage->delete($id, $attachment->getStepId(), $attachment->getStorageKey());
		}

		$this->runs->delete($run);
	}

	/**
	 * Progress is always calculated from the current steps.
	 *
	 * @param list<RunStep> $steps
	 * @param list<int> $inapplicableStepIds Steps in sections whose condition is false.
	 * @return array{total: int, completed: int, skipped: int, pending: int, percentage: int, canComplete: bool}
	 */
	public function calculateProgress(Run $run, array $steps, array $inapplicableStepIds = []): array {
		$inapplicable = array_flip($inapplicableStepIds);
		$total = count($steps);
		$completed = 0;
		$skipped = 0;
		$pending = 0;
		$unresolvedRequired = 0;

		foreach ($steps as $step) {
			// Steps of an inapplicable section are resolved (not actionable) and
			// must never block completion.
			if (isset($inapplicable[$step->getId()])) {
				$skipped++;
				continue;
			}

			$status = RunStepStatus::tryFrom($step->getStatus());
			if ($status === RunStepStatus::Completed) {
				$completed++;
			} elseif ($status === RunStepStatus::Skipped) {
				$skipped++;
			} else {
				$pending++;
				if ($step->getRequired()) {
					$unresolvedRequired++;
				}
			}
		}

		$resolved = $completed + $skipped;
		$percentage = $total === 0 ? 100 : (int)floor(($resolved / $total) * 100);

		return [
			'total' => $total,
			'completed' => $completed,
			'skipped' => $skipped,
			'pending' => $pending,
			'percentage' => $percentage,
			'canComplete' => $run->getStatus() === RunStatus::Active->value && $unresolvedRequired === 0,
		];
	}

	/**
	 * @return list<int>
	 */
	private function accessibleRunIds(string $uid): array {
		$groupIds = $this->access->getUserGroupIds($uid);

		/** @var list<int> $merged */
		$merged = array_values(array_unique(array_merge(
			$this->runAclMapper->findRunIdsForPrincipal($uid, $groupIds),
			$this->runSteps->findDistinctRunIdsForPrincipal($uid, $groupIds),
		)));

		return $merged;
	}

	/**
	 * @return array{type: \OCA\Runbook\Enum\PrincipalType, id: string}|null
	 */
	private function resolveDefaultAssignee(?string $value): ?array {
		$parsed = $this->principalValidator->parsePrincipalString($value);
		if ($parsed === null) {
			// Legacy or unsupported default assignee values are ignored so that
			// starting a run never fails because of a stale template field.
			return null;
		}
		if (!$this->principalValidator->exists($parsed['type'], $parsed['id'])) {
			return null;
		}

		return $parsed;
	}

	/**
	 * Due offsets are a non-negative number of minutes; invalid legacy values
	 * are treated as "no due date" instead of failing the run.
	 */
	private function parseDueOffsetMinutes(?string $value): ?int {
		if ($value === null) {
			return null;
		}
		$value = trim($value);
		if ($value === '' || preg_match('/^[0-9]+$/', $value) !== 1) {
			return null;
		}

		return (int)$value;
	}

	private function loadTemplate(int $id): Template {
		try {
			return $this->templates->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('template_not_found');
		}
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
	private function readTimestamp(array $data, string $key): ?int {
		if (!array_key_exists($key, $data) || $data[$key] === null) {
			return null;
		}
		if (is_int($data[$key])) {
			return $data[$key];
		}
		if (is_string($data[$key]) && preg_match('/^[0-9]+$/', $data[$key]) === 1) {
			return (int)$data[$key];
		}

		throw new ValidationException('invalid_field');
	}

	private function generateUuid(): string {
		$hex = $this->secureRandom->generate(32, '0123456789abcdef');
		$hex = substr_replace($hex, '4', 12, 1);
		$variant = dechex(0x8 | ((int)hexdec($hex[16]) & 0x3));
		$hex = substr_replace($hex, $variant, 16, 1);

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr($hex, 0, 8),
			substr($hex, 8, 4),
			substr($hex, 12, 4),
			substr($hex, 16, 4),
			substr($hex, 20, 12),
		);
	}
}
