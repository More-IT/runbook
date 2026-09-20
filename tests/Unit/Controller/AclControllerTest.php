<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Controller;

use OCA\Runbook\Controller\AclController;
use OCA\Runbook\Db\TemplateAcl;
use OCA\Runbook\Service\AclService;
use OCA\Runbook\Service\ValidationException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AclControllerTest extends TestCase {
	/** @var AclService&MockObject */
	private AclService $aclService;

	protected function setUp(): void {
		$this->aclService = $this->createMock(AclService::class);
	}

	/**
	 * @param array<string, mixed> $params Request parameters.
	 */
	private function controller(array $params): AclController {
		/** @var IRequest&MockObject $request */
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default,
		);
		$request->method('getParams')->willReturn($params);

		return new AclController('runbook', $request, $this->aclService);
	}

	private function entry(int $id, string $principalId, string $role): TemplateAcl {
		$entry = new TemplateAcl();
		$entry->setId($id);
		$entry->setTemplateId(5);
		$entry->setPrincipalType('USER');
		$entry->setPrincipalId($principalId);
		$entry->setRole($role);
		$entry->setCreatedAt(1000);
		$entry->setUpdatedAt(1000);

		return $entry;
	}

	public function testIndexReturnsOwnerAndEntries(): void {
		$this->aclService->expects(self::once())
			->method('getAcl')
			->with(5)
			->willReturn(['owner' => 'alice', 'entries' => [$this->entry(1, 'bob', 'VIEWER')]]);

		$response = $this->controller(['id' => '5'])->index();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame([
			'owner' => 'alice',
			'entries' => [[
				'id' => 1,
				'templateId' => 5,
				'principalType' => 'USER',
				'principalId' => 'bob',
				'role' => 'VIEWER',
				'createdAt' => 1000,
				'updatedAt' => 1000,
			]],
		], $response->getData());
	}

	public function testUpdateReplacesAcl(): void {
		$entries = [['principalType' => 'USER', 'principalId' => 'bob', 'role' => 'EDITOR']];

		$this->aclService->expects(self::once())
			->method('replaceAcl')
			->with(5, $entries)
			->willReturn(['owner' => 'alice', 'entries' => [$this->entry(2, 'bob', 'EDITOR')]]);

		$response = $this->controller(['id' => '5', 'entries' => $entries])->update();

		self::assertSame(200, $response->getStatus());
		self::assertSame('EDITOR', $response->getData()['entries'][0]['role']);
	}

	public function testUpdateRejectsMissingEntries(): void {
		$this->aclService->expects(self::never())->method('replaceAcl');

		$this->expectException(ValidationException::class);
		$this->controller(['id' => '5'])->update();
	}
}
