<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Enum\TemplateCreationPolicy;
use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\ValidationException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminSettingsTest extends TestCase {
	/** @var array<string, mixed> */
	private array $values = [];
	/** @var list<string> */
	private array $groups = [];
	/** @var IGroupManager&MockObject */
	private IGroupManager $groupManager;

	protected function setUp(): void {
		$this->values = [];
		$this->groups = ['engineering', 'operations'];
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('groupExists')->willReturnCallback(
			fn (string $gid): bool => in_array($gid, $this->groups, true),
		);
	}

	private function settings(): AdminSettings {
		/** @var IAppConfig&MockObject $config */
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '', bool $lazy = false): string => is_string($this->values[$key] ?? null) ? $this->values[$key] : $default,
		);
		$config->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0, bool $lazy = false): int => is_int($this->values[$key] ?? null) ? $this->values[$key] : $default,
		);
		$config->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false, bool $lazy = false): bool => is_bool($this->values[$key] ?? null) ? $this->values[$key] : $default,
		);
		$config->method('getValueArray')->willReturnCallback(
			fn (string $app, string $key, array $default = [], bool $lazy = false): array => is_array($this->values[$key] ?? null) ? $this->values[$key] : $default,
		);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->values[$key] = $value;

			return true;
		});
		$config->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value): bool {
			$this->values[$key] = $value;

			return true;
		});
		$config->method('setValueBool')->willReturnCallback(function (string $app, string $key, bool $value): bool {
			$this->values[$key] = $value;

			return true;
		});
		$config->method('setValueArray')->willReturnCallback(function (string $app, string $key, array $value): bool {
			$this->values[$key] = $value;

			return true;
		});

		return new AdminSettings($config, $this->groupManager);
	}

	public function testDefaultsAreReturnedWhenNothingIsStored(): void {
		$settings = $this->settings();

		self::assertSame($settings->getDefaults(), $settings->getAll());
		self::assertSame(TemplateCreationPolicy::Everyone, $settings->getTemplateCreationPolicy());
		self::assertSame([], $settings->getTemplateCreatorGroups());
		self::assertTrue($settings->isCommentsEnabled());
		self::assertTrue($settings->isStepReopenEnabled());
		self::assertTrue($settings->isSkipReasonRequired());
		self::assertTrue($settings->isRunReopenEnabled());
		self::assertSame(AdminSettings::DEFAULT_MAX_ATTACHMENT_SIZE, $settings->getMaxAttachmentSize());
		self::assertTrue($settings->isDashboardEnabled());
		self::assertTrue($settings->isNotificationsEnabled());
		self::assertTrue($settings->isSearchEnabled());
		self::assertSame(0, $settings->getRetentionDays());
	}

	public function testStoredValuesAreRead(): void {
		$this->values[AdminSettings::KEY_TEMPLATE_CREATION_POLICY] = 'selected_groups';
		$this->values[AdminSettings::KEY_TEMPLATE_CREATOR_GROUPS] = ['engineering'];
		$this->values[AdminSettings::KEY_COMMENTS_ENABLED] = false;
		$this->values[AdminSettings::KEY_MAX_ATTACHMENT_SIZE] = 1048576;
		$this->values[AdminSettings::KEY_RETENTION_DAYS] = 365;

		$settings = $this->settings();

		self::assertSame(TemplateCreationPolicy::SelectedGroups, $settings->getTemplateCreationPolicy());
		self::assertSame(['engineering'], $settings->getTemplateCreatorGroups());
		self::assertFalse($settings->isCommentsEnabled());
		self::assertSame(1048576, $settings->getMaxAttachmentSize());
		self::assertSame(365, $settings->getRetentionDays());
	}

	public function testInvalidStoredValuesFallBackToDefaultsOrBounds(): void {
		$this->values[AdminSettings::KEY_TEMPLATE_CREATION_POLICY] = 'not-a-policy';
		$this->values[AdminSettings::KEY_MAX_ATTACHMENT_SIZE] = 999999999999;
		$this->values[AdminSettings::KEY_RETENTION_DAYS] = -50;

		$settings = $this->settings();

		self::assertSame(TemplateCreationPolicy::Everyone, $settings->getTemplateCreationPolicy());
		self::assertSame(AdminSettings::MAX_ATTACHMENT_SIZE, $settings->getMaxAttachmentSize());
		self::assertSame(0, $settings->getRetentionDays());
	}

	public function testUpdateSavesAndReadsBack(): void {
		$settings = $this->settings();

		$result = $settings->update([
			'templateCreationPolicy' => 'selected_groups',
			'templateCreatorGroups' => ['engineering'],
			'commentsEnabled' => false,
			'stepReopenEnabled' => false,
			'requireSkipReason' => false,
			'runReopenEnabled' => false,
			'maxAttachmentSize' => 5242880,
			'dashboardEnabled' => false,
			'notificationsEnabled' => false,
			'searchEnabled' => false,
			'retentionDays' => 1095,
		]);

		self::assertSame('selected_groups', $result['templateCreationPolicy']);
		self::assertSame(['engineering'], $result['templateCreatorGroups']);
		self::assertSame(5242880, $settings->getMaxAttachmentSize());
		self::assertSame(1095, $settings->getRetentionDays());
		self::assertFalse($settings->isCommentsEnabled());
		self::assertFalse($settings->isDashboardEnabled());
		self::assertFalse($settings->isNotificationsEnabled());
		self::assertFalse($settings->isSearchEnabled());
	}

	public function testGroupsAreNormalized(): void {
		$settings = $this->settings();

		$result = $settings->update([
			'templateCreationPolicy' => 'selected_groups',
			'templateCreatorGroups' => [' engineering ', 'engineering', 'operations'],
			'commentsEnabled' => true,
			'stepReopenEnabled' => true,
			'requireSkipReason' => true,
			'runReopenEnabled' => true,
			'maxAttachmentSize' => AdminSettings::DEFAULT_MAX_ATTACHMENT_SIZE,
			'dashboardEnabled' => true,
			'notificationsEnabled' => true,
			'searchEnabled' => true,
			'retentionDays' => 0,
		]);

		self::assertSame(['engineering', 'operations'], $result['templateCreatorGroups']);
	}

	public function testInvalidPolicyIsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->settings()->update([
			'templateCreationPolicy' => 'everybody',
			'templateCreatorGroups' => [],
			'commentsEnabled' => true,
			'stepReopenEnabled' => true,
			'requireSkipReason' => true,
			'runReopenEnabled' => true,
			'maxAttachmentSize' => AdminSettings::DEFAULT_MAX_ATTACHMENT_SIZE,
			'dashboardEnabled' => true,
			'notificationsEnabled' => true,
			'searchEnabled' => true,
			'retentionDays' => 0,
		]);
	}

	public function testUnknownGroupIsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->settings()->update([
			'templateCreationPolicy' => 'selected_groups',
			'templateCreatorGroups' => ['ghost-group'],
			'commentsEnabled' => true,
			'stepReopenEnabled' => true,
			'requireSkipReason' => true,
			'runReopenEnabled' => true,
			'maxAttachmentSize' => AdminSettings::DEFAULT_MAX_ATTACHMENT_SIZE,
			'dashboardEnabled' => true,
			'notificationsEnabled' => true,
			'searchEnabled' => true,
			'retentionDays' => 0,
		]);
	}

	public function testNonStringGroupEntryIsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->settings()->update([
			'templateCreationPolicy' => 'selected_groups',
			'templateCreatorGroups' => [123],
			'commentsEnabled' => true,
			'stepReopenEnabled' => true,
			'requireSkipReason' => true,
			'runReopenEnabled' => true,
			'maxAttachmentSize' => AdminSettings::DEFAULT_MAX_ATTACHMENT_SIZE,
			'dashboardEnabled' => true,
			'notificationsEnabled' => true,
			'searchEnabled' => true,
			'retentionDays' => 0,
		]);
	}

	public function testNegativeAttachmentSizeIsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->settings()->update($this->payload(['maxAttachmentSize' => -1]));
	}

	public function testTooSmallAttachmentSizeIsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->settings()->update($this->payload(['maxAttachmentSize' => 1]));
	}

	public function testTooLargeAttachmentSizeIsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->settings()->update($this->payload(['maxAttachmentSize' => AdminSettings::MAX_ATTACHMENT_SIZE + 1]));
	}

	public function testNonIntegerAttachmentSizeIsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->settings()->update($this->payload(['maxAttachmentSize' => '1048576']));
	}

	public function testNegativeRetentionIsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->settings()->update($this->payload(['retentionDays' => -1]));
	}

	public function testExcessiveRetentionIsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->settings()->update($this->payload(['retentionDays' => AdminSettings::MAX_RETENTION_DAYS + 1]));
	}

	public function testNonBooleanSettingIsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->settings()->update($this->payload(['commentsEnabled' => 'yes']));
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function payload(array $overrides): array {
		return array_merge($this->settings()->getDefaults(), $overrides);
	}
}
