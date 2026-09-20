<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Controller;

use OCA\Runbook\Controller\PageController;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class PageControllerTest extends TestCase {
	public function testIndexRendersUserTemplate(): void {
		$controller = new PageController('runbook', $this->createMock(IRequest::class));

		$response = $controller->index();

		self::assertInstanceOf(TemplateResponse::class, $response);
		self::assertSame('runbook', $response->getApp());
		self::assertSame('main', $response->getTemplateName());
		self::assertSame(TemplateResponse::RENDER_AS_USER, $response->getRenderAs());
	}
}
