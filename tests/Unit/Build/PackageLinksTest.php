<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/build/package-links.php';

/**
 * Regression tests for the packaged-README link validator.
 *
 * A package must only reference files inside its own staging tree, so a link
 * that uses `..`, an absolute path or a drive/UNC path must be rejected before
 * the file-existence check.
 */
class PackageLinksTest extends TestCase {
	public function testRelativeLinksAreExtractedOnceAndAnchorsStripped(): void {
		$markdown = 'See [A](docs/a.md) and [B](docs/b.md#section) and [A again](docs/a.md).';

		self::assertSame(['docs/a.md', 'docs/b.md'], relativeMarkdownLinks($markdown));
	}

	public function testExternalAndAnchorOnlyLinksAreIgnored(): void {
		$markdown = '[web](https://example.com/x) [mail](mailto:x@example.com) [top](#overview) [proto](ftp://host/x)';

		self::assertSame([], relativeMarkdownLinks($markdown));
	}

	public function testSafeRelativeLinksAreAccepted(): void {
		self::assertFalse(isUnsafeRelativeLink('README.md'));
		self::assertFalse(isUnsafeRelativeLink('docs/ux-architecture.md'));
		self::assertFalse(isUnsafeRelativeLink('docs/sub dir/file.md'));
	}

	public function testLinksThatEscapeThePackageAreRejected(): void {
		foreach (['../secret.md', 'docs/../../etc/passwd', '/etc/passwd', 'C:\\Windows\\system.ini', '..\\secret.md', '//host/share/file'] as $link) {
			self::assertTrue(isUnsafeRelativeLink($link), $link . ' must be rejected');
		}
	}
}
