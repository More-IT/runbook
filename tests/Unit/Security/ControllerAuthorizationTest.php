<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Security;

use OCA\Runbook\Controller\AclController;
use OCA\Runbook\Controller\ActivityController;
use OCA\Runbook\Controller\AdminSettingsController;
use OCA\Runbook\Controller\AttachmentController;
use OCA\Runbook\Controller\CommentController;
use OCA\Runbook\Controller\PrincipalController;
use OCA\Runbook\Controller\RunAclController;
use OCA\Runbook\Controller\RunController;
use OCA\Runbook\Controller\RunStepController;
use OCA\Runbook\Controller\SectionController;
use OCA\Runbook\Controller\StepController;
use OCA\Runbook\Controller\TemplateController;
use OCA\Runbook\Controller\WorkController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use PHPUnit\Framework\TestCase;

/**
 * Structural regression test for the controller authorization matrix.
 *
 * Every Runbook API endpoint is an authenticated user endpoint
 * (`#[NoAdminRequired]`), except the administration settings read/write
 * endpoints, which must stay administrator-only. Mutations keep CSRF protection
 * because none of them opt out of it.
 */
class ControllerAuthorizationTest extends TestCase {
	/**
	 * @return array<class-string, list<string>>
	 */
	private function userEndpoints(): array {
		return [
			TemplateController::class => ['index', 'create', 'show', 'update', 'destroy', 'publish', 'archive', 'export', 'import'],
			SectionController::class => ['create', 'update', 'destroy', 'reorder'],
			StepController::class => ['create', 'update', 'destroy', 'reorder'],
			AclController::class => ['index', 'update'],
			PrincipalController::class => ['index'],
			RunController::class => ['index', 'create', 'show', 'complete', 'cancel', 'reopen'],
			RunStepController::class => ['start', 'update', 'complete', 'skip', 'reopen'],
			RunAclController::class => ['index', 'update'],
			CommentController::class => ['index', 'create', 'update', 'destroy'],
			AttachmentController::class => ['index', 'create', 'show', 'destroy'],
			ActivityController::class => ['index'],
			WorkController::class => ['myWork', 'overview'],
			AdminSettingsController::class => ['features'],
		];
	}

	public function testUserEndpointsAreAvailableToAuthenticatedUsers(): void {
		foreach ($this->userEndpoints() as $class => $methods) {
			foreach ($methods as $method) {
				self::assertTrue(
					$this->hasAttribute($class, $method, NoAdminRequired::class),
					sprintf('%s::%s must be available to authenticated users', $class, $method),
				);
			}
		}
	}

	public function testAdminSettingsReadAndWriteAreAdminOnly(): void {
		foreach (['index', 'update'] as $method) {
			self::assertFalse(
				$this->hasAttribute(AdminSettingsController::class, $method, NoAdminRequired::class),
				sprintf('AdminSettingsController::%s must be administrator only', $method),
			);
		}
	}

	public function testAdminSettingsFeaturesAreUserAccessible(): void {
		self::assertTrue($this->hasAttribute(AdminSettingsController::class, 'features', NoAdminRequired::class));
	}

	public function testImportAndExportAreNotPublicAndKeepCsrfProtection(): void {
		foreach (['export', 'import'] as $method) {
			self::assertFalse(
				$this->hasAttribute(TemplateController::class, $method, PublicPage::class),
				sprintf('TemplateController::%s must require an authenticated user', $method),
			);
			self::assertFalse(
				$this->hasAttribute(TemplateController::class, $method, NoCSRFRequired::class),
				sprintf('TemplateController::%s must keep the default CSRF protection', $method),
			);
		}
	}

	/**
	 * @param class-string $class
	 * @param class-string $attribute
	 */
	private function hasAttribute(string $class, string $method, string $attribute): bool {
		$reflection = new \ReflectionMethod($class, $method);
		foreach ($reflection->getAttributes() as $found) {
			if ($found->getName() === $attribute) {
				return true;
			}
		}

		return false;
	}
}
