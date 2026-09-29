<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\RunStep;
use OCA\Runbook\ResponseDefinitions;
use OCA\Runbook\Service\RunService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoint for editing a run section's notes and returning its steps.
 *
 * @psalm-import-type RunbookRunSectionData from ResponseDefinitions
 * @psalm-import-type RunbookRunStepData from ResponseDefinitions
 */
class RunSectionController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RunService $runService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Update a run section.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{section: RunbookRunSectionData}, array{}>
	 *
	 * 200: Section updated
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v1/run-sections/{id}')]
	public function update(): JSONResponse {
		$section = $this->runService->updateSectionNotes(
			$this->requireId('id'),
			$this->body(['notes']),
		);

		return new JSONResponse(['section' => $section->toArray()]);
	}

	/**
	 * Return a section to its previous state.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{steps: list<RunbookRunStepData>}, array{}>
	 *
	 * 200: Section returned
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/run-sections/{id}/return')]
	public function returnSection(): JSONResponse {
		$steps = $this->runService->returnSection($this->requireId('id'), $this->body(['reason']));

		return new JSONResponse([
			'steps' => array_map(static fn (RunStep $step): array => $step->toArray(), $steps),
		]);
	}
}
