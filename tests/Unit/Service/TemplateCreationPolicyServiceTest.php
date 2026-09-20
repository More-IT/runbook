<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Enum\TemplateCreationPolicy;
use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\TemplateCreationPolicyService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TemplateCreationPolicyServiceTest extends TestCase {
	/** @var array<string, mixed> */
	private array $values = [];
	/** @var list<string> */
	private array $adminUsers = [];
	/** @var array<string, list<string>> */
	private array $userGroups = [];
	/** @var array<string, string> */
	private array $users = ['alice' => 'alice', 'bob' => 'bob', 'carol' => 'carol'];
	/** @var IGroupManager&MockObject */
	private IGroupManager $groupManager;
	/** @var IUserManager&MockObject */
	private IUserManager $userManager;

	protected function setUp(): void {
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturnCallback(
			fn (string $uid): bool => in_array($uid, $this->adminUsers, true),
		);
		$this->groupManager->method('getUserGroupIds')->willReturnCallback(
			fn (IUser $user): array => $this->userGroups[$user->getUID()] ?? [],
		);

		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('get')->willReturnCallback(function (string $uid): ?IUser {
			if (!array_key_exists($uid, $this->users)) {
				return null;
			}

			/** @var IUser&MockObject $user */
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);

			return $user;
		});
	}

	private function policy(): TemplateCreationPolicyService {
		/** @var IAppConfig&MockObject $config */
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '', bool $lazy = false): string => is_string($this->values[$key] ?? null) ? $this->values[$key] : $default,
		);
		$config->method('getValueArray')->willReturnCallback(
			fn (string $app, string $key, array $default = [], bool $lazy = false): array => is_array($this->values[$key] ?? null) ? $this->values[$key] : $default,
		);
		$config->method('getValueBool')->willReturnCallback(static fn (string $app, string $key, bool $default = false, bool $lazy = false): bool => $default);
		$config->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0, bool $lazy = false): int => $default);

		return new TemplateCreationPolicyService(
			new AdminSettings($config, $this->groupManager),
			$this->groupManager,
			$this->userManager,
		);
	}

	public function testEveryoneAllowsAnyAuthenticatedUser(): void {
		$this->values[AdminSettings::KEY_TEMPLATE_CREATION_POLICY] = TemplateCreationPolicy::Everyone->value;

		self::assertTrue($this->policy()->canCreate('bob'));
	}

	public function testAdminsOnlyRejectsNonAdmin(): void {
		$this->values[AdminSettings::KEY_TEMPLATE_CREATION_POLICY] = TemplateCreationPolicy::Admins->value;

		self::assertFalse($this->policy()->canCreate('bob'));
	}

	public function testAdminsOnlyAllowsAdmin(): void {
		$this->values[AdminSettings::KEY_TEMPLATE_CREATION_POLICY] = TemplateCreationPolicy::Admins->value;
		$this->adminUsers[] = 'alice';

		self::assertTrue($this->policy()->canCreate('alice'));
	}

	public function testSelectedGroupsAllowsMember(): void {
		$this->values[AdminSettings::KEY_TEMPLATE_CREATION_POLICY] = TemplateCreationPolicy::SelectedGroups->value;
		$this->values[AdminSettings::KEY_TEMPLATE_CREATOR_GROUPS] = ['engineering'];
		$this->userGroups['carol'] = ['engineering'];

		self::assertTrue($this->policy()->canCreate('carol'));
	}

	public function testSelectedGroupsRejectsNonMember(): void {
		$this->values[AdminSettings::KEY_TEMPLATE_CREATION_POLICY] = TemplateCreationPolicy::SelectedGroups->value;
		$this->values[AdminSettings::KEY_TEMPLATE_CREATOR_GROUPS] = ['engineering'];
		$this->userGroups['carol'] = ['marketing'];

		self::assertFalse($this->policy()->canCreate('carol'));
	}

	public function testSelectedGroupsWithoutGroupsRejectsEveryone(): void {
		$this->values[AdminSettings::KEY_TEMPLATE_CREATION_POLICY] = TemplateCreationPolicy::SelectedGroups->value;
		$this->values[AdminSettings::KEY_TEMPLATE_CREATOR_GROUPS] = [];

		self::assertFalse($this->policy()->canCreate('alice'));
	}

	public function testEmptyUserIsRejected(): void {
		self::assertFalse($this->policy()->canCreate(''));
	}
}
