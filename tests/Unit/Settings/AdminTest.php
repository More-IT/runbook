<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Settings;

use OCA\Runbook\Settings\Admin;
use OCP\AppFramework\Http\TemplateResponse;
use PHPUnit\Framework\TestCase;

class AdminTest extends TestCase {
	public function testMetadata(): void {
		$admin = new Admin();

		self::assertSame('runbook', $admin->getSection());
		self::assertSame(50, $admin->getPriority());
	}

	public function testFormRendersSettingsTemplate(): void {
		$form = (new Admin())->getForm();

		self::assertInstanceOf(TemplateResponse::class, $form);
		self::assertSame('runbook', $form->getApp());
		self::assertSame('admin-settings', $form->getTemplateName());
	}
}
