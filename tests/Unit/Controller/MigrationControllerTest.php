<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Controller;

use OCA\Runbook\Controller\MigrationController;
use OCA\Runbook\Service\LegacyMigrationService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MigrationControllerTest extends TestCase {
	/** @var LegacyMigrationService&MockObject */
	private LegacyMigrationService $migration;

	protected function setUp(): void {
		$this->migration = $this->createMock(LegacyMigrationService::class);
	}

	private function controller(): MigrationController {
		/** @var IRequest&MockObject $request */
		$request = $this->createMock(IRequest::class);

		return new MigrationController('runbook', $request, $this->migration);
	}

	public function testIndexReturnsMigrationStatus(): void {
		$this->migration->expects(self::once())
			->method('status')
			->willReturn([
				'remainingAppDataAttachments' => 2,
				'residualCleanupAttachments' => 1,
				'runs' => [['id' => 5, 'title' => 'Run', 'owner' => 'alice', 'state' => 'blocked', 'reason' => 'destination_no_access', 'appDataAttachments' => 2, 'lastAttemptedAt' => 1000]],
				'cleanup' => [['id' => 6, 'title' => 'Old', 'owner' => 'alice', 'residualAttachments' => 1, 'lastAttemptedAt' => 1000]],
			]);

		$response = $this->controller()->index();

		self::assertSame(200, $response->getStatus());
		self::assertSame(2, $response->getData()['migration']['remainingAppDataAttachments']);
		self::assertSame(1, $response->getData()['migration']['residualCleanupAttachments']);
		self::assertSame('blocked', $response->getData()['migration']['runs'][0]['state']);
		self::assertSame(6, $response->getData()['migration']['cleanup'][0]['id']);
	}

	public function testRunTriggersABatchAndReturnsTheUpdatedStatus(): void {
		$this->migration->expects(self::once())
			->method('migrateAll')
			->willReturn(['processed' => 1, 'migrated' => 1, 'blocked' => 0, 'remaining' => 0, 'busy' => false]);
		$this->migration->expects(self::once())
			->method('status')
			->willReturn(['remainingAppDataAttachments' => 0, 'residualCleanupAttachments' => 0, 'runs' => [], 'cleanup' => []]);

		$response = $this->controller()->run();

		self::assertSame(200, $response->getStatus());
		self::assertSame(1, $response->getData()['result']['processed']);
		self::assertSame(0, $response->getData()['migration']['remainingAppDataAttachments']);
	}

	public function testMigrationEndpointsAreAdministratorOnly(): void {
		$attribute = 'OCP\\AppFramework\\Http\\Attribute\\NoAdminRequired';

		foreach (['index', 'run'] as $method) {
			$reflection = new \ReflectionMethod(MigrationController::class, $method);
			self::assertSame(
				[],
				array_filter(
					$reflection->getAttributes(),
					static fn (\ReflectionAttribute $found): bool => $found->getName() === $attribute,
				),
				$method . ' must stay administrator-only (no NoAdminRequired)',
			);
		}
	}
}
