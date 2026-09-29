<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\ResponseDefinitions;
use OCA\Runbook\Service\RunStepService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoints for executing individual run steps.
 *
 * @psalm-import-type RunbookRunStepData from ResponseDefinitions
 */
class RunStepController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RunStepService $runStepService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Start a run step.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{step: RunbookRunStepData}, array{}>
	 *
	 * 200: Step started
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/run-steps/{id}/start')]
	public function start(): JSONResponse {
		$step = $this->runStepService->start($this->requireId('id'));

		return new JSONResponse(['step' => $step->toArray()]);
	}

	/**
	 * Update a run step.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{step: RunbookRunStepData}, array{}>
	 *
	 * 200: Step updated
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v1/run-steps/{id}')]
	public function update(): JSONResponse {
		$step = $this->runStepService->update(
			$this->requireId('id'),
			$this->body(['response', 'assigneeType', 'assigneeId', 'dueAt']),
		);

		return new JSONResponse(['step' => $step->toArray()]);
	}

	/**
	 * Complete a run step.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{step: RunbookRunStepData}, array{}>
	 *
	 * 200: Step completed
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/run-steps/{id}/complete')]
	public function complete(): JSONResponse {
		$step = $this->runStepService->complete($this->requireId('id'), $this->body(['response']));

		return new JSONResponse(['step' => $step->toArray()]);
	}

	/**
	 * Skip a run step.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{step: RunbookRunStepData}, array{}>
	 *
	 * 200: Step skipped
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/run-steps/{id}/skip')]
	public function skip(): JSONResponse {
		$step = $this->runStepService->skip($this->requireId('id'), $this->body(['reason']));

		return new JSONResponse(['step' => $step->toArray()]);
	}

	/**
	 * Reopen a run step.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{step: RunbookRunStepData}, array{}>
	 *
	 * 200: Step reopened
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/run-steps/{id}/reopen')]
	public function reopen(): JSONResponse {
		$step = $this->runStepService->reopen($this->requireId('id'));

		return new JSONResponse(['step' => $step->toArray()]);
	}

	/**
	 * Return a run step to its previous state.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{step: RunbookRunStepData}, array{}>
	 *
	 * 200: Step returned
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/run-steps/{id}/return')]
	public function returnStep(): JSONResponse {
		$step = $this->runStepService->returnStep($this->requireId('id'), $this->body(['reason']));

		return new JSONResponse(['step' => $step->toArray()]);
	}
}
