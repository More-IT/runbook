<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\Template;
use OCA\Runbook\Db\TemplateAcl;
use OCA\Runbook\Db\TemplateAclMapper;
use OCA\Runbook\Db\TemplateMapper;
use OCA\Runbook\Db\TemplateSection;
use OCA\Runbook\Db\TemplateSectionMapper;
use OCA\Runbook\Db\TemplateStep;
use OCA\Runbook\Db\TemplateStepMapper;
use OCA\Runbook\Enum\AclRole;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\TemplateStatus;
use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\ConflictException;
use OCA\Runbook\Service\ForbiddenException;
use OCA\Runbook\Service\PermissionService;
use OCA\Runbook\Service\TemplateCreationPolicyService;
use OCA\Runbook\Service\TemplateService;
use OCA\Runbook\Service\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour tests for the template authoring service.
 *
 * The mappers are backed by in-memory arrays so that ordering, state
 * transitions and ACL resolution are exercised without a database.
 */
class TemplateServiceTest extends TestCase {
	/** @var array<int, Template> */
	private array $templates = [];
	/** @var array<int, TemplateSection> */
	private array $sections = [];
	/** @var array<int, TemplateStep> */
	private array $steps = [];
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
	/** @var TemplateSectionMapper&MockObject */
	private TemplateSectionMapper $sectionMapper;
	/** @var TemplateStepMapper&MockObject */
	private TemplateStepMapper $stepMapper;
	/** @var TemplateAclMapper&MockObject */
	private TemplateAclMapper $aclMapper;
	/** @var IUserManager&MockObject */
	private IUserManager $userManager;
	/** @var IGroupManager&MockObject */
	private IGroupManager $groupManager;
	private PermissionService $permissionService;
	private TemplateCreationPolicyService $creationPolicy;
	/** @var array<string, mixed> */
	private array $configValues = [];
	/** @var list<string> */
	private array $adminUsers = [];
	/** @var ITimeFactory&MockObject */
	private ITimeFactory $timeFactory;
	/** @var ISecureRandom&MockObject */
	private ISecureRandom $random;

