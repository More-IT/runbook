<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\Template;
use OCA\Runbook\Service\DestinationReference;
use OCA\Runbook\Service\RunDestinationResolver;
use OCA\Runbook\Service\TemplateDestinationService;
use OCA\Runbook\Service\ValidationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the per-template destination reference reader (issue #48).
 *
 * The Files capture itself is delegated to {@see RunDestinationResolver} and is
 * covered by its own tests; here the resolver is doubled.
 */
class TemplateDestinationServiceTest extends TestCase {
	/** @var RunDestinationResolver&MockObject */
	private RunDestinationResolver $resolver;
	private TemplateDestinationService $service;

	protected function setUp(): void {
		$this->resolver = $this->createMock(RunDestinationResolver::class);
		$this->service = new TemplateDestinationService($this->resolver);
	}

	private function configuredTemplate(string $storageId, int $fileId, ?string $path, string $configuredBy): Template {
		$template = new Template();
		$template->setDestinationStorageId($storageId);
		$template->setDestinationFileId($fileId);
		$template->setDestinationPath($path);
		$template->setDestinationConfiguredBy($configuredBy);

		return $template;
	}

	public function testUnsetTemplateHasNoReference(): void {
		$template = new Template();

		self::assertNull($this->service->readReference($template));
		self::assertSame(
			['configured' => false, 'valid' => false, 'path' => null, 'configuredBy' => null],
			$this->service->describe($template),
		);
	}

	public function testCompleteReferenceIsRead(): void {
		$template = $this->configuredTemplate('home::alice', 42, '/Shared', 'alice');

		$reference = $this->service->readReference($template);
		self::assertNotNull($reference);
		self::assertSame('home::alice', $reference->storageId);
		self::assertSame(42, $reference->fileId);
		self::assertSame('/Shared', $reference->path);
		self::assertSame('alice', $reference->configuredBy);

		$described = $this->service->describe($template);
		self::assertTrue($described['configured']);
		self::assertTrue($described['valid']);
		self::assertSame('/Shared', $described['path']);
	}

	public function testPartialReferenceIsInvalidNotUnset(): void {
		$template = new Template();
		$template->setDestinationFileId(42);

		self::assertSame(
			['configured' => true, 'valid' => false, 'path' => null, 'configuredBy' => null],
			$this->service->describe($template),
			'a partial reference is configured, not unset',
		);

		$this->expectException(ValidationException::class);
		$this->service->readReference($template);
	}

	public function testDescriptionNeverExposesIdentity(): void {
		$described = $this->service->describe($this->configuredTemplate('home::alice', 42, '/Shared', 'alice'));

		self::assertArrayNotHasKey('storageId', $described);
		self::assertArrayNotHasKey('fileId', $described);
	}

	public function testCaptureDelegatesToTheResolver(): void {
		$expected = new DestinationReference('home::alice', 7, '/Shared', 'alice');
		$this->resolver->expects(self::once())
			->method('captureReference')
			->with('alice', '/Shared')
			->willReturn($expected);

		self::assertSame($expected, $this->service->capture('alice', '/Shared'));
	}
}
