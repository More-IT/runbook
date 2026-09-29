<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Service\LegacyMigrationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Administration endpoints for the legacy AppData migration (issue #55).
 *
 * Both endpoints are administrator-only: they are intentionally not annotated
 * with `#[NoAdminRequired]`.
 */
class MigrationController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly LegacyMigrationService $migration,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Read migration status.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{migration: array<string, mixed>}, array{}>
	 *
	 * 200: Migration status returned
	 */
	#[OpenAPI]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/admin/migration')]
	public function index(): JSONResponse {
		return new JSONResponse(['migration' => $this->migration->status()]);
	}

	/**
	 * Run one bounded migration batch now, then return the updated status.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{result: array<string, mixed>, migration: array<string, mixed>}, array{}>
	 *
	 * 200: Migration completed
	 */
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/admin/migration')]
	public function run(): JSONResponse {
		$result = $this->migration->migrateAll();

		return new JSONResponse([
			'result' => $result,
			'migration' => $this->migration->status(),
		]);
	}
}
