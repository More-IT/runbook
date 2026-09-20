<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\Template;
use OCA\Runbook\Db\TemplateSection;
use OCA\Runbook\Db\TemplateStep;
use OCA\Runbook\Service\TemplateService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoints for template metadata and lifecycle.
 *
 * @phpstan-import-type TemplateData from Template
 * @phpstan-import-type SectionData from TemplateSection
 * @phpstan-import-type StepData from TemplateStep
 */
class TemplateController extends ApiController {
	private const FIELDS = ['title', 'description'];

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TemplateService $templateService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{templates: list<TemplateData>}, array{}>
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$templates = array_map(
			static fn (Template $template): array => $template->toArray(),
			$this->templateService->listTemplates(),
		);

		return new JSONResponse(['templates' => $templates]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_CREATED, array{template: TemplateData}, array{}>
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$template = $this->templateService->createTemplate($this->body(self::FIELDS));

		return new JSONResponse(['template' => $template->toArray()], Http::STATUS_CREATED);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{template: TemplateData, sections: list<array{section: SectionData, steps: list<StepData>}>, permissions: array{role: string|null, canView: bool, canExecute: bool, canEdit: bool, canManageAcl: bool, canDelete: bool}}, array{}>
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		$id = $this->requireId('id');
		$template = $this->templateService->getTemplate($id);

		$sections = [];
		foreach ($this->templateService->getSections($id) as $section) {
			$steps = array_map(
				static fn (TemplateStep $step): array => $step->toArray(),
				$this->templateService->getSteps($section->getId()),
			);
			$sections[] = ['section' => $section->toArray(), 'steps' => $steps];
		}

		return new JSONResponse([
			'template' => $template->toArray(),
			'sections' => $sections,
			'permissions' => $this->templateService->getPermissions($template),
		]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{template: TemplateData}, array{}>
	 */
	#[NoAdminRequired]
	public function update(): JSONResponse {
		$template = $this->templateService->updateTemplate($this->requireId('id'), $this->body(self::FIELDS));

		return new JSONResponse(['template' => $template->toArray()]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{success: bool}, array{}>
	 */
	#[NoAdminRequired]
	public function destroy(): JSONResponse {
		$this->templateService->deleteTemplate($this->requireId('id'));

		return new JSONResponse(['success' => true]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{template: TemplateData}, array{}>
	 */
	#[NoAdminRequired]
	public function publish(): JSONResponse {
		$template = $this->templateService->publishTemplate($this->requireId('id'));

		return new JSONResponse(['template' => $template->toArray()]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{template: TemplateData}, array{}>
	 */
	#[NoAdminRequired]
	public function archive(): JSONResponse {
		$template = $this->templateService->archiveTemplate($this->requireId('id'));

		return new JSONResponse(['template' => $template->toArray()]);
	}
}
