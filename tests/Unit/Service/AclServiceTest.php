<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\Template;
use OCA\Runbook\Db\TemplateAcl;
use OCA\Runbook\Db\TemplateAclMapper;
use OCA\Runbook\Db\TemplateMapper;
use OCA\Runbook\Enum\TemplateStatus;
use OCA\Runbook\Service\AclService;
use OCA\Runbook\Service\ConflictException;
use OCA\Runbook\Service\ForbiddenException;
use OCA\Runbook\Service\PermissionService;
use OCA\Runbook\Service\TransactionRunner;
use OCA\Runbook\Service\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour tests for ACL management.
 */
class AclServiceTest extends TestCase {
	/** @var array<int, Template> */
	private array $templates = [];
	/** @var array<int, TemplateAcl> */
	private array $aclEntries = [];
	/** @var array<string, string> */
	private array $existingUsers = [];
	/** @var array<string, string> */
	private array $existingGroups = [];
	/** @var array<string, list<string>> */
	private array $userGroups = [];
	private int $nextId = 1;
	private int $now = 1000;

	/** @var TemplateMapper&MockObject */
	private TemplateMapper $templateMapper;
	/** @var TemplateAclMapper&MockObject */
	private TemplateAclMapper $aclMapper;
	/** @var IUserManager&MockObject */
	private IUserManager $userManager;
	/** @var IGroupManager&MockObject */
	private IGroupManager $groupManager;
	/** @var TransactionRunner&MockObject */
	private TransactionRunner $transactionRunner;
	private PermissionService $permissionService;

	protected function setUp(): void {
		$this->nextId = 1;
		$this->now = 1000;

		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('userExists')->willReturnCallback(
			fn (string $uid, array $excludeBackends = []): bool => array_key_exists($uid, $this->existingUsers),
		);
		$this->userManager->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => array_key_exists($uid, $this->existingUsers) ? $this->userMock($uid) : null,
		);
		$this->userManager->method('searchDisplayName')->willReturnCallback(
			function (string $pattern, ?int $limit = null, ?int $offset = null): array {
				$result = [];
				foreach ($this->existingUsers as $uid => $displayName) {
					if (stripos($uid, $pattern) !== false || stripos($displayName, $pattern) !== false) {
						$result[] = $this->userMock($uid);
					}
				}

				return array_slice($result, 0, $limit ?? count($result));
			},
		);

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('groupExists')->willReturnCallback(
			fn (string $gid): bool => array_key_exists($gid, $this->existingGroups),
		);
		$this->groupManager->method('getUserGroupIds')->willReturnCallback(
			fn (IUser $user): array => $this->userGroups[$user->getUID()] ?? [],
		);
		$this->groupManager->method('search')->willReturnCallback(
			function (string $search, ?int $limit = null, ?int $offset = 0): array {
				$result = [];
				foreach ($this->existingGroups as $gid => $displayName) {
					if (stripos($gid, $search) !== false || stripos($displayName, $search) !== false) {
						$result[] = $this->groupMock($gid);
					}
				}

				return array_slice($result, 0, $limit ?? count($result));
			},
		);

		$this->templateMapper = $this->createMock(TemplateMapper::class);
		$this->templateMapper->method('find')->willReturnCallback(function (int $id): Template {
			if (!isset($this->templates[$id])) {
				throw new DoesNotExistException('template not found');
			}

			return $this->templates[$id];
		});

		$this->aclMapper = $this->createMock(TemplateAclMapper::class);
		$this->aclMapper->method('insert')->willReturnCallback(function (TemplateAcl $entry): TemplateAcl {
			$entry->setId($this->nextId++);
			$this->aclEntries[$entry->getId()] = $entry;

			return $entry;
		});
		$this->aclMapper->method('findByTemplate')->willReturnCallback(
			fn (int $templateId): array => array_values(array_filter(
				$this->aclEntries,
				static fn (TemplateAcl $entry): bool => $entry->getTemplateId() === $templateId,
			)),
		);
		$this->aclMapper->method('deleteByTemplate')->willReturnCallback(function (int $templateId): void {
			foreach ($this->aclEntries as $id => $entry) {
				if ($entry->getTemplateId() === $templateId) {
					unset($this->aclEntries[$id]);
				}
			}
		});

		$this->transactionRunner = $this->createMock(TransactionRunner::class);
		$this->transactionRunner->method('run')->willReturnCallback(
			static fn (callable $operation): mixed => $operation(),
		);

