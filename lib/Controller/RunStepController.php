<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Service\RunStepService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoints for executing individual run steps.
 *
 * @phpstan-import-type RunStepData from RunStep
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
	 * @return JSONResponse<Http::STATUS_OK, array{step: RunStepData}, array{}>
	 */
	#[NoAdminRequired]
	public function start(): JSONResponse {
		$step = $this->runStepService->start($this->requireId('id'));

		return new JSONResponse(['step' => $step->toArray()]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{step: RunStepData}, array{}>
	 */
	#[NoAdminRequired]
	public function update(): JSONResponse {
		$step = $this->runStepService->update(
			$this->requireId('id'),
			$this->body(['response', 'assigneeType', 'assigneeId', 'dueAt']),
		);

		return new JSONResponse(['step' => $step->toArray()]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{step: RunStepData}, array{}>
	 */
	#[NoAdminRequired]
	public function complete(): JSONResponse {
		$step = $this->runStepService->complete($this->requireId('id'), $this->body(['response']));

		return new JSONResponse(['step' => $step->toArray()]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{step: RunStepData}, array{}>
	 */
	#[NoAdminRequired]
	public function skip(): JSONResponse {
		$step = $this->runStepService->skip($this->requireId('id'), $this->body(['reason']));

		return new JSONResponse(['step' => $step->toArray()]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{step: RunStepData}, array{}>
	 */
	#[NoAdminRequired]
	public function reopen(): JSONResponse {
		$step = $this->runStepService->reopen($this->requireId('id'));

		return new JSONResponse(['step' => $step->toArray()]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{step: RunStepData}, array{}>
	 */
	#[NoAdminRequired]
	public function returnStep(): JSONResponse {
		$step = $this->runStepService->returnStep($this->requireId('id'), $this->body(['reason']));

		return new JSONResponse(['step' => $step->toArray()]);
	}
}
