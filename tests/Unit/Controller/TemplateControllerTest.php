<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Controller;

use OCA\Runbook\Controller\TemplateController;
use OCA\Runbook\Db\Template;
use OCA\Runbook\Service\ForbiddenException;
use OCA\Runbook\Service\TemplateExportService;
use OCA\Runbook\Service\TemplateImportService;
use OCA\Runbook\Service\TemplateService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TemplateControllerTest extends TestCase {
	/** @var TemplateService&MockObject */
	private TemplateService $templateService;
	/** @var TemplateExportService&MockObject */
	private TemplateExportService $exportService;
	/** @var TemplateImportService&MockObject */
	private TemplateImportService $importService;

	protected function setUp(): void {
		$this->templateService = $this->createMock(TemplateService::class);
		$this->exportService = $this->createMock(TemplateExportService::class);
		$this->importService = $this->createMock(TemplateImportService::class);
	}

	/**
	 * @param array<string, mixed> $params Request parameters.
	 */
	private function controller(array $params): TemplateController {
		/** @var IRequest&MockObject $request */
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default,
		);
		$request->method('getParams')->willReturn($params);

		return new TemplateController('runbook', $request, $this->templateService, $this->exportService, $this->importService);
	}

	public function testExportReturnsTheDocument(): void {
		$document = [
			'format' => 'runbook-template',
			'schemaVersion' => 1,
			'template' => ['title' => 'Deploy', 'description' => ''],
			'sections' => [],
			'steps' => [],
		];
		$this->exportService->expects(self::once())
			->method('export')
			->with(5)
			->willReturn($document);

		$response = $this->controller(['id' => '5'])->export();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(200, $response->getStatus());
		self::assertSame($document, $response->getData(), 'the response body is the export document itself');
	}

	public function testExportPropagatesPermissionErrors(): void {
		$this->exportService->expects(self::once())
			->method('export')
			->with(5)
			->willThrowException(new ForbiddenException('not_allowed'));

		$this->expectException(ForbiddenException::class);
		$this->controller(['id' => '5'])->export();
	}

	public function testImportPassesTheJsonDecodedBodyToTheService(): void {
		// A real JSON request is decoded by Nextcloud into params; the controller
		// must forward the decoded document unchanged.
		$document = [
			'format' => 'runbook-template',
			'schemaVersion' => 1,
			'template' => ['title' => 'Deploy', 'description' => ''],
			'sections' => [],
			'steps' => [],
		];
		$decoded = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);

		$template = $this->createMock(Template::class);
		$template->method('toArray')->willReturn(['id' => 9, 'title' => 'Deploy']);
		$this->importService->expects(self::once())
			->method('import')
			->with($decoded)
			->willReturn($template);

		$response = $this->controller($decoded)->import();

		self::assertSame(201, $response->getStatus());
		self::assertSame(['template' => ['id' => 9, 'title' => 'Deploy']], $response->getData());
	}

	public function testImportDoesNotConsultContentLength(): void {
		$document = ['format' => 'runbook-template', 'schemaVersion' => 1, 'template' => ['title' => 'Deploy'], 'sections' => [], 'steps' => []];
		$template = $this->createMock(Template::class);
		$template->method('toArray')->willReturn(['id' => 9]);

		/** @var IRequest&MockObject $request */
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($document);
		$request->expects(self::never())->method('getHeader');

		$this->importService->expects(self::once())
			->method('import')
			->with($document)
			->willReturn($template);

		$controller = new TemplateController('runbook', $request, $this->templateService, $this->exportService, $this->importService);
		self::assertSame(201, $controller->import()->getStatus());
	}

	public function testImportPropagatesValidationErrors(): void {
		$this->importService->expects(self::once())
			->method('import')
			->willThrowException(new \OCA\Runbook\Service\ValidationException('invalid_import_format'));

		$this->expectException(\OCA\Runbook\Service\ValidationException::class);
		$this->controller(['format' => 'nope'])->import();
	}

	public function testImportPropagatesCreationPermissionErrors(): void {
		$this->importService->expects(self::once())
			->method('import')
			->willThrowException(new ForbiddenException('template_creation_forbidden'));

		$this->expectException(ForbiddenException::class);
		$this->controller(['format' => 'runbook-template'])->import();
	}

	public function testImportRouteIsRegisteredBeforeTheIdentifierRoute(): void {
		/** @var array{routes: list<array{name: string, url: string, verb: string}>} $routes */
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$names = array_column($routes['routes'], 'name');
		$importIndex = array_search('template#import', $names, true);
		$showIndex = array_search('template#show', $names, true);

		self::assertNotFalse($importIndex, 'the import route is registered');
		self::assertNotFalse($showIndex, 'the show route is registered');
		self::assertLessThan($showIndex, $importIndex, 'the static import route is registered before /templates/{id}');

		foreach ($routes['routes'] as $route) {
			if ($route['name'] === 'template#import') {
				self::assertSame('/api/v1/templates/import', $route['url']);
				self::assertSame('POST', $route['verb']);
			}
		}
	}
}
