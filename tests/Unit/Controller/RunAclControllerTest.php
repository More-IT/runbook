<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Controller;

use OCA\Runbook\Controller\RunAclController;
use OCA\Runbook\Db\RunAcl;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunAclRole;
use OCA\Runbook\Service\RunAclService;
use OCA\Runbook\Service\ValidationException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RunAclControllerTest extends TestCase {
	/** @var RunAclService&MockObject */
	private RunAclService $runAclService;

	protected function setUp(): void {
		$this->runAclService = $this->createMock(RunAclService::class);
	}

	/**
	 * @param array<string, mixed> $params Request parameters.
	 */
	private function controller(array $params): RunAclController {
		/** @var IRequest&MockObject $request */
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default,
		);
		$request->method('getParams')->willReturn($params);

		return new RunAclController('runbook', $request, $this->runAclService);
	}

	private function entry(): RunAcl {
		$entry = new RunAcl();
		$entry->setId(1);
		$entry->setRunId(5);
		$entry->setPrincipalType(PrincipalType::User->value);
		$entry->setPrincipalId('bob');
		$entry->setRole(RunAclRole::Participant->value);
		$entry->setCreatedAt(1000);
		$entry->setUpdatedAt(1000);

		return $entry;
	}

	public function testIndexReturnsOwnerAndEntries(): void {
		$this->runAclService->expects(self::once())
			->method('getAcl')
			->with(5)
			->willReturn(['owner' => 'alice', 'entries' => [$this->entry()]]);

		$response = $this->controller(['id' => '5'])->index();

		self::assertSame(200, $response->getStatus());
		self::assertSame('alice', $response->getData()['owner']);
		self::assertSame('bob', $response->getData()['entries'][0]['principalId']);
	}

	public function testUpdateReplacesAcl(): void {
		$entries = [['principalType' => 'USER', 'principalId' => 'bob', 'role' => 'PARTICIPANT']];

		$this->runAclService->expects(self::once())
			->method('replaceAcl')
			->with(5, $entries)
			->willReturn(['owner' => 'alice', 'entries' => [$this->entry()]]);

		$response = $this->controller(['id' => '5', 'entries' => $entries])->update();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame('PARTICIPANT', $response->getData()['entries'][0]['role']);
	}

	public function testUpdateRejectsMissingEntries(): void {
		$this->runAclService->expects(self::never())->method('replaceAcl');

		$this->expectException(ValidationException::class);
		$this->controller(['id' => '5'])->update();
	}
}
