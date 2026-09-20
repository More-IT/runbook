<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Controller;

use OCA\Runbook\Controller\PrincipalController;
use OCA\Runbook\Service\AclService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PrincipalControllerTest extends TestCase {
	/** @var AclService&MockObject */
	private AclService $aclService;

	protected function setUp(): void {
		$this->aclService = $this->createMock(AclService::class);
	}

	public function testIndexReturnsBoundedPrincipals(): void {
		$this->aclService->expects(self::once())
			->method('searchPrincipals')
			->with('bob', 25)
			->willReturn([
				['principalType' => 'USER', 'principalId' => 'bob', 'displayName' => 'Bob'],
			]);

		/** @var IRequest&MockObject $request */
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $key === 'search' ? 'bob' : $default,
		);

		$response = (new PrincipalController('runbook', $request, $this->aclService))->index();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame([
			'principals' => [
				['principalType' => 'USER', 'principalId' => 'bob', 'displayName' => 'Bob'],
			],
		], $response->getData());
	}
}
