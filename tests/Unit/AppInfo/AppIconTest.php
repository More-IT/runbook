<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\AppInfo;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SimpleXMLElement;
use SplFileInfo;

/**
 * Regression coverage for the Runbook theme-aware app icons.
 *
 * Convention (matching Nextcloud Forms/Tables/Deck):
 *   - img/app.svg is the light (white) variant and is declared in info.xml, so
 *     the main navigation and the installed-apps list render it white and let
 *     Nextcloud's background-invert-if-bright filter darken it on light
 *     backgrounds;
 *   - img/app-dark.svg is the dark (black) variant used by Administration
 *     Settings and the Dashboard widget, which apply
 *     background-invert-if-dark and turn it white in dark themes.
 *
 * The previous v0.1.1 configuration was inverted: app.svg was the dark variant
 * and a custom app-nav.svg carried the light variant. Nextcloud resolves the
 * installed-apps list and the default app icon from app.svg, so the dark icon
 * leaked into light contexts and the list rendered incorrectly. The custom
 * app-nav.svg is not a Nextcloud convention and was removed.
 */
class AppIconTest extends TestCase {
	private function root(): string {
		return dirname(__DIR__, 3);
	}

	private function raw(string $file): string {
		return (string)file_get_contents($this->root() . '/img/' . $file);
	}

	private function loadSvg(string $file): SimpleXMLElement {
		$svg = simplexml_load_file($this->root() . '/img/' . $file);
		if ($svg === false) {
			self::fail($file . ' must be well-formed SVG');
		}

		return $svg;
	}

	/**
	 * @return list<string>
	 */
	private function geometry(SimpleXMLElement $svg): array {
		$paths = [];
		foreach ($svg->path as $path) {
			$paths[] = (string)preg_replace('/\s+/', '', (string)$path['d']);
		}

		return $paths;
	}

	public function testStandardIconAssetsExist(): void {
		self::assertFileExists($this->root() . '/img/app.svg');
		self::assertFileExists($this->root() . '/img/app-dark.svg');
	}

	public function testAppNavIconWasRemoved(): void {
		self::assertFileDoesNotExist($this->root() . '/img/app-nav.svg');
	}

	public function testNoAppNavReferencesRemain(): void {
		$files = array_merge(
			[$this->root() . '/appinfo/info.xml', $this->root() . '/README.md'],
			$this->phpFiles($this->root() . '/lib'),
			$this->phpFiles($this->root() . '/build'),
		);

		foreach ($files as $file) {
			self::assertStringNotContainsString(
				'app-nav',
				(string)file_get_contents($file),
				$file . ' must not reference app-nav.svg',
			);
		}
	}

	public function testNavigationDeclaresTheLightIcon(): void {
		$info = (string)file_get_contents($this->root() . '/appinfo/info.xml');

		self::assertStringContainsString('<icon>app.svg</icon>', $info);
		self::assertStringNotContainsString('app-dark.svg', $info);
		self::assertStringNotContainsString('app-nav', $info);
	}

	public function testAppSvgIsTheLightVariant(): void {
		$svg = $this->loadSvg('app.svg');

		self::assertNotSame([], $this->geometry($svg));
		foreach ($svg->path as $path) {
			self::assertSame('#ffffff', strtolower((string)$path['fill']));
		}
		// The inline path fills are authoritative; a root fill is optional and
		// must never contradict them.
		$rootFill = strtolower((string)$svg['fill']);
		if ($rootFill !== '') {
			self::assertSame('#ffffff', $rootFill);
		}
	}

	public function testAppDarkSvgIsTheDarkVariant(): void {
		$svg = $this->loadSvg('app-dark.svg');

		self::assertNotSame([], $this->geometry($svg));
		foreach ($svg->path as $path) {
			self::assertSame('#000000', strtolower((string)$path['fill']));
		}
		$rootFill = strtolower((string)$svg['fill']);
		if ($rootFill !== '') {
			self::assertSame('#000000', $rootFill);
		}
	}

	public function testIconsHaveBrowserCompatibleStructure(): void {
		foreach (['app.svg', 'app-dark.svg'] as $file) {
			$raw = $this->raw($file);
			self::assertStringStartsWith('<svg', ltrim($raw), $file . ' must start with the svg root element');
			self::assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $raw);
			self::assertStringNotContainsString('<style', $raw, $file . ' must not embed internal CSS');
			self::assertStringNotContainsString('class="', $raw, $file . ' must not rely on CSS classes');
			self::assertStringNotContainsString('enable-background', $raw, $file . ' must not carry Illustrator leftovers');

			$svg = $this->loadSvg($file);
			self::assertSame('svg', $svg->getName());
			self::assertMatchesRegularExpression('/^0 0 \d+ \d+$/', (string)$svg['viewBox']);
			self::assertSame(0, count($svg->g), $file . ' must not wrap paths in a group');
			self::assertSame(0, count($svg->style), $file . ' must not declare a style element');
			self::assertGreaterThanOrEqual(1, count($svg->path));
		}
	}

	public function testLightAndDarkVariantsShareGeometry(): void {
		$light = $this->geometry($this->loadSvg('app.svg'));
		$dark = $this->geometry($this->loadSvg('app-dark.svg'));

		self::assertSame($light, $dark);
	}

	public function testSettingsAndWidgetUseTheDarkIcon(): void {
		self::assertStringContainsString(
			'app-dark.svg',
			(string)file_get_contents($this->root() . '/lib/Settings/Section.php'),
		);
		self::assertStringContainsString(
			'app-dark.svg',
			(string)file_get_contents($this->root() . '/lib/Dashboard/Widget.php'),
		);
	}

	public function testPackageValidationRequiresBothIcons(): void {
		$validation = (string)file_get_contents($this->root() . '/build/validate-package.php');

		self::assertStringContainsString("'img/app.svg'", $validation);
		self::assertStringContainsString("'img/app-dark.svg'", $validation);
	}

	/**
	 * @return list<string>
	 */
	private function phpFiles(string $directory): array {
		$files = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
		);
		foreach ($iterator as $item) {
			if ($item instanceof SplFileInfo && $item->isFile() && $item->getExtension() === 'php') {
				$files[] = $item->getPathname();
			}
		}

		return $files;
	}
}