		$this->permissionService = new PermissionService($this->aclMapper, $this->userManager, $this->groupManager);
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

	/**
	 * @return IGroup&MockObject
	 */
	private function groupMock(string $gid): IGroup {
		/** @var IGroup&MockObject $group */
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$group->method('getDisplayName')->willReturn($this->existingGroups[$gid] ?? $gid);

		return $group;
	}

	private function addUser(string $uid, string $displayName = ''): void {
		$this->existingUsers[$uid] = $displayName !== '' ? $displayName : $uid;
	}

	private function addGroup(string $gid, string $displayName = ''): void {
		$this->existingGroups[$gid] = $displayName !== '' ? $displayName : $gid;
	}

	private function createTemplate(string $owner, string $status = 'DRAFT', int $id = 1): Template {
		$template = new Template();
		$template->setId($id);
		$template->setUuid('00000000-0000-4000-8000-000000000000');
		$template->setTitle('Deploy');
		$template->setDescription('');
		$template->setVersion(1);
		$template->setStatus($status);
		$template->setOwner($owner);
		$template->setCreatedAt($this->now);
		$template->setUpdatedAt($this->now);
		$this->templates[$id] = $template;
		$this->nextId = max($this->nextId, $id + 1);

		return $template;
	}

	private function seedAcl(int $templateId, string $principalType, string $principalId, string $role): TemplateAcl {
		$entry = new TemplateAcl();
		$entry->setId($this->nextId++);
		$entry->setTemplateId($templateId);
		$entry->setPrincipalType($principalType);
		$entry->setPrincipalId($principalId);
		$entry->setRole($role);
		$entry->setCreatedAt($this->now);
		$entry->setUpdatedAt($this->now);
		$this->aclEntries[$entry->getId()] = $entry;

		return $entry;
	}

	private function serviceFor(string $uid): AclService {
		/** @var IUser&MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		/** @var IUserSession&MockObject $session */
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		/** @var ITimeFactory&MockObject $timeFactory */
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new AclService(
			$this->templateMapper,
			$this->aclMapper,
			$this->permissionService,
			$this->userManager,
			$this->groupManager,
			$session,
			$timeFactory,
			$this->transactionRunner,
		);
	}

	public function testGetAclReturnsOwnerAndEntries(): void {
		$this->addUser('bob');
		$template = $this->createTemplate('alice');
		$this->seedAcl($template->getId(), 'USER', 'bob', 'VIEWER');

		$result = $this->serviceFor('alice')->getAcl($template->getId());

		self::assertSame('alice', $result['owner']);
		self::assertCount(1, $result['entries']);
		self::assertSame('bob', $result['entries'][0]->getPrincipalId());
	}

	public function testReplaceAclStoresNormalizedEntries(): void {
		$this->addUser('bob');
		$this->addGroup('engineering');
		$template = $this->createTemplate('alice');

		$result = $this->serviceFor('alice')->replaceAcl($template->getId(), [
			['principalType' => 'USER', 'principalId' => ' bob ', 'role' => 'EDITOR'],
			['principalType' => 'GROUP', 'principalId' => 'engineering', 'role' => 'EXECUTOR'],
		]);

		self::assertSame('alice', $result['owner']);
		self::assertCount(2, $result['entries']);
		self::assertSame('bob', $result['entries'][0]->getPrincipalId());
		self::assertSame('EDITOR', $result['entries'][0]->getRole());
		self::assertSame(1000, $result['entries'][0]->getCreatedAt());
	}

	public function testReplaceAclRejectsUnknownUser(): void {
		$template = $this->createTemplate('alice');

		$this->expectException(ValidationException::class);
		$this->serviceFor('alice')->replaceAcl($template->getId(), [
			['principalType' => 'USER', 'principalId' => 'ghost', 'role' => 'VIEWER'],
		]);
	}

	public function testReplaceAclRejectsUnknownGroup(): void {
		$template = $this->createTemplate('alice');

		$this->expectException(ValidationException::class);
		$this->serviceFor('alice')->replaceAcl($template->getId(), [
			['principalType' => 'GROUP', 'principalId' => 'ghosts', 'role' => 'VIEWER'],
		]);
	}

	public function testReplaceAclRejectsDuplicatePrincipals(): void {
		$this->addUser('bob');
		$template = $this->createTemplate('alice');

		$this->expectException(ValidationException::class);
		$this->serviceFor('alice')->replaceAcl($template->getId(), [
			['principalType' => 'USER', 'principalId' => 'bob', 'role' => 'VIEWER'],
			['principalType' => 'USER', 'principalId' => 'bob', 'role' => 'EDITOR'],
		]);
	}

