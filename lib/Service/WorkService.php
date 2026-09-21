<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunAclMapper;
use OCA\Runbook\Db\RunMapper;
use OCA\Runbook\Db\RunSection;
use OCA\Runbook\Db\RunSectionMapper;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Db\RunStepMapper;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserSession;

/**
 * "My Work" and overview aggregation.
 *
 * All queries are bounded. "Today" is resolved in the user's Nextcloud
 * timezone when available, falling back to the server timezone.
 */
class WorkService {
	public const FILTERS = ['all', 'today', 'upcoming', 'overdue', 'completed'];

	private const MAX_ASSIGNED_STEPS = 500;
	private const OVERVIEW_WORK_LIMIT = 5;
	private const OVERVIEW_RUN_LIMIT = 5;

	public function __construct(
		private readonly RunMapper $runs,
		private readonly RunSectionMapper $runSections,
		private readonly RunStepMapper $runSteps,
		private readonly RunAclMapper $runAclMapper,
		private readonly RunAccessService $access,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $timeFactory,
		private readonly IConfig $config,
		private readonly FlowService $flow,
	) {
	}

	/**
	 * Assigned work of the current user for the given filter.
	 *
	 * @return list<array{run: Run, section: RunSection, step: RunStep, overdue: bool, dueToday: bool}>
	 */
	public function myWork(string $filter): array {
		return $this->myWorkForUser($this->currentUserId(), $filter);
	}

	/**
	 * Assigned work of a specific user for the given filter.
	 *
	 * @return list<array{run: Run, section: RunSection, step: RunStep, overdue: bool, dueToday: bool}>
	 */
	public function myWorkForUser(string $uid, string $filter): array {
		if (!in_array($filter, self::FILTERS, true)) {
			throw new ValidationException('invalid_filter');
		}

		$now = $this->timeFactory->getTime();
		[$todayStart, $todayEnd] = $this->dayRange($uid, $now);

		$items = [];
		$unavailableCache = [];
		foreach ($this->assignedItems($uid) as $item) {
			$run = $item['run'];
			$step = $item['step'];

			// Steps in blocked or inapplicable sections are not actionable work.
			$unavailable = $this->unavailableStepIds($run->getId(), $unavailableCache);
			if (isset($unavailable[$step->getId()])) {
				continue;
			}

			$status = RunStepStatus::tryFrom($step->getStatus());
			$active = $run->getStatus() === RunStatus::Active->value
				&& ($status === RunStepStatus::Pending || $status === RunStepStatus::InProgress);

			$dueAt = $step->getDueAt();
			$overdue = $active && $dueAt !== null && $dueAt < $now;
			$dueToday = $active && $dueAt !== null && $dueAt >= $todayStart && $dueAt < $todayEnd;

			$matches = match ($filter) {
				'today' => $active && $dueToday,
				'upcoming' => $active && $dueAt !== null && $dueAt >= $todayEnd,
				'overdue' => $overdue,
				'completed' => $status === RunStepStatus::Completed || $status === RunStepStatus::Skipped,
				default => $active,
			};

			if ($matches) {
				$items[] = [
					'run' => $run,
					'section' => $item['section'],
					'step' => $step,
					'overdue' => $overdue,
					'dueToday' => $dueToday,
				];
			}
		}

		usort($items, static function (array $a, array $b): int {
			return self::compareWorkItems($a, $b);
		});

		return $items;
	}

	/**
	 * Bounded overview counters and short lists.
	 *
	 * @return array{
	 *     activeRuns: int,
	 *     assignedActiveSteps: int,
	 *     overdue: int,
	 *     completedStepsThisMonth: int,
	 *     completedRunsThisMonth: int,
	 *     assignedWork: list<array{run: Run, section: RunSection, step: RunStep, overdue: bool, dueToday: bool}>,
	 *     recentRuns: list<Run>
	 * }
	 */
	public function overview(): array {
		return $this->overviewForUser($this->currentUserId());
	}

	/**
	 * Bounded overview counters and short lists for a specific user.
	 *
	 * @return array{
	 *     activeRuns: int,
	 *     assignedActiveSteps: int,
	 *     overdue: int,
	 *     completedStepsThisMonth: int,
	 *     completedRunsThisMonth: int,
	 *     assignedWork: list<array{run: Run, section: RunSection, step: RunStep, overdue: bool, dueToday: bool}>,
	 *     recentRuns: list<Run>
	 * }
	 */
	public function overviewForUser(string $uid): array {
		$groupIds = $this->access->getUserGroupIds($uid);
		$runIds = $this->accessibleRunIds($uid, $groupIds);
		$now = $this->timeFactory->getTime();
		$monthStart = $this->monthStart($uid, $now);

		$assignedWork = $this->myWorkForUser($uid, 'all');
		$overdue = 0;
		foreach ($assignedWork as $item) {
			if ($item['overdue']) {
				$overdue++;
			}
		}
		$recentRuns = $this->runs->findAccessible($uid, $runIds, self::OVERVIEW_RUN_LIMIT);

		return [
			'activeRuns' => $this->runs->countAccessible($uid, $runIds, RunStatus::Active->value),
			'assignedActiveSteps' => count($assignedWork),
			'overdue' => $overdue,
			'completedStepsThisMonth' => $this->runSteps->countAssignedCompletedSince($uid, $groupIds, $monthStart),
			'completedRunsThisMonth' => $this->runs->countAccessibleCompletedSince($uid, $runIds, $monthStart),
			'assignedWork' => array_slice($assignedWork, 0, self::OVERVIEW_WORK_LIMIT),
			'recentRuns' => $recentRuns,
		];
	}

