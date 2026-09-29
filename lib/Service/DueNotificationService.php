<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunMapper;
use OCA\Runbook\Db\RunSection;
use OCA\Runbook\Db\RunSectionMapper;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Db\RunStepMapper;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Finds assigned steps that are due tomorrow or overdue and notifies each
 * assignee according to their own Nextcloud timezone.
 *
 * Due dates are stored as UTC Unix timestamps. "Tomorrow" and "today" are
 * evaluated in the recipient's timezone, resolved through Nextcloud, falling
 * back to the server timezone when a user has no configured timezone. As a
 * result, a group-assigned step can notify members on different local days.
 *
 * Scanning is bounded: candidate steps are fetched in a bounded window with a
 * result limit, group membership is bounded by the notification service, and
 * per-recipient deduplication is enforced by the delivery ledger. Failures are
 * logged and never abort the batch.
 */
class DueNotificationService {
	private const BATCH_LIMIT = 200;

	/**
	 * Global envelope for the "due tomorrow" scan. It comfortably covers every
	 * valid timezone offset (plus or minus 14 hours) around each user's local
	 * tomorrow.
	 */
	private const DUE_LOOKBEHIND_SECONDS = 3600;
	private const DUE_LOOKAHEAD_SECONDS = 3600 * 40;

	/**
	 * Global envelope for the overdue scan: a bounded lookback window plus an
	 * upper margin for positive timezone offsets.
	 */
	private const OVERDUE_LOOKBACK_SECONDS = 86400 * 7;
	private const OVERDUE_LOOKAHEAD_SECONDS = 3600 * 15;

	public function __construct(
		private readonly RunStepMapper $runSteps,
		private readonly RunSectionMapper $runSections,
		private readonly RunMapper $runs,
		private readonly NotificationService $notifications,
		private readonly AdminSettings $settings,
		private readonly ITimeFactory $timeFactory,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}

	public function process(): void {
		if (!$this->settings->isNotificationsEnabled()) {
			return;
		}

		$this->processDueTomorrow();
		$this->processOverdue();
	}

	/**
	 * Notify each assignee for whom the step's due date falls on their local
	 * tomorrow.
	 */
	public function processDueTomorrow(): int {
		if (!$this->settings->isNotificationsEnabled()) {
			return 0;
		}

		$now = $this->timeFactory->getTime();
		$steps = $this->runSteps->findAssignedStepsDueBetween(
			$now - self::DUE_LOOKBEHIND_SECONDS,
			$now + self::DUE_LOOKAHEAD_SECONDS,
			self::BATCH_LIMIT,
		);

		return $this->dispatch($steps, NotificationService::SUBJECT_STEP_DUE);
	}

	/**
	 * Notify each assignee for whom the step is overdue in their local timezone.
	 */
	public function processOverdue(): int {
		if (!$this->settings->isNotificationsEnabled()) {
			return 0;
		}

		$now = $this->timeFactory->getTime();
		$steps = $this->runSteps->findAssignedStepsOverdue(
			$now - self::OVERDUE_LOOKBACK_SECONDS,
			$now + self::OVERDUE_LOOKAHEAD_SECONDS,
			self::BATCH_LIMIT,
		);

		return $this->dispatch($steps, NotificationService::SUBJECT_STEP_OVERDUE);
	}

	/**
	 * @param list<RunStep> $steps
	 */
	private function dispatch(array $steps, string $subject): int {
		if ($steps === []) {
			return 0;
		}

		$now = $this->timeFactory->getTime();
		$runs = $this->resolveRuns($steps);
		$delivered = 0;

		foreach ($steps as $step) {
			$run = $runs[$step->getRunSectionId()] ?? null;
			if ($run === null || $run->getStatus() !== RunStatus::Active->value) {
				continue;
			}
			$status = RunStepStatus::tryFrom($step->getStatus());
			if ($status !== RunStepStatus::Pending && $status !== RunStepStatus::InProgress) {
				continue;
			}

			foreach ($this->notifications->recipientsFor($step->getAssigneeType(), $step->getAssigneeId()) as $uid) {
				try {
					if ($this->notifyFor($run, $step, $uid, $subject, $now)) {
						$delivered++;
					}
				} catch (\Throwable $exception) {
					$this->logger->warning('Runbook due notification failed', [
						'app' => 'runbook',
						'stepId' => $step->getId(),
						'exception' => $exception,
					]);
				}
			}
		}

		return $delivered;
	}

	private function notifyFor(Run $run, RunStep $step, string $uid, string $subject, int $now): bool {
		if ($subject === NotificationService::SUBJECT_STEP_DUE) {
			return $this->isDueTomorrow($step, $uid, $now)
				&& $this->notifications->notifyStepDue($run, $step, $uid);
		}

		return $this->isOverdue($step, $uid, $now)
			&& $this->notifications->notifyStepOverdue($run, $step, $uid);
	}

	private function isDueTomorrow(RunStep $step, string $uid, int $now): bool {
		$due = $step->getDueAt();
		if ($due === null) {
			return false;
		}

		$start = $this->localDayStart($uid, $now) + 86400;

		return $due >= $start && $due < $start + 86400;
	}

	private function isOverdue(RunStep $step, string $uid, int $now): bool {
		$due = $step->getDueAt();
		if ($due === null) {
			return false;
		}

		return $due < $this->localDayStart($uid, $now);
	}

	private function localDayStart(string $uid, int $now): int {
		return (new \DateTimeImmutable('@' . $now))
			->setTimezone($this->timeZoneFor($uid))
			->setTime(0, 0, 0)
			->getTimestamp();
	}

	/**
	 * The recipient's Nextcloud timezone, falling back to the server timezone.
	 */
	private function timeZoneFor(string $uid): \DateTimeZone {
		/** @psalm-suppress DeprecatedMethod Nextcloud 33 compatibility requires this API. */
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

	/**
	 * Resolve the run of each step, keyed by run section id.
	 *
	 * @param list<RunStep> $steps
	 * @return array<int, Run>
	 */
	private function resolveRuns(array $steps): array {
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
		$runsById = [];
		foreach ($this->runs->findByIds($runIds) as $run) {
			$runsById[$run->getId()] = $run;
		}

		$bySection = [];
		foreach ($sections as $section) {
			$run = $runsById[$section->getRunId()] ?? null;
			if ($run !== null) {
				$bySection[$section->getId()] = $run;
			}
		}

		return $bySection;
	}
}