	protected function setUp(): void {
		$this->nextId = 1;
		$this->now = 1000;

		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$this->random = $this->createMock(ISecureRandom::class);
		$this->random->method('generate')->willReturnCallback(
			static fn (int $length, string $characters = ''): string => str_repeat('a', $length),
		);

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

				return $result;
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

				return $result;
			},
		);

		/** @var IAppConfig&MockObject $appConfig */
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '', bool $lazy = false): string => is_string($this->configValues[$key] ?? null) ? $this->configValues[$key] : $default,
		);
		$appConfig->method('getValueArray')->willReturnCallback(
			fn (string $app, string $key, array $default = [], bool $lazy = false): array => is_array($this->configValues[$key] ?? null) ? $this->configValues[$key] : $default,
		);
		$appConfig->method('getValueBool')->willReturnCallback(static fn (string $app, string $key, bool $default = false, bool $lazy = false): bool => $default);
		$appConfig->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0, bool $lazy = false): int => $default);
		$this->groupManager->method('isAdmin')->willReturnCallback(
			fn (string $uid): bool => in_array($uid, $this->adminUsers, true),
		);
		$this->creationPolicy = new TemplateCreationPolicyService(
			new AdminSettings($appConfig, $this->groupManager),
			$this->groupManager,
			$this->userManager,
		);

		$this->templateMapper = $this->createMock(TemplateMapper::class);
		$this->templateMapper->method('insert')->willReturnCallback(function (Template $template): Template {
			$template->setId($this->nextId++);
			$this->templates[$template->getId()] = $template;

			return $template;
		});
		$this->templateMapper->method('update')->willReturnCallback(function (Template $template): Template {
			$this->templates[$template->getId()] = $template;

			return $template;
		});
		$this->templateMapper->method('delete')->willReturnCallback(function (Template $template): Template {
			unset($this->templates[$template->getId()]);
			// Simulate the ON DELETE CASCADE constraint for ACL rows.
			foreach ($this->aclEntries as $id => $entry) {
				if ($entry->getTemplateId() === $template->getId()) {
					unset($this->aclEntries[$id]);
				}
			}

			return $template;
		});
		$this->templateMapper->method('find')->willReturnCallback(function (int $id): Template {
			if (!isset($this->templates[$id])) {
				throw new DoesNotExistException('template not found');
			}

			return $this->templates[$id];
		});
		$this->templateMapper->method('findAccessible')->willReturnCallback(function (string $owner, array $templateIds): array {
			$result = array_values(array_filter(
				$this->templates,
				static fn (Template $template): bool => $template->getOwner() === $owner
					|| in_array($template->getId(), $templateIds, true),
			));

			return $result;
		});

		$this->sectionMapper = $this->createMock(TemplateSectionMapper::class);
		$this->sectionMapper->method('insert')->willReturnCallback(function (TemplateSection $section): TemplateSection {
			$section->setId($this->nextId++);
			$this->sections[$section->getId()] = $section;

			return $section;
		});
		$this->sectionMapper->method('update')->willReturnCallback(function (TemplateSection $section): TemplateSection {
			$this->sections[$section->getId()] = $section;

			return $section;
		});
		$this->sectionMapper->method('delete')->willReturnCallback(function (TemplateSection $section): TemplateSection {
			unset($this->sections[$section->getId()]);

			return $section;
		});
		$this->sectionMapper->method('find')->willReturnCallback(function (int $id): TemplateSection {
			if (!isset($this->sections[$id])) {
				throw new DoesNotExistException('section not found');
			}

			return $this->sections[$id];
		});
		$this->sectionMapper->method('findByTemplate')->willReturnCallback(function (int $templateId): array {
			$result = array_values(array_filter(
				$this->sections,
				static fn (TemplateSection $section): bool => $section->getTemplateId() === $templateId,
			));
			usort($result, static fn (TemplateSection $a, TemplateSection $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

			return $result;
		});

		$this->stepMapper = $this->createMock(TemplateStepMapper::class);
		$this->stepMapper->method('insert')->willReturnCallback(function (TemplateStep $step): TemplateStep {
			$step->setId($this->nextId++);
			$this->steps[$step->getId()] = $step;

			return $step;
		});
		$this->stepMapper->method('update')->willReturnCallback(function (TemplateStep $step): TemplateStep {
			$this->steps[$step->getId()] = $step;

			return $step;
		});
		$this->stepMapper->method('delete')->willReturnCallback(function (TemplateStep $step): TemplateStep {
			unset($this->steps[$step->getId()]);

			return $step;
		});
		$this->stepMapper->method('find')->willReturnCallback(function (int $id): TemplateStep {
			if (!isset($this->steps[$id])) {
				throw new DoesNotExistException('step not found');
			}

			return $this->steps[$id];
		});
		$this->stepMapper->method('findBySection')->willReturnCallback(function (int $sectionId): array {
			$result = array_values(array_filter(
				$this->steps,
				static fn (TemplateStep $step): bool => $step->getSectionId() === $sectionId,
			));
			usort($result, static fn (TemplateStep $a, TemplateStep $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

			return $result;
		});

		$this->aclMapper = $this->createMock(TemplateAclMapper::class);
		$this->aclMapper->method('insert')->willReturnCallback(function (TemplateAcl $entry): TemplateAcl {
			$entry->setId($this->nextId++);
			$this->aclEntries[$entry->getId()] = $entry;

			return $entry;
		});
		$this->aclMapper->method('find')->willReturnCallback(function (int $id): TemplateAcl {
			if (!isset($this->aclEntries[$id])) {
				throw new DoesNotExistException('acl entry not found');
			}

			return $this->aclEntries[$id];
		});
		$this->aclMapper->method('findByTemplate')->willReturnCallback(function (int $templateId): array {
			return array_values(array_filter(
				$this->aclEntries,
				static fn (TemplateAcl $entry): bool => $entry->getTemplateId() === $templateId,
			));
		});
		$this->aclMapper->method('deleteByTemplate')->willReturnCallback(function (int $templateId): void {
			foreach ($this->aclEntries as $id => $entry) {
				if ($entry->getTemplateId() === $templateId) {
					unset($this->aclEntries[$id]);
				}
			}
		});
		$this->aclMapper->method('findTemplateIdsForPrincipal')->willReturnCallback(function (string $uid, array $groupIds): array {
			$templateIds = [];
			foreach ($this->aclEntries as $entry) {
				$applies = ($entry->getPrincipalType() === PrincipalType::User->value && $entry->getPrincipalId() === $uid)
					|| ($entry->getPrincipalType() === PrincipalType::Group->value && in_array($entry->getPrincipalId(), $groupIds, true));
				if ($applies) {
					$templateIds[] = $entry->getTemplateId();
				}
			}

			/** @var list<int> $unique */
			$unique = array_values(array_unique($templateIds));

			return $unique;
		});

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

	private function joinGroup(string $uid, string $gid): void {
		$this->userGroups[$uid][] = $gid;
	}

	private function seedAcl(int $templateId, string $principalType, string $principalId, string $role): TemplateAcl {
		$entry = new TemplateAcl();
		$entry->setTemplateId($templateId);
		$entry->setPrincipalType($principalType);
		$entry->setPrincipalId($principalId);
		$entry->setRole($role);
		$entry->setCreatedAt($this->now);
		$entry->setUpdatedAt($this->now);
		$entry->setId($this->nextId++);
		$this->aclEntries[$entry->getId()] = $entry;

		return $entry;
	}

	private function serviceFor(string $uid): TemplateService {
		/** @var IUser&MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		/** @var IUserSession&MockObject $session */
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new TemplateService(
			$this->templateMapper,
			$this->sectionMapper,
			$this->stepMapper,
			$session,
			$this->timeFactory,
			$this->random,
			$this->aclMapper,
			$this->permissionService,
			$this->creationPolicy,
			$this->groupManager,
		);
	}

	public function testNewTemplateStartsWithVersionOne(): void {
		$service = $this->serviceFor('alice');

		$template = $service->createTemplate(['title' => 'Deploy', 'description' => 'Steps']);

		self::assertSame('Deploy', $template->getTitle());
		self::assertSame(TemplateStatus::Draft->value, $template->getStatus());
		self::assertSame('alice', $template->getOwner());
		self::assertSame(1, $template->getVersion());
		self::assertSame(36, strlen($template->getUuid()));
		self::assertNull($template->getPublishedAt());
	}

	public function testPublishRejectsEmptyTitle(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => '   ']);

		$this->expectException(ValidationException::class);
		$service->publishTemplate($template->getId());
	}

	public function testAddSectionsAndSteps(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);

		$section = $service->createSection($template->getId(), ['title' => 'Preparation']);
		$step = $service->createStep($section->getId(), [
			'title' => 'Check backups',
			'type' => 'CHECK',
			'required' => true,
		]);

		self::assertSame($template->getId(), $section->getTemplateId());
		self::assertSame(0, $section->getPosition());
		self::assertSame($section->getId(), $step->getSectionId());
		self::assertSame('CHECK', $step->getType());
		self::assertTrue($step->getRequired());
		self::assertSame(0, $step->getPosition());
		self::assertSame(36, strlen($step->getUuid()));
	}

	public function testSectionNotesArePersistedAndUpdated(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);

		$section = $service->createSection($template->getId(), [
			'title' => 'Preparation',
			'notes' => 'Call the on-call engineer.',
		]);
		self::assertSame('Call the on-call engineer.', $section->getNotes());

		$updated = $service->updateSection($section->getId(), ['notes' => 'Escalate to the platform team.']);
		self::assertSame('Escalate to the platform team.', $updated->getNotes());
	}

	public function testSectionNotesTooLongIsRejected(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);

		$this->expectException(ValidationException::class);
		$service->createSection($template->getId(), [
			'title' => 'Preparation',
			'notes' => str_repeat('a', 10001),
		]);
	}

	public function testSectionPositionsAreNormalizedAfterDelete(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$first = $service->createSection($template->getId(), ['title' => 'A']);
		$second = $service->createSection($template->getId(), ['title' => 'B']);
		$third = $service->createSection($template->getId(), ['title' => 'C']);

		$service->deleteSection($second->getId());
		$remaining = $service->getSections($template->getId());

		self::assertSame([$first->getId(), $third->getId()], array_map(static fn (TemplateSection $section): int => $section->getId(), $remaining));
		self::assertSame(0, $remaining[0]->getPosition());
		self::assertSame(1, $remaining[1]->getPosition());
	}

	public function testSectionReorderNormalizesPositions(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$a = $service->createSection($template->getId(), ['title' => 'A']);
		$b = $service->createSection($template->getId(), ['title' => 'B']);
		$c = $service->createSection($template->getId(), ['title' => 'C']);

		$ordered = $service->reorderSection($c->getId(), 0);

		self::assertSame([$c->getId(), $a->getId(), $b->getId()], array_map(static fn (TemplateSection $section): int => $section->getId(), $ordered));
		self::assertSame([0, 1, 2], array_map(static fn (TemplateSection $section): int => $section->getPosition(), $ordered));
	}

	public function testStepReorderNormalizesPositions(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$section = $service->createSection($template->getId(), ['title' => 'Prep']);
		$first = $service->createStep($section->getId(), ['title' => 'One', 'type' => 'CHECK']);
		$second = $service->createStep($section->getId(), ['title' => 'Two', 'type' => 'TEXT']);
		$third = $service->createStep($section->getId(), ['title' => 'Three', 'type' => 'NUMBER']);

		$ordered = $service->reorderStep($third->getId(), 0);

		self::assertSame([$third->getId(), $first->getId(), $second->getId()], array_map(static fn (TemplateStep $step): int => $step->getId(), $ordered));
		self::assertSame([0, 1, 2], array_map(static fn (TemplateStep $step): int => $step->getPosition(), $ordered));
	}

	public function testReorderRejectsNegativePosition(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$section = $service->createSection($template->getId(), ['title' => 'A']);

		$this->expectException(ValidationException::class);
		$service->reorderSection($section->getId(), -1);
	}

	public function testPublishVersionOneDraftKeepsVersionOne(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);

		$published = $service->publishTemplate($template->getId());

		self::assertSame(TemplateStatus::Published->value, $published->getStatus());
		self::assertSame(1, $published->getVersion());
		self::assertSame(1000, $published->getPublishedAt());
	}

	public function testArchiveTemplate(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);

		$archived = $service->archiveTemplate($template->getId());

		self::assertSame(TemplateStatus::Archived->value, $archived->getStatus());
		self::assertSame(1000, $archived->getArchivedAt());
	}

	public function testEditingArchivedTemplateIsRejected(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$section = $service->createSection($template->getId(), ['title' => 'A']);
		$service->archiveTemplate($template->getId());

		$this->expectException(ConflictException::class);
		$service->updateSection($section->getId(), ['title' => 'Changed']);
	}

	public function testNonOwnerCannotAccessDraft(): void {
		$alice = $this->serviceFor('alice');
		$template = $alice->createTemplate(['title' => 'Private']);

		$bob = $this->serviceFor('bob');

		$this->expectException(ForbiddenException::class);
		$bob->getTemplate($template->getId());
	}

	public function testNonOwnerCannotEditPublishedTemplate(): void {
		$alice = $this->serviceFor('alice');
		$template = $alice->createTemplate(['title' => 'Shared']);
		$alice->publishTemplate($template->getId());

		$bob = $this->serviceFor('bob');

		$this->expectException(ForbiddenException::class);
		$bob->updateTemplate($template->getId(), ['title' => 'Hijacked']);
	}

	public function testPublishedTemplateWithoutAclIsNotVisibleToOtherUsers(): void {
		$alice = $this->serviceFor('alice');
		$template = $alice->createTemplate(['title' => 'Shared']);
		$alice->publishTemplate($template->getId());

		$bob = $this->serviceFor('bob');

		$this->expectException(ForbiddenException::class);
		$bob->getTemplate($template->getId());
	}

	public function testEditingPublishedTemplateIncrementsVersion(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$service->publishTemplate($template->getId());

		$updated = $service->updateTemplate($template->getId(), ['title' => 'Deploy v2']);

		self::assertSame('Deploy v2', $updated->getTitle());
		self::assertSame(2, $updated->getVersion());
	}

	public function testNoOpEditDoesNotIncrementVersion(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$service->publishTemplate($template->getId());

		$updated = $service->updateTemplate($template->getId(), ['title' => 'Deploy']);

		self::assertSame(1, $updated->getVersion());
	}

	public function testAddSectionToPublishedTemplateIncrementsVersion(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$service->publishTemplate($template->getId());

		$service->createSection($template->getId(), ['title' => 'New']);

		self::assertSame(2, $this->templates[$template->getId()]->getVersion());
	}

	public function testSectionReorderToCurrentPositionDoesNotIncrementVersion(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$section = $service->createSection($template->getId(), ['title' => 'A']);
		$service->createSection($template->getId(), ['title' => 'B']);
		$service->publishTemplate($template->getId());

		$this->now = 2000;
		$service->reorderSection($section->getId(), $section->getPosition());

		$stored = $this->templates[$template->getId()];
		self::assertSame(1, $stored->getVersion());
		self::assertSame(1000, $stored->getUpdatedAt());
	}

	public function testStepReorderToCurrentPositionDoesNotIncrementVersion(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$section = $service->createSection($template->getId(), ['title' => 'Prep']);
		$step = $service->createStep($section->getId(), ['title' => 'One', 'type' => 'CHECK']);
		$service->createStep($section->getId(), ['title' => 'Two', 'type' => 'TEXT']);
		$service->publishTemplate($template->getId());

		$this->now = 2000;
		$service->reorderStep($step->getId(), $step->getPosition());

		$stored = $this->templates[$template->getId()];
		self::assertSame(1, $stored->getVersion());
		self::assertSame(1000, $stored->getUpdatedAt());
	}

	public function testRealSectionReorderIncrementsVersionOnce(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$service->createSection($template->getId(), ['title' => 'A']);
		$service->createSection($template->getId(), ['title' => 'B']);
		$last = $service->createSection($template->getId(), ['title' => 'C']);
		$service->publishTemplate($template->getId());

		$this->now = 2000;
		$service->reorderSection($last->getId(), 0);

		$stored = $this->templates[$template->getId()];
		self::assertSame(2, $stored->getVersion());
		self::assertSame(2000, $stored->getUpdatedAt());
	}

	public function testRealStepReorderIncrementsVersionOnce(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$section = $service->createSection($template->getId(), ['title' => 'Prep']);
		$service->createStep($section->getId(), ['title' => 'One', 'type' => 'CHECK']);
		$service->createStep($section->getId(), ['title' => 'Two', 'type' => 'TEXT']);
		$last = $service->createStep($section->getId(), ['title' => 'Three', 'type' => 'NUMBER']);
		$service->publishTemplate($template->getId());

		$this->now = 2000;
		$service->reorderStep($last->getId(), 0);

		$stored = $this->templates[$template->getId()];
		self::assertSame(2, $stored->getVersion());
		self::assertSame(2000, $stored->getUpdatedAt());
	}

	public function testCreateStepRejectsInvalidType(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$section = $service->createSection($template->getId(), ['title' => 'A']);

		$this->expectException(ValidationException::class);
		$service->createStep($section->getId(), ['title' => 'Bad', 'type' => 'NOPE']);
	}

	public function testCreateSelectStepRequiresOptions(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$section = $service->createSection($template->getId(), ['title' => 'A']);

		$this->expectException(ValidationException::class);
		$service->createStep($section->getId(), ['title' => 'Choose', 'type' => 'SELECT', 'config' => []]);
	}

	public function testDeleteTemplateRemovesIt(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);

		$service->deleteTemplate($template->getId());

		self::assertArrayNotHasKey($template->getId(), $this->templates);
	}

	public function testOwnerHasAllPermissionsWithoutAclRow(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);

		$permissions = $service->getPermissions($template);

		self::assertSame(AclRole::Owner->value, $permissions['role']);
		self::assertTrue($permissions['canView']);
		self::assertTrue($permissions['canExecute']);
		self::assertTrue($permissions['canEdit']);
		self::assertTrue($permissions['canManageAcl']);
		self::assertTrue($permissions['canDelete']);
		self::assertSame([], $this->aclEntries);
	}

	public function testViewerCanViewButNotEdit(): void {
		$this->addUser('bob');
		$alice = $this->serviceFor('alice');
		$template = $alice->createTemplate(['title' => 'Deploy']);
		$this->seedAcl($template->getId(), PrincipalType::User->value, 'bob', AclRole::Viewer->value);

		$bob = $this->serviceFor('bob');
		self::assertSame($template->getId(), $bob->getTemplate($template->getId())->getId());

		$permissions = $bob->getPermissions($template);
		self::assertSame(AclRole::Viewer->value, $permissions['role']);
		self::assertFalse($permissions['canEdit']);
		self::assertFalse($permissions['canExecute']);
		self::assertFalse($permissions['canManageAcl']);

		$this->expectException(ForbiddenException::class);
		$bob->updateTemplate($template->getId(), ['title' => 'Hijacked']);
	}

	public function testExecutorCanViewAndIsMarkedExecutableButCannotEdit(): void {
		$this->addUser('bob');
		$alice = $this->serviceFor('alice');
		$template = $alice->createTemplate(['title' => 'Deploy']);
		$this->seedAcl($template->getId(), PrincipalType::User->value, 'bob', AclRole::Executor->value);

		$bob = $this->serviceFor('bob');
		$permissions = $bob->getPermissions($template);

		self::assertSame(AclRole::Executor->value, $permissions['role']);
		self::assertTrue($permissions['canExecute']);
		self::assertFalse($permissions['canEdit']);

		$this->expectException(ForbiddenException::class);
		$bob->updateTemplate($template->getId(), ['title' => 'Hijacked']);
	}

	public function testEditorCanUpdateTemplateContent(): void {
		$this->addUser('bob');
		$alice = $this->serviceFor('alice');
		$template = $alice->createTemplate(['title' => 'Deploy']);
		$this->seedAcl($template->getId(), PrincipalType::User->value, 'bob', AclRole::Editor->value);

		$bob = $this->serviceFor('bob');
		$permissions = $bob->getPermissions($template);
		self::assertTrue($permissions['canEdit']);
		self::assertFalse($permissions['canManageAcl']);

		$updated = $bob->updateTemplate($template->getId(), ['title' => 'Edited by Bob']);
		self::assertSame('Edited by Bob', $updated->getTitle());
	}

	public function testGroupAclGrantsViewAccess(): void {
		$this->addUser('bob');
		$this->addGroup('engineering');
		$this->joinGroup('bob', 'engineering');

		$alice = $this->serviceFor('alice');
		$template = $alice->createTemplate(['title' => 'Deploy']);
		$this->seedAcl($template->getId(), PrincipalType::Group->value, 'engineering', AclRole::Viewer->value);

		$bob = $this->serviceFor('bob');
		self::assertSame($template->getId(), $bob->getTemplate($template->getId())->getId());
	}

	public function testStrongestRoleWinsAcrossDirectAndGroupEntries(): void {
		$this->addUser('bob');
		$this->addGroup('engineering');
		$this->joinGroup('bob', 'engineering');

		$alice = $this->serviceFor('alice');
		$template = $alice->createTemplate(['title' => 'Deploy']);
		$this->seedAcl($template->getId(), PrincipalType::User->value, 'bob', AclRole::Viewer->value);
		$this->seedAcl($template->getId(), PrincipalType::Group->value, 'engineering', AclRole::Editor->value);

		$bob = $this->serviceFor('bob');
		$permissions = $bob->getPermissions($template);

		self::assertSame(AclRole::Editor->value, $permissions['role']);
		self::assertTrue($permissions['canEdit']);
	}

	public function testTemplateListIncludesOwnedAndSharedTemplates(): void {
		$this->addUser('bob');
		$alice = $this->serviceFor('alice');
		$owned = $alice->createTemplate(['title' => 'Owned']);

		$carol = $this->serviceFor('carol');
		$shared = $carol->createTemplate(['title' => 'Shared']);
		$this->seedAcl($shared->getId(), PrincipalType::User->value, 'alice', AclRole::Viewer->value);
		$inaccessible = $carol->createTemplate(['title' => 'Private']);

		$ids = array_map(static fn (Template $template): int => $template->getId(), $alice->listTemplates());

		self::assertContains($owned->getId(), $ids);
		self::assertContains($shared->getId(), $ids);
		self::assertNotContains($inaccessible->getId(), $ids);
	}

	public function testArchivedTemplateRemainsReadOnlyForOwner(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$service->archiveTemplate($template->getId());

		$permissions = $service->getPermissions($template);
		self::assertTrue($permissions['canView']);
		self::assertFalse($permissions['canEdit']);
		self::assertFalse($permissions['canManageAcl']);

		$section = new TemplateSection();
		$section->setTemplateId($template->getId());
		$section->setTitle('A');
		$section->setDescription('');
		$section->setPosition(0);
		$section->setId($this->nextId++);
		$this->sections[$section->getId()] = $section;

		$this->expectException(ConflictException::class);
		$service->updateSection($section->getId(), ['title' => 'Changed']);
	}

	public function testDeletingTemplateCascadesAclRows(): void {
		$this->addUser('bob');
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$entry = $this->seedAcl($template->getId(), PrincipalType::User->value, 'bob', AclRole::Viewer->value);

		$service->deleteTemplate($template->getId());

		self::assertArrayNotHasKey($entry->getId(), $this->aclEntries);
	}

	public function testAdminsOnlyPolicyRejectsNonAdminCreation(): void {
		$this->configValues[AdminSettings::KEY_TEMPLATE_CREATION_POLICY] = 'admins';

		$this->expectException(ForbiddenException::class);
		$this->serviceFor('alice')->createTemplate(['title' => 'Deploy']);
	}

	public function testAdminsOnlyPolicyAllowsAdminCreation(): void {
		$this->configValues[AdminSettings::KEY_TEMPLATE_CREATION_POLICY] = 'admins';
		$this->adminUsers[] = 'alice';

		$template = $this->serviceFor('alice')->createTemplate(['title' => 'Deploy']);

		self::assertSame('alice', $template->getOwner());
	}

	public function testSelectedGroupsPolicyRejectsNonMemberCreation(): void {
		$this->configValues[AdminSettings::KEY_TEMPLATE_CREATION_POLICY] = 'selected_groups';
		$this->configValues[AdminSettings::KEY_TEMPLATE_CREATOR_GROUPS] = ['engineering'];

		$this->expectException(ForbiddenException::class);
		$this->serviceFor('alice')->createTemplate(['title' => 'Deploy']);
	}

	public function testSelectedGroupsPolicyAllowsMemberCreation(): void {
		$this->configValues[AdminSettings::KEY_TEMPLATE_CREATION_POLICY] = 'selected_groups';
		$this->configValues[AdminSettings::KEY_TEMPLATE_CREATOR_GROUPS] = ['engineering'];
		$this->addUser('alice');
		$this->addGroup('engineering');
		$this->joinGroup('alice', 'engineering');

		$template = $this->serviceFor('alice')->createTemplate(['title' => 'Deploy']);

		self::assertSame('alice', $template->getOwner());
	}

	public function testPublishedTemplateCanBeArchived(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$service->publishTemplate($template->getId());

		$archived = $service->archiveTemplate($template->getId());

		self::assertSame(TemplateStatus::Archived->value, $archived->getStatus());
	}

	public function testArchivedTemplateCannotBePublished(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$service->archiveTemplate($template->getId());

		$this->expectException(ConflictException::class);
		$service->publishTemplate($template->getId());
	}

	public function testUnarchivePublishedTemplateRestoresPublishedStatus(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$service->publishTemplate($template->getId());
		$service->archiveTemplate($template->getId());

		$restored = $service->unarchiveTemplate($template->getId());

		self::assertSame(TemplateStatus::Published->value, $restored->getStatus());
		self::assertNull($restored->getArchivedAt());
	}

	public function testUnarchiveDraftTemplateRestoresDraftStatus(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$service->archiveTemplate($template->getId());

		$restored = $service->unarchiveTemplate($template->getId());

		self::assertSame(TemplateStatus::Draft->value, $restored->getStatus());
	}

	public function testUnarchiveRejectsNonOwner(): void {
		$alice = $this->serviceFor('alice');
		$template = $alice->createTemplate(['title' => 'Deploy']);
		$alice->archiveTemplate($template->getId());

		$this->expectException(ForbiddenException::class);
		$this->serviceFor('bob')->unarchiveTemplate($template->getId());
	}

	public function testAdminCanUnarchiveTemplate(): void {
		$alice = $this->serviceFor('alice');
		$template = $alice->createTemplate(['title' => 'Deploy']);
		$alice->archiveTemplate($template->getId());
		$this->adminUsers[] = 'bob';

		$restored = $this->serviceFor('bob')->unarchiveTemplate($template->getId());

		self::assertSame(TemplateStatus::Draft->value, $restored->getStatus());
	}

	public function testUnarchiveRejectsActiveTemplate(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);

		$this->expectException(ConflictException::class);
		$service->unarchiveTemplate($template->getId());
	}

	public function testOwnerCanDuplicateTemplate(): void {
		$alice = $this->serviceFor('alice');
		$template = $alice->createTemplate(['title' => 'Deploy', 'description' => 'Steps']);
		$section = $alice->createSection($template->getId(), ['title' => 'Prep', 'notes' => 'Note']);
		$alice->createStep($section->getId(), ['title' => 'Check', 'type' => 'CHECK', 'required' => true]);

		$copy = $alice->duplicateTemplate($template->getId());

		self::assertNotSame($template->getId(), $copy->getId());
		self::assertSame('alice', $copy->getOwner());
		self::assertSame('Copy of Deploy', $copy->getTitle());
		self::assertSame('Steps', $copy->getDescription());
		self::assertSame(TemplateStatus::Draft->value, $copy->getStatus());
		self::assertSame(1, $copy->getVersion());

		$copiedSections = $alice->getSections($copy->getId());
		self::assertCount(1, $copiedSections);
		self::assertSame('Prep', $copiedSections[0]->getTitle());
		self::assertSame('Note', $copiedSections[0]->getNotes());

		$copiedSteps = $alice->getSteps($copiedSections[0]->getId());
		self::assertCount(1, $copiedSteps);
		self::assertSame('Check', $copiedSteps[0]->getTitle());
		self::assertSame('CHECK', $copiedSteps[0]->getType());

		// The original template is left completely unchanged.
		self::assertCount(1, $alice->getSections($template->getId()));
		self::assertSame('Deploy', $alice->getTemplate($template->getId())->getTitle());
	}

	public function testAdminCanDuplicateTemplate(): void {
		$alice = $this->serviceFor('alice');
		$template = $alice->createTemplate(['title' => 'Deploy']);
		$this->adminUsers[] = 'bob';

		$copy = $this->serviceFor('bob')->duplicateTemplate($template->getId());

		self::assertSame('bob', $copy->getOwner());
		self::assertSame('Copy of Deploy', $copy->getTitle());
	}

	public function testEditorCannotDuplicateTemplate(): void {
		$alice = $this->serviceFor('alice');
		$template = $alice->createTemplate(['title' => 'Deploy']);
		$this->addUser('editor');
		$this->seedAcl($template->getId(), PrincipalType::User->value, 'editor', AclRole::Editor->value);

		$this->expectException(ForbiddenException::class);
		$this->serviceFor('editor')->duplicateTemplate($template->getId());
	}

	public function testViewerCannotDuplicateTemplate(): void {
		$alice = $this->serviceFor('alice');
		$template = $alice->createTemplate(['title' => 'Deploy']);
		$this->addUser('viewer');
		$this->seedAcl($template->getId(), PrincipalType::User->value, 'viewer', AclRole::Viewer->value);

		$this->expectException(ForbiddenException::class);
		$this->serviceFor('viewer')->duplicateTemplate($template->getId());
	}

	public function testDuplicateDoesNotCopyAcl(): void {
		$alice = $this->serviceFor('alice');
		$template = $alice->createTemplate(['title' => 'Deploy']);
		$this->addUser('carol');
		$this->seedAcl($template->getId(), PrincipalType::User->value, 'carol', AclRole::Viewer->value);

		$copy = $alice->duplicateTemplate($template->getId());

		self::assertFalse($this->permissionService->canView($this->templates[$copy->getId()], 'carol'));
		self::assertTrue($this->permissionService->canView($this->templates[$copy->getId()], 'alice'));
	}

	public function testSectionDependenciesArePersisted(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$first = $service->createSection($template->getId(), ['title' => 'A']);
		$second = $service->createSection($template->getId(), ['title' => 'B', 'dependsOn' => [$first->getId()]]);

		self::assertSame([$first->getId()], $second->getDependsOnIds());
	}

	public function testUnknownSectionDependencyIsRejected(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);

		$this->expectException(ValidationException::class);
		$service->createSection($template->getId(), ['title' => 'B', 'dependsOn' => [999]]);
	}

	public function testSelfDependencyIsRejected(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$section = $service->createSection($template->getId(), ['title' => 'A']);

		$this->expectException(ValidationException::class);
		$service->updateSection($section->getId(), ['dependsOn' => [$section->getId()]]);
	}

	public function testDependencyCycleIsRejected(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$a = $service->createSection($template->getId(), ['title' => 'A']);
		$b = $service->createSection($template->getId(), ['title' => 'B', 'dependsOn' => [$a->getId()]]);

		$this->expectException(ValidationException::class);
		$service->updateSection($a->getId(), ['dependsOn' => [$b->getId()]]);
	}

	public function testDeletingSectionStripsDependencies(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$a = $service->createSection($template->getId(), ['title' => 'A']);
		$b = $service->createSection($template->getId(), ['title' => 'B', 'dependsOn' => [$a->getId()]]);

		$service->deleteSection($a->getId());

		self::assertSame([], $service->getSections($template->getId())[0]->getDependsOnIds());
	}

	public function testConditionIsValidatedAgainstStepType(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$start = $service->createSection($template->getId(), ['title' => 'Start']);
		$step = $service->createStep($start->getId(), ['title' => 'Approved?', 'type' => 'CONFIRMATION', 'required' => true]);

		$branch = $service->createSection($template->getId(), [
			'title' => 'Branch',
			'condition' => ['stepId' => $step->getId(), 'operator' => 'is_true'],
		]);
		$condition = $branch->getCondition();
		self::assertNotNull($condition);
		self::assertSame('is_true', $condition['operator']);

		$this->expectException(ValidationException::class);
		$service->createSection($template->getId(), [
			'title' => 'Bad',
			'condition' => ['stepId' => $step->getId(), 'operator' => 'greater_than'],
		]);
	}

	public function testSelectConditionValueMustBeAConfiguredOption(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$start = $service->createSection($template->getId(), ['title' => 'Start']);
		$step = $service->createStep($start->getId(), [
			'title' => 'Environment',
			'type' => 'SELECT',
			'required' => true,
			'config' => ['options' => ['dev', 'prod']],
		]);

		$prod = $service->createSection($template->getId(), [
			'title' => 'Production',
			'condition' => ['stepId' => $step->getId(), 'operator' => 'equals', 'value' => 'prod'],
		]);
		$condition = $prod->getCondition();
		self::assertNotNull($condition);
		self::assertSame('prod', $condition['value']);

		$this->expectException(ValidationException::class);
		$service->createSection($template->getId(), [
			'title' => 'Bad',
			'condition' => ['stepId' => $step->getId(), 'operator' => 'equals', 'value' => 'staging'],
		]);
	}

	public function testDuplicateConditionIsRejected(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$start = $service->createSection($template->getId(), ['title' => 'Start']);
		$step = $service->createStep($start->getId(), ['title' => 'Approved?', 'type' => 'CONFIRMATION', 'required' => true]);
		$condition = ['stepId' => $step->getId(), 'operator' => 'is_true'];

		$service->createSection($template->getId(), ['title' => 'A', 'condition' => $condition]);

		$this->expectException(ValidationException::class);
		$service->createSection($template->getId(), ['title' => 'B', 'condition' => $condition]);
	}

	public function testMultipleAndConditionsAreStoredAsAList(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$start = $service->createSection($template->getId(), ['title' => 'Start']);
		$approved = $service->createStep($start->getId(), ['title' => 'Approved?', 'type' => 'CONFIRMATION', 'required' => true]);
		$environment = $service->createStep($start->getId(), [
			'title' => 'Environment',
			'type' => 'SELECT',
			'required' => true,
			'config' => ['options' => ['dev', 'prod']],
		]);

		$section = $service->createSection($template->getId(), [
			'title' => 'Production',
			'conditions' => [
				['stepId' => $approved->getId(), 'operator' => 'is_true'],
				['stepId' => $environment->getId(), 'operator' => 'equals', 'value' => 'prod'],
			],
		]);

		$conditions = $section->getConditions();
		self::assertCount(2, $conditions);
		self::assertSame('is_true', $conditions[0]['operator']);
		self::assertSame('prod', $conditions[1]['value']);
		self::assertSame($conditions[0], $section->getCondition());
	}

	public function testContradictoryEqualityConditionsInOneGateAreRejected(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$start = $service->createSection($template->getId(), ['title' => 'Start']);
		$environment = $service->createStep($start->getId(), [
			'title' => 'Environment',
			'type' => 'SELECT',
			'required' => true,
			'config' => ['options' => ['dev', 'prod']],
		]);

		$this->expectException(ValidationException::class);
		$service->createSection($template->getId(), [
			'title' => 'Impossible',
			'conditions' => [
				['stepId' => $environment->getId(), 'operator' => 'equals', 'value' => 'dev'],
				['stepId' => $environment->getId(), 'operator' => 'equals', 'value' => 'prod'],
			],
		]);
	}

	public function testContradictoryBooleanConditionsInOneGateAreRejected(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$start = $service->createSection($template->getId(), ['title' => 'Start']);
		$approved = $service->createStep($start->getId(), ['title' => 'Approved?', 'type' => 'CONFIRMATION', 'required' => true]);

		$this->expectException(ValidationException::class);
		$service->createSection($template->getId(), [
			'title' => 'Impossible',
			'conditions' => [
				['stepId' => $approved->getId(), 'operator' => 'is_true'],
				['stepId' => $approved->getId(), 'operator' => 'is_false'],
			],
		]);
	}

	public function testDuplicateConditionInsideOneGateIsRejected(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$start = $service->createSection($template->getId(), ['title' => 'Start']);
		$approved = $service->createStep($start->getId(), ['title' => 'Approved?', 'type' => 'CONFIRMATION', 'required' => true]);
		$condition = ['stepId' => $approved->getId(), 'operator' => 'is_true'];

		$this->expectException(ValidationException::class);
		$service->createSection($template->getId(), [
			'title' => 'Redundant',
			'conditions' => [$condition, $condition],
		]);
	}

	public function testConditionsCanBeReplacedAndClearedAsAList(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$start = $service->createSection($template->getId(), ['title' => 'Start']);
		$approved = $service->createStep($start->getId(), ['title' => 'Approved?', 'type' => 'CONFIRMATION', 'required' => true]);

		$section = $service->createSection($template->getId(), ['title' => 'Branch']);

		$updated = $service->updateSection($section->getId(), [
			'conditions' => [['stepId' => $approved->getId(), 'operator' => 'is_true']],
		]);
		self::assertCount(1, $updated->getConditions());

		$cleared = $service->updateSection($section->getId(), ['conditions' => []]);
		self::assertSame([], $cleared->getConditions());
	}

	public function testConditionOnOwnSectionStepIsRejected(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$section = $service->createSection($template->getId(), ['title' => 'Apenas se duas forem certas']);
		$own = $service->createStep($section->getId(), [
			'title' => 'Tudo funciona, certo?',
			'type' => 'CONFIRMATION',
			'required' => true,
		]);

		try {
			$service->updateSection($section->getId(), [
				'conditions' => [['stepId' => $own->getId(), 'operator' => 'is_true']],
			]);
			self::fail('A same-section condition must be rejected');
		} catch (ValidationException $exception) {
			self::assertSame('condition_references_own_section', $exception->getReason());
		}
	}

	public function testConditionOnOwnSectionStepIsRejectedForTheLegacySingleConditionPayload(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$section = $service->createSection($template->getId(), ['title' => 'ultima']);
		$own = $service->createStep($section->getId(), ['title' => 'teste', 'type' => 'CONFIRMATION', 'required' => true]);

		$this->expectException(ValidationException::class);
		$service->updateSection($section->getId(), [
			'condition' => ['stepId' => $own->getId(), 'operator' => 'is_true'],
		]);
	}

	public function testConditionOnAPreviousSectionStepIsAccepted(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$first = $service->createSection($template->getId(), ['title' => 'Start']);
		$control = $service->createStep($first->getId(), ['title' => 'Approved?', 'type' => 'CONFIRMATION', 'required' => true]);
		$second = $service->createSection($template->getId(), ['title' => 'Ultima']);

		$updated = $service->updateSection($second->getId(), [
			'conditions' => [['stepId' => $control->getId(), 'operator' => 'is_true']],
		]);

		self::assertCount(1, $updated->getConditions());
		self::assertSame($control->getId(), $updated->getConditions()[0]['stepId']);
	}

	public function testSectionCanBeCreatedWithAConditionOnAPreviousSectionStep(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$first = $service->createSection($template->getId(), ['title' => 'Start']);
		$environment = $service->createStep($first->getId(), [
			'title' => 'Environment',
			'type' => 'SELECT',
			'required' => true,
			'config' => ['options' => ['dev', 'prod']],
		]);

		$production = $service->createSection($template->getId(), [
			'title' => 'Production',
			'conditions' => [['stepId' => $environment->getId(), 'operator' => 'equals', 'value' => 'prod']],
		]);

		self::assertCount(1, $production->getConditions());
		self::assertSame('prod', $production->getConditions()[0]['value']);
	}

	public function testConditionReferencingAnUnknownStepIsRejected(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$start = $service->createSection($template->getId(), ['title' => 'Start']);
		$service->createStep($start->getId(), ['title' => 'Approved?', 'type' => 'CONFIRMATION', 'required' => true]);

		try {
			$service->createSection($template->getId(), [
				'title' => 'Branch',
				'conditions' => [['stepId' => 999999, 'operator' => 'is_true']],
			]);
			self::fail('An unknown condition step must be rejected');
		} catch (ValidationException $exception) {
			self::assertSame('condition_step_not_found', $exception->getReason());
		}
	}

	public function testInvalidOperatorForANumericStepIsRejected(): void {
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$start = $service->createSection($template->getId(), ['title' => 'Start']);
		$amount = $service->createStep($start->getId(), ['title' => 'Amount', 'type' => 'NUMBER', 'required' => true]);
		$branch = $service->createSection($template->getId(), ['title' => 'Branch']);

		try {
			$service->updateSection($branch->getId(), [
				'conditions' => [['stepId' => $amount->getId(), 'operator' => 'is_true']],
			]);
			self::fail('An invalid operator for a numeric step must be rejected');
		} catch (ValidationException $exception) {
			self::assertSame('invalid_condition_operator', $exception->getReason());
		}
	}
}
