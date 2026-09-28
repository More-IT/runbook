<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\Template;
use OCA\Runbook\Db\TemplateSection;
use OCA\Runbook\Db\TemplateStep;
use OCA\Runbook\Service\TemplateExportService;
use OCA\Runbook\Service\TemplateImportService;
use OCA\Runbook\Service\TemplateService;
use OCA\Runbook\Service\ValidationException;
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
 * @phpstan-import-type ExportDocument from TemplateExportService
 */
class TemplateController extends ApiController {
	private const FIELDS = ['title', 'description'];

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TemplateService $templateService,
		private readonly TemplateExportService $templateExportService,
		private readonly TemplateImportService $templateImportService,
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
	 * @return JSONResponse<Http::STATUS_OK, array{template: TemplateData, sections: list<array{section: SectionData, steps: list<StepData>}>, permissions: array{role: string|null, canView: bool, canExecute: bool, canEdit: bool, canManageAcl: bool, canDelete: bool}, destination: array{configured: bool, valid: bool, path: string|null, configuredBy: string|null}}, array{}>
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
			'destination' => $this->templateService->getTemplateDestinationState($template),
		]);
	}

	/**
	 * Save (or replace) the optional destination folder reference of a template
	 * (issue #48).
	 *
	 * The client submits a user-visible path from its own Files view; the folder
	 * is re-resolved and its identity captured server-side. Edit permission and
	 * the non-archived lifecycle rule are enforced by the service; a failed save
	 * never replaces a previously valid destination.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{template: TemplateData, destination: array{configured: bool, valid: bool, path: string|null, configuredBy: string|null}}, array{}>
	 */
	#[NoAdminRequired]
	public function updateDestination(): JSONResponse {
		$path = $this->request->getParam('path');
		if (!is_string($path) || trim($path) === '') {
			throw new ValidationException('invalid_field');
		}

		$template = $this->templateService->setTemplateDestination($this->requireId('id'), $path);

		return new JSONResponse([
			'template' => $template->toArray(),
			'destination' => $this->templateService->getTemplateDestinationState($template),
		]);
	}

	/**
	 * Clear the optional destination folder reference of a template (issue #48).
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{template: TemplateData, destination: array{configured: bool, valid: bool, path: string|null, configuredBy: string|null}}, array{}>
	 */
	#[NoAdminRequired]
	public function clearDestination(): JSONResponse {
		$template = $this->templateService->clearTemplateDestination($this->requireId('id'));

		return new JSONResponse([
			'template' => $template->toArray(),
			'destination' => $this->templateService->getTemplateDestinationState($template),
		]);
	}

	/**
	 * Export a template the current user may view as a portable JSON document.
	 *
	 * The response body is the export document itself (no wrapper), so the
	 * downloaded file is exactly the contract issue #31 will import. Requires the
	 * same view permission as the detail endpoint; unauthorized requests are
	 * rejected without exposing any template content.
	 *
	 * @return JSONResponse<Http::STATUS_OK, ExportDocument, array{}>
	 */
	#[NoAdminRequired]
	public function export(): JSONResponse {
		return new JSONResponse($this->templateExportService->export($this->requireId('id')));
	}

	/**
	 * Import a portable template export document as a new DRAFT template.
	 *
	 * The request body must be the export document itself (the same shape the
	 * export endpoint returns), not a wrapper. Nextcloud decodes a JSON request
	 * body into `getParams()`, so an authored empty configuration object `{}`
	 * and an empty array `[]` both arrive as an empty PHP array and are treated
	 * identically. Creation policy and ownership are enforced by the authoring
	 * service, never by the client.
	 *
	 * The size limit is the canonical compact UTF-8 encoding of the decoded
	 * document, enforced by the service. The installed `IRequest` API exposes no
	 * raw request body, so a client-declared `Content-Length` is deliberately not
	 * consulted: it is not proof of the actual body length.
	 *
	 * @return JSONResponse<Http::STATUS_CREATED, array{template: TemplateData}, array{}>
	 */
	#[NoAdminRequired]
	public function import(): JSONResponse {
		$template = $this->templateImportService->import($this->request->getParams());

		return new JSONResponse(['template' => $template->toArray()], Http::STATUS_CREATED);
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

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{template: TemplateData}, array{}>
	 */
	#[NoAdminRequired]
	public function unarchive(): JSONResponse {
		$template = $this->templateService->unarchiveTemplate($this->requireId('id'));

		return new JSONResponse(['template' => $template->toArray()]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_CREATED, array{template: TemplateData}, array{}>
	 */
	#[NoAdminRequired]
	public function duplicate(): JSONResponse {
		$template = $this->templateService->duplicateTemplate($this->requireId('id'));

		return new JSONResponse(['template' => $template->toArray()], Http::STATUS_CREATED);
	}
}
