<?php

declare(strict_types=1);

namespace OCA\Runbook\BackgroundJob;

use OCA\Runbook\Service\LegacyMigrationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Retries the legacy AppData → Files evidence migration (issue #55).
 *
 * Runs hourly and processes a bounded batch of runs that still have AppData
 * attachments. The migration is idempotent and resumable, so an interrupted
 * batch simply continues on the next run; failures are recorded per run and
 * attachment and never abort the job.
 */
class LegacyMigrationJob extends TimedJob {
	private const INTERVAL_SECONDS = 3600;
	private const MAX_RUNS_PER_RUN = 50;

	public function __construct(
		ITimeFactory $time,
		private readonly LegacyMigrationService $migration,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	/**
	 * @param mixed $argument
	 */
	protected function run($argument): void {
		try {
			$this->migration->migrateAll(self::MAX_RUNS_PER_RUN);
		} catch (\Throwable $exception) {
			$this->logger->warning('Runbook legacy migration job failed', [
				'app' => 'runbook',
				'exception' => $exception,
			]);
		}
	}
}
