<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Controller;

use OCA\Runbook\Controller\AdminSettingsController;
use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\DestinationReference;
use OCA\Runbook\Service\RunDestinationResolver;
use OCA\Runbook\Service\TemplateCreationPolicyService;
use OCA\Runbook\Service\ValidationException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class AdminSettingsControllerTest extends TestCase {
	/** @var AdminSettings&MockObject */
	private AdminSettings $settings;
	/** @var TemplateCreationPolicyService&MockObject */
	private TemplateCreationPolicyService $creationPolicy;
	/** @var RunDestinationResolver&MockObject */
	private RunDestinationResolver $destinations;
	/** @var IGroupManager&MockObject */
	private IGroupManager $groupManager;

	protected function setUp(): void {
		$this->settings = $this->createMock(AdminSettings::class);
		$this->settings->method('getDefaults')->willReturn([
			'templateCreationPolicy' => 'everyone',
			'templateCreatorGroups' => [],
			'commentsEnabled' => true,
		]);
		$this->creationPolicy = $this->createMock(TemplateCreationPolicyService::class);
		$this->destinations = $this->createMock(RunDestinationResolver::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturn(false);
	}

	/**
	 * @param array<string, mixed> $params Request parameters.
	 */
	private function controller(array $params, string $uid = 'admin'): AdminSettingsController {
		/** @var IRequest&MockObject $request */
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default,
		);
		$request->method('getParams')->willReturn($params);

		/** @var IUser&MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		/** @var IUserSession&MockObject $session */
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new AdminSettingsController('runbook', $request, $this->settings, $this->creationPolicy, $this->destinations, $session, $this->groupManager);
	}

	public function testIndexReturnsSettingsAndBounds(): void {
		$this->settings->expects(self::once())->method('getAll')->willReturn(['commentsEnabled' => true]);
		$this->settings->expects(self::once())->method('getBounds')->willReturn(['minAttachmentSize' => 1024, 'maxAttachmentSize' => 1073741824, 'maxRetentionDays' => 36500]);

		$response = $this->controller([])->index();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['commentsEnabled' => true], $response->getData()['settings']);
		self::assertSame(1073741824, $response->getData()['bounds']['maxAttachmentSize']);
	}

	public function testUpdateSavesSubmittedSettings(): void {
		$this->settings->expects(self::once())
			->method('update')
			->with(['templateCreationPolicy' => 'admins', 'commentsEnabled' => false])
			->willReturn(['templateCreationPolicy' => 'admins']);
		$this->settings->method('getBounds')->willReturn(['minAttachmentSize' => 1024, 'maxAttachmentSize' => 1073741824, 'maxRetentionDays' => 36500]);

		$response = $this->controller([
			'templateCreationPolicy' => 'admins',
			'commentsEnabled' => false,
			'ignored' => 'value',
		])->update();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['templateCreationPolicy' => 'admins'], $response->getData()['settings']);
	}

	public function testFeaturesReflectSettingsAndPolicy(): void {
		$this->settings->method('isCommentsEnabled')->willReturn(false);
		$this->settings->method('isStepReopenEnabled')->willReturn(true);
		$this->settings->method('isSkipReasonRequired')->willReturn(true);
		$this->settings->method('isRunReopenEnabled')->willReturn(false);
		$this->settings->method('getMaxAttachmentSize')->willReturn(26214400);
		$this->creationPolicy->expects(self::once())->method('canCreate')->with('bob')->willReturn(true);

		$response = $this->controller([], 'bob')->features();

		self::assertSame(200, $response->getStatus());
		$features = $response->getData()['features'];
		self::assertFalse($features['commentsEnabled']);
		self::assertTrue($features['stepReopenEnabled']);
		self::assertFalse($features['runReopenEnabled']);
		self::assertSame(26214400, $features['maxAttachmentSize']);
		self::assertTrue($features['canCreateTemplates']);
		self::assertSame('bob', $features['uid']);
		self::assertFalse($features['isAdmin']);
		self::assertArrayNotHasKey('destination', $features, 'the protected destination setting must not leak to ordinary users');
	}

	public function testIndexIncludesDestinationState(): void {
		$this->settings->method('getAll')->willReturn(['commentsEnabled' => true]);
		$this->settings->method('getBounds')->willReturn(['minAttachmentSize' => 1024, 'maxAttachmentSize' => 1073741824, 'maxRetentionDays' => 36500]);
		$this->settings->method('describeDestination')->willReturn([
			'configured' => true,
			'valid' => true,
			'path' => '/Shared/Reports',
			'configuredBy' => 'admin',
		]);

		$response = $this->controller([])->index();

		self::assertSame(200, $response->getStatus());
		self::assertSame('/Shared/Reports', $response->getData()['destination']['path']);
	}

	public function testUpdateDestinationSavesCapturedIdentity(): void {
		$this->destinations->expects(self::once())
			->method('captureReference')
			->with('admin', '/Shared/Reports')
			->willReturn(new DestinationReference('home::admin', 4242, '/Shared/Reports', 'admin'));
		$this->settings->expects(self::once())
			->method('saveDestinationReference')
			->with('home::admin', 4242, '/Shared/Reports', 'admin')
			->willReturn(new DestinationReference('home::admin', 4242, '/Shared/Reports', 'admin'));
		$this->settings->method('describeDestination')->willReturn([
			'configured' => true,
			'valid' => true,
			'path' => '/Shared/Reports',
			'configuredBy' => 'admin',
		]);

		$response = $this->controller(['path' => '/Shared/Reports'])->updateDestination();

		self::assertSame(200, $response->getStatus());
		self::assertSame('/Shared/Reports', $response->getData()['destination']['path']);
	}

	public function testUpdateDestinationRejectsNonStringPath(): void {
		$this->destinations->expects(self::never())->method('captureReference');

		$this->expectException(ValidationException::class);
		$this->controller(['path' => 123])->updateDestination();
	}

	public function testClearDestinationResetsTheReference(): void {
		$this->settings->expects(self::once())->method('clearDestinationReference');
		$this->settings->method('describeDestination')->willReturn([
			'configured' => false,
			'valid' => false,
			'path' => null,
			'configuredBy' => null,
		]);

		$response = $this->controller([])->clearDestination();

		self::assertSame(200, $response->getStatus());
		self::assertFalse($response->getData()['destination']['configured']);
	}

	public function testProtectedEndpointsAreAdminOnly(): void {
		foreach (['index', 'update', 'updateDestination', 'clearDestination'] as $method) {
			$attributes = (new ReflectionMethod(AdminSettingsController::class, $method))->getAttributes(NoAdminRequired::class);
			self::assertSame([], $attributes, $method . ' must stay administrator-only');
		}

		$featuresAttributes = (new ReflectionMethod(AdminSettingsController::class, 'features'))->getAttributes(NoAdminRequired::class);
		self::assertNotSame([], $featuresAttributes, 'features must remain available to authenticated users');
	}
}
