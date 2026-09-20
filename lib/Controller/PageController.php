<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Renders the main Runbook application page that mounts the Vue frontend.
 */
class PageController extends Controller {
	public function __construct(string $appName, IRequest $request) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return TemplateResponse<Http::STATUS_OK, array{}>
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		return new TemplateResponse(
			Application::APP_ID,
			'main',
			[],
			TemplateResponse::RENDER_AS_USER,
		);
	}
}
