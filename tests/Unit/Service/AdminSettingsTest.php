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

	// Fault injection for the persistence back end (#47 atomicity tests).
	private int $setStringCalls = 0;
	private int $deleteKeyCalls = 0;
	private ?int $failSetStringOn = null;
	private ?int $failDeleteKeyOn = null;

	protected function setUp(): void {
		$this->values = [];
		$this->groups = ['engineering', 'operations'];
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('groupExists')->willReturnCallback(
			fn (string $gid): bool => in_array($gid, $this->groups, true),
		);
		$this->resetFaults();
	}

	/**
	 * Clear the fault-injection counters.
	 */
	private function resetFaults(): void {
		$this->setStringCalls = 0;
		$this->deleteKeyCalls = 0;
		$this->failSetStringOn = null;
		$this->failDeleteKeyOn = null;
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
			$this->setStringCalls++;
			if ($this->failSetStringOn !== null && $this->setStringCalls === $this->failSetStringOn) {
				throw new \RuntimeException('injected setValueString failure');
			}
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
		$config->method('hasKey')->willReturnCallback(
			fn (string $app, string $key, ?bool $lazy = false): bool => array_key_exists($key, $this->values),
		);
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			$this->deleteKeyCalls++;
			if ($this->failDeleteKeyOn !== null && $this->deleteKeyCalls === $this->failDeleteKeyOn) {
				throw new \RuntimeException('injected deleteKey failure');
			}
			unset($this->values[$key]);
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

	public function testDestinationIsUnsetByDefault(): void {
		$settings = $this->settings();

		self::assertNull($settings->getDestinationReference());
		self::assertSame(
			['configured' => false, 'valid' => false, 'path' => null, 'configuredBy' => null],
			$settings->describeDestination(),
		);
	}

	public function testDestinationSaveAndReadBack(): void {
		$settings = $this->settings();

		$reference = $settings->saveDestinationReference('home::admin', 4242, '/Shared/Reports', 'admin');
		self::assertSame('home::admin', $reference->storageId);
		self::assertSame(4242, $reference->fileId);
		self::assertSame('/Shared/Reports', $reference->path);
		self::assertSame('admin', $reference->configuredBy);

		$stored = $settings->getDestinationReference();
		self::assertNotNull($stored);
		self::assertSame('home::admin', $stored->storageId);
		self::assertSame(4242, $stored->fileId);
		self::assertSame('/Shared/Reports', $stored->path);
		self::assertSame('admin', $stored->configuredBy);

		$described = $settings->describeDestination();
		self::assertTrue($described['configured']);
		self::assertTrue($described['valid']);
		self::assertSame('/Shared/Reports', $described['path']);
		self::assertSame('admin', $described['configuredBy']);
	}

	public function testDestinationDescriptionNeverExposesIdentity(): void {
		$settings = $this->settings();
		$settings->saveDestinationReference('home::admin', 4242, '/Shared', 'admin');

		$described = $settings->describeDestination();
		self::assertArrayNotHasKey('storageId', $described);
		self::assertArrayNotHasKey('fileId', $described);
	}

	public function testDestinationClearRestoresUnsetState(): void {
		$settings = $this->settings();
		$settings->saveDestinationReference('home::admin', 4242, '/Shared', 'admin');

		$settings->clearDestinationReference();

		self::assertNull($settings->getDestinationReference());
		self::assertFalse($settings->describeDestination()['configured']);
	}

	public function testPartialDestinationIsInvalidNotUnset(): void {
		$this->values[AdminSettings::KEY_DESTINATION_FILE_ID] = 4242;
		$settings = $this->settings();

		$described = $settings->describeDestination();
		self::assertTrue($described['configured'], 'a partial reference is configured, not unset');
		self::assertFalse($described['valid']);

		$this->expectException(ValidationException::class);
		$settings->getDestinationReference();
	}

	public function testIncompleteDestinationCannotBeSaved(): void {
		$this->expectException(ValidationException::class);
		$this->settings()->saveDestinationReference('', 0, null, '');
	}

	public function testPathOnlyDestinationIsInvalid(): void {
		$this->values[AdminSettings::KEY_DESTINATION_PATH] = '/Shared';
		$settings = $this->settings();

		$described = $settings->describeDestination();
		self::assertTrue($described['configured'], 'a path without identity is a corrupted reference, not unset');
		self::assertFalse($described['valid']);
	}

	public function testSavingReplacesPreviousReferenceAsOneLogicalOperation(): void {
		$settings = $this->settings();
		$settings->saveDestinationReference('home::a', 1, '/A', 'admin-a');
		$settings->saveDestinationReference('home::b', 2, '/B', 'admin-b');

		$stored = $settings->getDestinationReference();
		self::assertNotNull($stored);
		self::assertSame('home::b', $stored->storageId);
		self::assertSame(2, $stored->fileId);
		self::assertSame('/B', $stored->path);
		self::assertSame('admin-b', $stored->configuredBy);

		self::assertArrayHasKey(AdminSettings::KEY_DESTINATION_REFERENCE, $this->values);
		$this->assertNoLegacyKeys();
		self::assertSame(
			['v' => 1, 'storageId' => 'home::b', 'fileId' => 2, 'path' => '/B', 'configuredBy' => 'admin-b'],
			json_decode($this->storedReferenceValue(), true, 512, JSON_THROW_ON_ERROR),
		);
	}

	public function testReferenceIsStoredAsOneCompleteValue(): void {
		$settings = $this->settings();
		$settings->saveDestinationReference('home::a', 1, '/A', 'admin-a');

		$decoded = json_decode($this->storedReferenceValue(), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(1, $decoded['v']);
		self::assertSame('home::a', $decoded['storageId']);
		self::assertSame(1, $decoded['fileId']);
		self::assertSame('/A', $decoded['path']);
		self::assertSame('admin-a', $decoded['configuredBy']);
	}

	public function testSaveFailureLeavesPreviousReferenceIntact(): void {
		$settings = $this->settings();
		$settings->saveDestinationReference('home::a', 1, '/A', 'admin-a');

		$this->resetFaults();
		$this->failSetStringOn = 1;
		try {
			$settings->saveDestinationReference('home::b', 2, '/B', 'admin-b');
			self::fail('expected the write failure to abort the save');
		} catch (\RuntimeException) {
		}

		$stored = $settings->getDestinationReference();
		self::assertNotNull($stored);
		self::assertSame('home::a', $stored->storageId);
		self::assertSame(1, $stored->fileId);
	}

	public function testSaveFailureDuringLegacyMigrationLeavesLegacyIntact(): void {
		$this->setLegacyValues('home::legacy', 11, '/Legacy', 'admin-legacy');
		$settings = $this->settings();

		$this->resetFaults();
		$this->failSetStringOn = 1;
		try {
			$settings->saveDestinationReference('home::new', 22, '/New', 'admin-new');
			self::fail('expected the migration write failure to abort the save');
		} catch (\RuntimeException) {
		}

		$stored = $settings->getDestinationReference();
		self::assertNotNull($stored);
		self::assertSame('home::legacy', $stored->storageId);
		self::assertSame(11, $stored->fileId);
		self::assertArrayHasKey(AdminSettings::KEY_DESTINATION_STORAGE_ID, $this->values, 'legacy keys were not deleted before the failing write');
	}

	public function testSaveFailureDuringLegacyCleanupLeavesOldReference(): void {
		$this->setLegacyValues('home::legacy', 11, '/Legacy', 'admin-legacy');
		$settings = $this->settings();

		$this->resetFaults();
		$this->failDeleteKeyOn = 1;
		try {
			$settings->saveDestinationReference('home::new', 22, '/New', 'admin-new');
			self::fail('expected the cleanup failure to abort the save');
		} catch (\RuntimeException) {
		}

		$stored = $settings->getDestinationReference();
		self::assertNotNull($stored);
		self::assertSame('home::legacy', $stored->storageId);
		self::assertSame(11, $stored->fileId);
	}

	public function testClearFailureLeavesPreviousReferenceIntact(): void {
		$settings = $this->settings();
		$settings->saveDestinationReference('home::a', 1, '/A', 'admin-a');

		$this->resetFaults();
		$this->failDeleteKeyOn = 1;
		try {
			$settings->clearDestinationReference();
			self::fail('expected the delete failure to abort the clear');
		} catch (\RuntimeException) {
		}

		$stored = $settings->getDestinationReference();
		self::assertNotNull($stored);
		self::assertSame('home::a', $stored->storageId);
		self::assertSame(1, $stored->fileId);
	}

	public function testCompleteLegacyReferenceIsReadAndMigratedOnSave(): void {
		$this->setLegacyValues('home::legacy', 11, '/Legacy', 'admin-legacy');
		$settings = $this->settings();

		$legacy = $settings->getDestinationReference();
		self::assertNotNull($legacy);
		self::assertSame('home::legacy', $legacy->storageId);
		self::assertSame(11, $legacy->fileId);

		$settings->saveDestinationReference('home::new', 22, '/New', 'admin-new');

		$stored = $settings->getDestinationReference();
		self::assertNotNull($stored);
		self::assertSame('home::new', $stored->storageId);
		self::assertSame(22, $stored->fileId);
		$this->assertNoLegacyKeys();
	}

	public function testClearRemovesCompleteLegacyReference(): void {
		$this->setLegacyValues('home::legacy', 11, '/Legacy', 'admin-legacy');
		$settings = $this->settings();

		$settings->clearDestinationReference();

		self::assertNull($settings->getDestinationReference());
		self::assertFalse($settings->describeDestination()['configured']);
		$this->assertNoLegacyKeys();
	}

	public function testMalformedNewFormatIsInvalidAndNeverFallsBackToLegacy(): void {
		$this->setLegacyValues('home::legacy', 11, '/Legacy', 'admin-legacy');
		$this->values[AdminSettings::KEY_DESTINATION_REFERENCE] = '{not valid json';
		$settings = $this->settings();

		$described = $settings->describeDestination();
		self::assertTrue($described['configured']);
		self::assertFalse($described['valid']);

		$this->expectException(ValidationException::class);
		$settings->getDestinationReference();
	}

	public function testConflictingCompleteLegacyAndNewIsInvalid(): void {
		$this->setLegacyValues('home::legacy', 11, '/Legacy', 'admin-legacy');
		$this->values[AdminSettings::KEY_DESTINATION_REFERENCE] = json_encode([
			'v' => 1,
			'storageId' => 'home::new',
			'fileId' => 22,
			'path' => '/New',
			'configuredBy' => 'admin-new',
		], JSON_THROW_ON_ERROR);
		$settings = $this->settings();

		$described = $settings->describeDestination();
		self::assertTrue($described['configured']);
		self::assertFalse($described['valid']);

		$this->expectException(ValidationException::class);
		$settings->getDestinationReference();
	}

	public function testConsistentCompleteLegacyAndNewIsReadable(): void {
		$this->setLegacyValues('home::same', 7, '/Same', 'admin');
		$this->values[AdminSettings::KEY_DESTINATION_REFERENCE] = json_encode([
			'v' => 1,
			'storageId' => 'home::same',
			'fileId' => 7,
			'path' => '/Same',
			'configuredBy' => 'admin',
		], JSON_THROW_ON_ERROR);
		$settings = $this->settings();

		$stored = $settings->getDestinationReference();
		self::assertNotNull($stored);
		self::assertSame('home::same', $stored->storageId);
		self::assertSame(7, $stored->fileId);
	}

	public function testPartialLegacyResidueIsIgnoredWhenNewReferenceIsValid(): void {
		$this->values[AdminSettings::KEY_DESTINATION_REFERENCE] = json_encode([
			'v' => 1,
			'storageId' => 'home::new',
			'fileId' => 22,
			'path' => '/New',
			'configuredBy' => 'admin-new',
		], JSON_THROW_ON_ERROR);
		$this->values[AdminSettings::KEY_DESTINATION_FILE_ID] = 999;
		$settings = $this->settings();

		$stored = $settings->getDestinationReference();
		self::assertNotNull($stored);
		self::assertSame('home::new', $stored->storageId);
		self::assertSame(22, $stored->fileId);
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function payload(array $overrides): array {
		return array_merge($this->settings()->getDefaults(), $overrides);
	}

	private function setLegacyValues(string $storageId, int $fileId, string $path, string $configuredBy): void {
		$this->values[AdminSettings::KEY_DESTINATION_STORAGE_ID] = $storageId;
		$this->values[AdminSettings::KEY_DESTINATION_FILE_ID] = $fileId;
		$this->values[AdminSettings::KEY_DESTINATION_PATH] = $path;
		$this->values[AdminSettings::KEY_DESTINATION_CONFIGURED_BY] = $configuredBy;
	}

	private function storedReferenceValue(): string {
		self::assertArrayHasKey(AdminSettings::KEY_DESTINATION_REFERENCE, $this->values);
		$value = $this->values[AdminSettings::KEY_DESTINATION_REFERENCE];
		self::assertIsString($value);

		return $value;
	}

	private function assertNoLegacyKeys(): void {
		foreach ([
			AdminSettings::KEY_DESTINATION_STORAGE_ID,
			AdminSettings::KEY_DESTINATION_FILE_ID,
			AdminSettings::KEY_DESTINATION_PATH,
			AdminSettings::KEY_DESTINATION_CONFIGURED_BY,
		] as $key) {
			self::assertArrayNotHasKey($key, $this->values);
		}
	}
}
