<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\TemplateStep;
use OCA\Runbook\ResponseDefinitions;
use OCA\Runbook\Service\TemplateService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoints for template steps.
 *
 * @psalm-import-type RunbookStepData from ResponseDefinitions
 */
class StepController extends ApiController {
	private const FIELDS = [
		'title',
		'description',
		'type',
		'required',
		'config',
		'defaultAssignee',
		'dueOffset',
	];

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TemplateService $templateService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Create a template step.
	 *
	 * @return JSONResponse<Http::STATUS_CREATED, array{step: RunbookStepData}, array{}>
	 *
	 * 201: Step created
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/sections/{sectionId}/steps')]
	public function create(): JSONResponse {
		$step = $this->templateService->createStep(
			$this->requireId('sectionId'),
			$this->body(self::FIELDS),
		);

		return new JSONResponse(['step' => $step->toArray()], Http::STATUS_CREATED);
	}

	/**
	 * Update a template step.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{step: RunbookStepData}, array{}>
	 *
	 * 200: Step updated
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v1/steps/{id}')]
	public function update(): JSONResponse {
		$step = $this->templateService->updateStep($this->requireId('id'), $this->body(self::FIELDS));

		return new JSONResponse(['step' => $step->toArray()]);
	}

	/**
	 * Delete a template step.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{success: bool}, array{}>
	 *
	 * 200: Step deleted
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/steps/{id}')]
	public function destroy(): JSONResponse {
		$this->templateService->deleteStep($this->requireId('id'));

		return new JSONResponse(['success' => true]);
	}

	/**
	 * Reorder template steps.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{steps: list<RunbookStepData>}, array{}>
	 *
	 * 200: Steps reordered
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/steps/{id}/reorder')]
	public function reorder(): JSONResponse {
		$steps = $this->templateService->reorderStep(
			$this->requireId('id'),
			$this->requireInt('position'),
		);

		return new JSONResponse([
			'steps' => array_map(
				static fn (TemplateStep $step): array => $step->toArray(),
				$steps,
			),
		]);
	}
}
