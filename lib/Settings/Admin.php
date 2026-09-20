<?php

declare(strict_types=1);

namespace OCA\Runbook\Settings;

use OCA\Runbook\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * Administration settings form for Runbook.
 *
 * The form itself is a Vue application; all values are read from and written to
 * the backend through the admin-only settings API, which validates them
 * server-side.
 */
class Admin implements ISettings {
	/**
	 * @return TemplateResponse<Http::STATUS_OK, array{}>
	 */
	public function getForm(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'admin-settings');
	}

	public function getSection(): string {
		return 'runbook';
	}

	public function getPriority(): int {
		return 50;
	}
}