	public function testReplaceAclRejectsOwnerRole(): void {
		$this->addUser('bob');
		$template = $this->createTemplate('alice');

		$this->expectException(ValidationException::class);
		$this->serviceFor('alice')->replaceAcl($template->getId(), [
			['principalType' => 'USER', 'principalId' => 'bob', 'role' => 'OWNER'],
		]);
	}

	public function testReplaceAclRejectsUnsupportedPrincipalType(): void {
		$template = $this->createTemplate('alice');

		$this->expectException(ValidationException::class);
		$this->serviceFor('alice')->replaceAcl($template->getId(), [
			['principalType' => 'TEAM', 'principalId' => 'bob', 'role' => 'VIEWER'],
		]);
	}

	public function testReplaceAclRejectsUnsupportedRole(): void {
		$this->addUser('bob');
		$template = $this->createTemplate('alice');

		$this->expectException(ValidationException::class);
		$this->serviceFor('alice')->replaceAcl($template->getId(), [
			['principalType' => 'USER', 'principalId' => 'bob', 'role' => 'ADMIN'],
		]);
	}

	public function testReplaceAclRejectsEmptyPrincipalId(): void {
		$template = $this->createTemplate('alice');

		$this->expectException(ValidationException::class);
		$this->serviceFor('alice')->replaceAcl($template->getId(), [
			['principalType' => 'USER', 'principalId' => '   ', 'role' => 'VIEWER'],
		]);
	}

	public function testNonOwnerCannotReplaceAcl(): void {
		$this->addUser('bob');
		$template = $this->createTemplate('alice');

		$this->expectException(ForbiddenException::class);
		$this->serviceFor('bob')->replaceAcl($template->getId(), [
			['principalType' => 'USER', 'principalId' => 'bob', 'role' => 'EDITOR'],
		]);
	}

	public function testEditorCannotReplaceAcl(): void {
		$this->addUser('bob');
		$template = $this->createTemplate('alice');
		$this->seedAcl($template->getId(), 'USER', 'bob', 'EDITOR');

		$this->expectException(ForbiddenException::class);
		$this->serviceFor('bob')->replaceAcl($template->getId(), []);
	}

	public function testArchivedTemplateAclIsReadOnly(): void {
		$this->addUser('bob');
		$template = $this->createTemplate('alice', TemplateStatus::Archived->value);

		$this->expectException(ConflictException::class);
		$this->serviceFor('alice')->replaceAcl($template->getId(), [
			['principalType' => 'USER', 'principalId' => 'bob', 'role' => 'VIEWER'],
		]);
	}

	public function testValidationFailureLeavesExistingAclUntouched(): void {
		$this->addUser('bob');
		$template = $this->createTemplate('alice');
		$existing = $this->seedAcl($template->getId(), 'USER', 'bob', 'VIEWER');

		try {
			$this->serviceFor('alice')->replaceAcl($template->getId(), [
				['principalType' => 'USER', 'principalId' => 'ghost', 'role' => 'EDITOR'],
			]);
			self::fail('Expected a validation exception');
		} catch (ValidationException) {
			// expected
		}

		self::assertCount(1, $this->aclEntries);
		self::assertArrayHasKey($existing->getId(), $this->aclEntries);
	}

	public function testSearchPrincipalsReturnsUsersAndGroups(): void {
		$this->addUser('bob', 'Bob');
		$this->addGroup('engineering', 'Engineering');
		$service = $this->serviceFor('alice');

		$users = $service->searchPrincipals('Bob', 25);
		self::assertCount(1, $users);
		self::assertSame('USER', $users[0]['principalType']);
		self::assertSame('bob', $users[0]['principalId']);
		self::assertSame('Bob', $users[0]['displayName']);

		$groups = $service->searchPrincipals('Eng', 25);
		self::assertCount(1, $groups);
		self::assertSame('GROUP', $groups[0]['principalType']);
		self::assertSame('engineering', $groups[0]['principalId']);
		self::assertSame('Engineering', $groups[0]['displayName']);
	}

	public function testSearchPrincipalsReturnsEmptyListForEmptySearch(): void {
		$this->addUser('bob', 'Bob');

		self::assertSame([], $this->serviceFor('alice')->searchPrincipals('   ', 25));
	}
}