	/**
	 * Step ids of a run that live in blocked or inapplicable sections.
	 *
	 * @param array<int, array<int, true>> $cache
	 * @return array<int, true>
	 */
	private function unavailableStepIds(int $runId, array &$cache): array {
		if (isset($cache[$runId])) {
			return $cache[$runId];
		}

		$flow = $this->flow->evaluate(
			$this->runSections->findByRun($runId),
			$this->runSteps->findByRun($runId),
		);

		$unavailable = [];
		foreach (array_merge($flow['blockedStepIds'], $flow['inapplicableStepIds']) as $stepId) {
			$unavailable[$stepId] = true;
		}

		return $cache[$runId] = $unavailable;
	}

	/**
	 * @param list<string> $groupIds
	 * @return list<int>
	 */
	private function accessibleRunIds(string $uid, array $groupIds): array {
		$aclRunIds = $this->runAclMapper->findRunIdsForPrincipal($uid, $groupIds);
		$assignedRunIds = $this->runSteps->findDistinctRunIdsForPrincipal($uid, $groupIds);

		/** @var list<int> $merged */
		$merged = array_values(array_unique(array_merge($aclRunIds, $assignedRunIds)));

		return $merged;
	}

	/**
	 * @return list<array{run: Run, section: RunSection, step: RunStep}>
	 */
	private function assignedItems(string $uid): array {
		$groupIds = $this->access->getUserGroupIds($uid);
		$steps = $this->runSteps->findAssignedTo($uid, $groupIds, self::MAX_ASSIGNED_STEPS);
		if ($steps === []) {
			return [];
		}

		$sectionIds = array_values(array_unique(array_map(
			static fn (RunStep $step): int => $step->getRunSectionId(),
			$steps,
		)));
		$sections = [];
		foreach ($this->runSections->findByIds($sectionIds) as $section) {
			$sections[$section->getId()] = $section;
		}

		$runIds = array_values(array_unique(array_map(
			static fn (RunSection $section): int => $section->getRunId(),
			$sections,
		)));
		$runs = [];
		foreach ($this->runs->findByIds($runIds) as $run) {
			$runs[$run->getId()] = $run;
		}

		$items = [];
		foreach ($steps as $step) {
			$section = $sections[$step->getRunSectionId()] ?? null;
			if ($section === null) {
				continue;
			}
			$run = $runs[$section->getRunId()] ?? null;
			if ($run === null) {
				continue;
			}

			$items[] = ['run' => $run, 'section' => $section, 'step' => $step];
		}

		return $items;
	}

	/**
	 * @param array{run: Run, section: RunSection, step: RunStep, overdue: bool, dueToday: bool} $a
	 * @param array{run: Run, section: RunSection, step: RunStep, overdue: bool, dueToday: bool} $b
	 */
	private static function compareWorkItems(array $a, array $b): int {
		if ($a['overdue'] !== $b['overdue']) {
			return $a['overdue'] ? -1 : 1;
		}

		$aDue = $a['step']->getDueAt();
		$bDue = $b['step']->getDueAt();
		if ($aDue !== $bDue) {
			if ($aDue === null) {
				return 1;
			}
			if ($bDue === null) {
				return -1;
			}

			return $aDue <=> $bDue;
		}

		return $a['step']->getId() <=> $b['step']->getId();
	}

	/**
	 * @return array{0: int, 1: int}
	 */
	private function dayRange(string $uid, int $now): array {
		$timezone = $this->userTimeZone($uid);
		$date = (new \DateTimeImmutable('@' . $now))->setTimezone($timezone);
		$start = $date->setTime(0, 0, 0);
		$end = $start->modify('+1 day');

		return [$start->getTimestamp(), $end->getTimestamp()];
	}

	private function monthStart(string $uid, int $now): int {
		$timezone = $this->userTimeZone($uid);
		$date = (new \DateTimeImmutable('@' . $now))->setTimezone($timezone);

		return $date->modify('first day of this month')->setTime(0, 0, 0)->getTimestamp();
	}

	private function userTimeZone(string $uid): \DateTimeZone {
		$timezone = $this->config->getUserValue($uid, 'core', 'timezone', '');
		if (is_string($timezone) && $timezone !== '') {
			try {
				return new \DateTimeZone($timezone);
			} catch (\Exception) {
				// Fall through to the server timezone.
			}
		}

		return new \DateTimeZone(date_default_timezone_get());
	}

	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new ForbiddenException('not_authenticated');
		}

		return $user->getUID();
	}
}
