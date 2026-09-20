<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\TemplateStep;
use OCA\Runbook\Service\TemplateService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoints for template steps.
 *
 * @phpstan-import-type StepData from TemplateStep
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
	 * @return JSONResponse<Http::STATUS_CREATED, array{step: StepData}, array{}>
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$step = $this->templateService->createStep(
			$this->requireId('sectionId'),
			$this->body(self::FIELDS),
		);

		return new JSONResponse(['step' => $step->toArray()], Http::STATUS_CREATED);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{step: StepData}, array{}>
	 */
	#[NoAdminRequired]
	public function update(): JSONResponse {
		$step = $this->templateService->updateStep($this->requireId('id'), $this->body(self::FIELDS));

		return new JSONResponse(['step' => $step->toArray()]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{success: bool}, array{}>
	 */
	#[NoAdminRequired]
	public function destroy(): JSONResponse {
		$this->templateService->deleteStep($this->requireId('id'));

		return new JSONResponse(['success' => true]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{steps: list<StepData>}, array{}>
	 */
	#[NoAdminRequired]
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
