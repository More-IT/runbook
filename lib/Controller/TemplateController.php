<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\Template;
use OCA\Runbook\Db\TemplateStep;
use OCA\Runbook\ResponseDefinitions;
use OCA\Runbook\Service\TemplateExportService;
use OCA\Runbook\Service\TemplateImportService;
use OCA\Runbook\Service\TemplateService;
use OCA\Runbook\Service\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoints for template metadata and lifecycle.
 *
 * @psalm-import-type RunbookTemplateData from ResponseDefinitions
 * @psalm-import-type RunbookSectionData from ResponseDefinitions
 * @psalm-import-type RunbookStepData from ResponseDefinitions
 * @psalm-import-type RunbookExportDocument from ResponseDefinitions
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
	 * List templates.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{templates: list<RunbookTemplateData>}, array{}>
	 *
	 * 200: Templates returned
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/templates')]
	public function index(): JSONResponse {
		$templates = array_map(
			static fn (Template $template): array => $template->toArray(),
			$this->templateService->listTemplates(),
		);

		return new JSONResponse(['templates' => $templates]);
	}

	/**
	 * Create a template.
	 *
	 * @return JSONResponse<Http::STATUS_CREATED, array{template: RunbookTemplateData}, array{}>
	 *
	 * 201: Template created
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/templates')]
	public function create(): JSONResponse {
		$template = $this->templateService->createTemplate($this->body(self::FIELDS));

		return new JSONResponse(['template' => $template->toArray()], Http::STATUS_CREATED);
	}

	/**
	 * Show a template.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{template: RunbookTemplateData, sections: list<array{section: RunbookSectionData, steps: list<RunbookStepData>}>, permissions: array{role: string|null, canView: bool, canExecute: bool, canEdit: bool, canManageAcl: bool, canDelete: bool}, destination: array{configured: bool, valid: bool, path: string|null, configuredBy: string|null}}, array{}>
	 *
	 * 200: Template returned
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/templates/{id}')]
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
	 * @param int $id Template identifier.
	 * @param string $path Destination path.
	 * @return JSONResponse<Http::STATUS_OK, array{template: RunbookTemplateData, destination: array{configured: bool, valid: bool, path: string|null, configuredBy: string|null}}, array{}>
	 *
	 * 200: Destination saved
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/templates/{id}/destination')]
	public function updateDestination(?int $id = null, ?string $path = null): JSONResponse {
		$id ??= $this->requireId('id');
		if ($path === null) {
			$value = $this->param('path');
			if (!is_string($value)) {
				throw new ValidationException('invalid_field');
			}
			$path = $value;
		}
		if (trim($path) === '') {
			throw new ValidationException('invalid_field');
		}

		$template = $this->templateService->setTemplateDestination($id, $path);

		return new JSONResponse([
			'template' => $template->toArray(),
			'destination' => $this->templateService->getTemplateDestinationState($template),
		]);
	}

	/**
	 * Clear the optional destination folder reference of a template (issue #48).
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{template: RunbookTemplateData, destination: array{configured: bool, valid: bool, path: string|null, configuredBy: string|null}}, array{}>
	 *
	 * 200: Destination cleared
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/templates/{id}/destination')]
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
	 * Export a template.
	 *
	 * @return JSONResponse<Http::STATUS_OK, RunbookExportDocument, array{}>
	 *
	 * 200: Template exported
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/templates/{id}/export')]
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
	 * @return JSONResponse<Http::STATUS_CREATED, array{template: RunbookTemplateData}, array{}>
	 *
	 * 201: Template imported
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/templates/import')]
	public function import(): JSONResponse {
		$template = $this->templateImportService->import($this->requestParams());

		return new JSONResponse(['template' => $template->toArray()], Http::STATUS_CREATED);
	}

	/**
	 * Update a template.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{template: RunbookTemplateData}, array{}>
	 *
	 * 200: Template updated
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v1/templates/{id}')]
	public function update(): JSONResponse {
		$template = $this->templateService->updateTemplate($this->requireId('id'), $this->body(self::FIELDS));

		return new JSONResponse(['template' => $template->toArray()]);
	}

	/**
	 * Delete a template.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{success: bool}, array{}>
	 *
	 * 200: Template deleted
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/templates/{id}')]
	public function destroy(): JSONResponse {
		$this->templateService->deleteTemplate($this->requireId('id'));

		return new JSONResponse(['success' => true]);
	}

	/**
	 * Publish a template.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{template: RunbookTemplateData}, array{}>
	 *
	 * 200: Template published
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/templates/{id}/publish')]
	public function publish(): JSONResponse {
		$template = $this->templateService->publishTemplate($this->requireId('id'));

		return new JSONResponse(['template' => $template->toArray()]);
	}

	/**
	 * Archive a template.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{template: RunbookTemplateData}, array{}>
	 *
	 * 200: Template archived
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/templates/{id}/archive')]
	public function archive(): JSONResponse {
		$template = $this->templateService->archiveTemplate($this->requireId('id'));

		return new JSONResponse(['template' => $template->toArray()]);
	}

	/**
	 * Unarchive a template.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{template: RunbookTemplateData}, array{}>
	 *
	 * 200: Template unarchived
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/templates/{id}/unarchive')]
	public function unarchive(): JSONResponse {
		$template = $this->templateService->unarchiveTemplate($this->requireId('id'));

		return new JSONResponse(['template' => $template->toArray()]);
	}

	/**
	 * Duplicate a template.
	 *
	 * @return JSONResponse<Http::STATUS_CREATED, array{template: RunbookTemplateData}, array{}>
	 *
	 * 201: Template duplicated
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/templates/{id}/duplicate')]
	public function duplicate(): JSONResponse {
		$template = $this->templateService->duplicateTemplate($this->requireId('id'));

		return new JSONResponse(['template' => $template->toArray()], Http::STATUS_CREATED);
	}
}
