<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\Template;
use OCA\Runbook\Db\TemplateAcl;
use OCA\Runbook\Db\TemplateAclMapper;
use OCA\Runbook\Enum\AclRole;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\TemplateStatus;
use OCA\Runbook\Service\PermissionService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for effective role resolution.
 */
class PermissionServiceTest extends TestCase {
	/** @var list<TemplateAcl> */
	private array $aclEntries = [];
	/** @var array<string, string> */
	private array $existingUsers = [];
	/** @var array<string, list<string>> */
	private array $userGroups = [];
	private int $nextId = 1;

	/** @var TemplateAclMapper&MockObject */
	private TemplateAclMapper $aclMapper;
	private PermissionService $service;

	protected function setUp(): void {
		$this->nextId = 1;

		/** @var IUserManager&MockObject $userManager */
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => array_key_exists($uid, $this->existingUsers) ? $this->userMock($uid) : null,
		);

		/** @var IGroupManager&MockObject $groupManager */
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturnCallback(
			fn (IUser $user): array => $this->userGroups[$user->getUID()] ?? [],
		);

		$this->aclMapper = $this->createMock(TemplateAclMapper::class);
		$this->aclMapper->method('findByTemplate')->willReturnCallback(
			fn (int $templateId): array => array_values(array_filter(
				$this->aclEntries,
				static fn (TemplateAcl $entry): bool => $entry->getTemplateId() === $templateId,
			)),
		);

		$this->service = new PermissionService($this->aclMapper, $userManager, $groupManager);
	}

	/**
	 * @return IUser&MockObject
	 */
	private function userMock(string $uid): IUser {
		/** @var IUser&MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($this->existingUsers[$uid] ?? $uid);

		return $user;
	}

	private function addUser(string $uid): void {
		$this->existingUsers[$uid] = $uid;
	}

	private function joinGroup(string $uid, string $gid): void {
		$this->userGroups[$uid][] = $gid;
	}

	private function template(string $owner, int $id = 1): Template {
		$template = new Template();
		$template->setId($id);
		$template->setUuid('00000000-0000-4000-8000-000000000000');
		$template->setTitle('Deploy');
		$template->setDescription('');
		$template->setVersion(1);
		$template->setStatus(TemplateStatus::Draft->value);
		$template->setOwner($owner);
		$template->setCreatedAt(1000);
		$template->setUpdatedAt(1000);

		return $template;
	}

	private function entry(int $templateId, string $principalType, string $principalId, string $role): void {
		$entry = new TemplateAcl();
		$entry->setId($this->nextId++);
		$entry->setTemplateId($templateId);
		$entry->setPrincipalType($principalType);
		$entry->setPrincipalId($principalId);
		$entry->setRole($role);
		$entry->setCreatedAt(1000);
		$entry->setUpdatedAt(1000);
		$this->aclEntries[] = $entry;
	}

	public function testOwnerHasAllPermissionsWithoutAclRow(): void {
		$template = $this->template('alice');

		self::assertSame(AclRole::Owner, $this->service->getEffectiveRole($template, 'alice'));
		self::assertTrue($this->service->canView($template, 'alice'));
		self::assertTrue($this->service->canExecute($template, 'alice'));
		self::assertTrue($this->service->canEdit($template, 'alice'));
		self::assertTrue($this->service->canManageAcl($template, 'alice'));
		self::assertTrue($this->service->canDelete($template, 'alice'));
		self::assertSame([], $this->aclEntries);
	}

	public function testUnknownUserHasNoRole(): void {
		$this->addUser('bob');
		$template = $this->template('alice');

		self::assertNull($this->service->getEffectiveRole($template, 'bob'));
		self::assertFalse($this->service->canView($template, 'bob'));
	}

	public function testDirectViewerAccess(): void {
		$this->addUser('bob');
		$template = $this->template('alice');
		$this->entry(1, PrincipalType::User->value, 'bob', AclRole::Viewer->value);

		self::assertSame(AclRole::Viewer, $this->service->getEffectiveRole($template, 'bob'));
		self::assertTrue($this->service->canView($template, 'bob'));
		self::assertFalse($this->service->canExecute($template, 'bob'));
		self::assertFalse($this->service->canEdit($template, 'bob'));
		self::assertFalse($this->service->canManageAcl($template, 'bob'));
		self::assertFalse($this->service->canDelete($template, 'bob'));
	}

	public function testDirectExecutorAccess(): void {
		$this->addUser('bob');
		$template = $this->template('alice');
		$this->entry(1, PrincipalType::User->value, 'bob', AclRole::Executor->value);

		self::assertSame(AclRole::Executor, $this->service->getEffectiveRole($template, 'bob'));
		self::assertTrue($this->service->canExecute($template, 'bob'));
		self::assertFalse($this->service->canEdit($template, 'bob'));
	}

	public function testDirectEditorAccess(): void {
		$this->addUser('bob');
		$template = $this->template('alice');
		$this->entry(1, PrincipalType::User->value, 'bob', AclRole::Editor->value);

		self::assertSame(AclRole::Editor, $this->service->getEffectiveRole($template, 'bob'));
		self::assertTrue($this->service->canEdit($template, 'bob'));
		self::assertFalse($this->service->canManageAcl($template, 'bob'));
	}

	public function testGroupBasedAccess(): void {
		$this->addUser('bob');
		$this->joinGroup('bob', 'engineering');
		$template = $this->template('alice');
		$this->entry(1, PrincipalType::Group->value, 'engineering', AclRole::Executor->value);

		self::assertSame(AclRole::Executor, $this->service->getEffectiveRole($template, 'bob'));
	}

	public function testGroupAccessDoesNotApplyToNonMembers(): void {
		$this->addUser('bob');
		$template = $this->template('alice');
		$this->entry(1, PrincipalType::Group->value, 'engineering', AclRole::Editor->value);

		self::assertNull($this->service->getEffectiveRole($template, 'bob'));
	}

	public function testStrongestRoleWinsAcrossMultipleEntries(): void {
		$this->addUser('bob');
		$this->joinGroup('bob', 'engineering');
		$this->joinGroup('bob', 'operations');
		$template = $this->template('alice');
		$this->entry(1, PrincipalType::User->value, 'bob', AclRole::Viewer->value);
		$this->entry(1, PrincipalType::Group->value, 'engineering', AclRole::Editor->value);
		$this->entry(1, PrincipalType::Group->value, 'operations', AclRole::Executor->value);

		self::assertSame(AclRole::Editor, $this->service->getEffectiveRole($template, 'bob'));
	}

	public function testOwnerRoleEntryIsIgnoredDefensively(): void {
		$this->addUser('bob');
		$template = $this->template('alice');
		$this->entry(1, PrincipalType::User->value, 'bob', AclRole::Owner->value);

		self::assertNull($this->service->getEffectiveRole($template, 'bob'));
		self::assertFalse($this->service->canManageAcl($template, 'bob'));
	}

	public function testEntriesOfOtherTemplatesAreNotConsidered(): void {
		$this->addUser('bob');
		$template = $this->template('alice', 1);
		$this->entry(2, PrincipalType::User->value, 'bob', AclRole::Editor->value);

		self::assertNull($this->service->getEffectiveRole($template, 'bob'));
	}
}
