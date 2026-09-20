<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Controller;

use OCA\Runbook\Controller\AdminSettingsController;
use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\TemplateCreationPolicyService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminSettingsControllerTest extends TestCase {
	/** @var AdminSettings&MockObject */
	private AdminSettings $settings;
	/** @var TemplateCreationPolicyService&MockObject */
	private TemplateCreationPolicyService $creationPolicy;

	protected function setUp(): void {
		$this->settings = $this->createMock(AdminSettings::class);
		$this->settings->method('getDefaults')->willReturn([
			'templateCreationPolicy' => 'everyone',
			'templateCreatorGroups' => [],
			'commentsEnabled' => true,
		]);
		$this->creationPolicy = $this->createMock(TemplateCreationPolicyService::class);
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

		return new AdminSettingsController('runbook', $request, $this->settings, $this->creationPolicy, $session);
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
	}
}
