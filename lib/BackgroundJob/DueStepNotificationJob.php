<?php

declare(strict_types=1);

namespace OCA\Runbook\BackgroundJob;

use OCA\Runbook\Service\DueNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Sends due and overdue step notifications.
 *
 * Runs hourly. The actual scanning and delivery is bounded and deduplicated in
 * {@see DueNotificationService}; failures are logged and never abort the job.
 */
class DueStepNotificationJob extends TimedJob {
	private const INTERVAL_SECONDS = 3600;

	public function __construct(
		ITimeFactory $time,
		private readonly DueNotificationService $dueNotifications,
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
			$this->dueNotifications->process();
		} catch (\Throwable $exception) {
			$this->logger->warning('Runbook due notification job failed', [
				'app' => 'runbook',
				'exception' => $exception,
			]);
		}
	}
}
