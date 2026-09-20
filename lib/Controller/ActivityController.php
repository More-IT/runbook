<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\ActivityEvent;
use OCA\Runbook\Service\RunService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Read-only JSON endpoint for the append-only run activity history.
 *
 * @phpstan-import-type ActivityData from ActivityEvent
 */
class ActivityController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RunService $runService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{activity: list<ActivityData>}, array{}>
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$events = array_map(
			static fn (ActivityEvent $event): array => $event->toArray(),
			$this->runService->listActivity($this->requireId('id'), $this->optionalInt('limit')),
		);

		return new JSONResponse(['activity' => $events]);
	}
}
