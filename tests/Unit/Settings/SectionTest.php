<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Settings;

use OCA\Runbook\Settings\Section;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SectionTest extends TestCase {
	public function testMetadata(): void {
		/** @var IL10N&MockObject $l10n */
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		/** @var IFactory&MockObject $factory */
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		/** @var IURLGenerator&MockObject $url */
		$url = $this->createMock(IURLGenerator::class);
		$url->method('imagePath')->willReturn('/apps/runbook/img/app.svg');

		$section = new Section($factory, $url);

		self::assertSame('runbook', $section->getID());
		self::assertSame('Runbook', $section->getName());
		self::assertSame(50, $section->getPriority());
		self::assertSame('/apps/runbook/img/app.svg', $section->getIcon());
	}
}
