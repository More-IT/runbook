<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Minimal, read-only status endpoint of the Runbook foundation.
 *
 * It exists solely to verify that the Vue frontend can reach the PHP
 * backend. It does not implement any business logic.
 */
class StatusController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IAppManager $appManager,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Report the health of the Runbook foundation.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{status: string, app: string, version: string}, array{}>
	 */
	#[NoAdminRequired]
	public function status(): JSONResponse {
		/** @var array{status: string, app: string, version: string} $data */
		$data = [
			'status' => 'ok',
			'app' => Application::APP_ID,
			'version' => $this->appManager->getAppVersion(Application::APP_ID),
		];

		return new JSONResponse($data);
	}
}
