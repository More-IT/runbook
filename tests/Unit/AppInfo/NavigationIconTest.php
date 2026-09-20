<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the Runbook navigation logo.
 *
 * The main navigation renders its icon directly on the colored/dark header, so
 * it must use a dedicated white icon instead of the dark app icon. Using the
 * dark app icon made the logo invisible on dark themes.
 */
class NavigationIconTest extends TestCase {
	public function testNavigationUsesDedicatedWhiteIcon(): void {
		$root = dirname(__DIR__, 3);

		$info = (string)file_get_contents($root . '/appinfo/info.xml');
		self::assertStringContainsString('<icon>app-nav.svg</icon>', $info);

		$iconPath = $root . '/img/app-nav.svg';
		self::assertFileExists($iconPath);

		$icon = (string)file_get_contents($iconPath);
		self::assertStringContainsString('fill="#ffffff"', $icon);
	}
}
