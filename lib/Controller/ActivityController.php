<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\ActivityEvent;
use OCA\Runbook\ResponseDefinitions;
use OCA\Runbook\Service\RunService;
use OCA\Runbook\Service\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Read-only JSON endpoint for the append-only run activity history.
 *
 * @psalm-import-type RunbookActivityData from ResponseDefinitions
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
	 * Return the activity history for a run
	 *
	 * @param int $id Run identifier.
	 * @param int|null $limit Maximum number of events.
	 * @param string|null $order Sort order.
	 * @return JSONResponse<Http::STATUS_OK, array{activity: list<RunbookActivityData>}, array{}>
	 *
	 * 200: Activity history returned
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/runs/{id}/activity')]
	public function index(?int $id = null, ?int $limit = null, ?string $order = null): JSONResponse {
		$id ??= $this->requireId('id');
		$limit ??= $this->optionalInt('limit');
		$rawOrder = $this->param('order');
		if ($order === null && $rawOrder !== null) {
			$order = is_string($rawOrder) ? $rawOrder : null;
		}
		if ($id < 0 || ($limit !== null && $limit < 0)) {
			throw new ValidationException('invalid_field');
		}
		$events = array_map(
			static fn (ActivityEvent $event): array => $event->toArray(),
			$this->runService->listActivity($id, $limit, $order),
		);

		return new JSONResponse(['activity' => $events]);
	}
}
