<?php

declare(strict_types=1);

namespace OCA\Runbook\BackgroundJob;

use OCA\Runbook\Service\FilesCleanupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Retries removal of managed run folders that outlived their run (issue #54).
 *
 * Runs hourly. Each pending cleanup record is re-resolved by the authoritative
 * identity `(view_uid, storage_id, file_id)` in the run owner's view; untracked
 * content is never deleted and failures stay durable for the next attempt.
 */
class FilesCleanupRetryJob extends TimedJob {
	private const INTERVAL_SECONDS = 3600;

	public function __construct(
		ITimeFactory $time,
		private readonly FilesCleanupService $cleanup,
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
			$this->cleanup->processPending();
		} catch (\Throwable $exception) {
			$this->logger->warning('Runbook files cleanup job failed', [
				'app' => 'runbook',
				'exception' => $exception,
			]);
		}
	}
}
