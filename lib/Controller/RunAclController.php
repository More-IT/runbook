<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\RunAcl;
use OCA\Runbook\ResponseDefinitions;
use OCA\Runbook\Service\RunAclService;
use OCA\Runbook\Service\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoints for a run's participant and viewer list.
 *
 * @psalm-import-type RunbookRunAclData from ResponseDefinitions
 */
class RunAclController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RunAclService $runAclService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Read a run ACL.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{owner: string, entries: list<RunbookRunAclData>}, array{}>
	 *
	 * 200: ACL returned
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/runs/{id}/acl')]
	public function index(): JSONResponse {
		$result = $this->runAclService->getAcl($this->requireId('id'));

		return new JSONResponse([
			'owner' => $result['owner'],
			'entries' => array_map(
				static fn (RunAcl $entry): array => $entry->toArray(),
				$result['entries'],
			),
		]);
	}

	/**
	 * Replace the complete ACL of a run.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{owner: string, entries: list<RunbookRunAclData>}, array{}>
	 *
	 * 200: ACL updated
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'PUT', url: '/api/v1/runs/{id}/acl')]
	public function update(): JSONResponse {
		$entries = $this->body(['entries'])['entries'] ?? null;
		if (!is_array($entries)) {
			throw new ValidationException('invalid_acl_entries');
		}

		$result = $this->runAclService->replaceAcl($this->requireId('id'), $entries);

		return new JSONResponse([
			'owner' => $result['owner'],
			'entries' => array_map(
				static fn (RunAcl $entry): array => $entry->toArray(),
				$result['entries'],
			),
		]);
	}
}
