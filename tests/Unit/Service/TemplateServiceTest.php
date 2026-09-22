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
use OCA\Runbook\Service\PrincipalValidator;
use OCA\Runbook\Service\TemplateCreationPolicyService;
use OCA\Runbook\Service\TemplateExportService;
use OCA\Runbook\Service\TemplateImportService;
use OCA\Runbook\Service\TemplateService;
use OCA\Runbook\Service\TransactionRunner;
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
use PHPUnit\Framework\Attributes\DataProvider;
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

	private function exportServiceFor(string $uid): TemplateExportService {
		return new TemplateExportService($this->serviceFor($uid));
	}

	public function testExportRequiresViewPermission(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addUser('carol');
		$template = $this->serviceFor('alice')->createTemplate(['title' => 'Deploy']);
		$this->seedAcl($template->getId(), PrincipalType::User->value, 'bob', AclRole::Viewer->value);

		$owner = $this->exportServiceFor('alice')->export($template->getId());
		self::assertSame('runbook-template', $owner['format']);
		self::assertSame(1, $owner['schemaVersion']);

		// A user with view access may export.
		$viewer = $this->exportServiceFor('bob')->export($template->getId());
		self::assertSame('Deploy', $viewer['template']['title']);

		// An unrelated user is rejected without any content.
		$this->expectException(ForbiddenException::class);
		$this->exportServiceFor('carol')->export($template->getId());
	}

	public function testExportSupportsEveryLifecycleStatus(): void {
		$this->addUser('alice');
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);

		// Draft.
		self::assertSame('Deploy', $this->exportServiceFor('alice')->export($template->getId())['template']['title']);

		// Published.
		$service->publishTemplate($template->getId());
		self::assertSame('Deploy', $this->exportServiceFor('alice')->export($template->getId())['template']['title']);

		// Archived.
		$service->archiveTemplate($template->getId());
		self::assertSame('Deploy', $this->exportServiceFor('alice')->export($template->getId())['template']['title']);
	}

	public function testExportIncludesFlowReferencesAndTypedConditions(): void {
		$this->addUser('alice');
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Release', 'description' => 'Notes']);
		$config = $service->createSection($template->getId(), ['title' => 'Config', 'notes' => 'cfg']);
		$env = $service->createStep($config->getId(), ['title' => 'Environment', 'type' => 'SELECT', 'required' => true, 'config' => ['options' => ['dev', 'prod']]]);
		$amount = $service->createStep($config->getId(), ['title' => 'Amount', 'type' => 'NUMBER', 'config' => ['unit' => 'kg']]);
		$gate = $service->createSection($template->getId(), ['title' => 'Gate']);
		$final = $service->createSection($template->getId(), [
			'title' => 'Final',
			'dependsOn' => [$config->getId(), $gate->getId()],
			'conditions' => [
				['stepId' => $env->getId(), 'operator' => 'equals', 'value' => 'prod'],
				['stepId' => $amount->getId(), 'operator' => 'greater_than', 'value' => 5],
			],
		]);

		$document = $this->exportServiceFor('alice')->export($template->getId());
		self::assertSame(['Config', 'Gate', 'Final'], array_column($document['sections'], 'title'));
		$refs = array_column($document['sections'], 'ref');
		self::assertSame(['s1', 's2', 's3'], $refs);
		self::assertSame(['s1', 's2'], $document['sections'][2]['dependsOn']);
		self::assertEquals([
			['stepRef' => 't1', 'operator' => 'equals', 'value' => 'prod'],
			['stepRef' => 't2', 'operator' => 'greater_than', 'value' => 5.0],
		], $document['sections'][2]['conditions']);
		self::assertSame('Final', $document['sections'][2]['title']);

		// Zero-step section is present with no steps.
		self::assertSame('s2', $document['sections'][1]['ref']);
		self::assertSame([], $document['sections'][1]['dependsOn']);
		self::assertSame([], $document['sections'][1]['conditions']);
	}

	public function testExportExcludesInternalData(): void {
		$this->addUser('alice');
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$section = $service->createSection($template->getId(), ['title' => 'Start']);
		$service->createStep($section->getId(), ['title' => 'Step', 'type' => 'TEXT']);

		$document = $this->exportServiceFor('alice')->export($template->getId());

		self::assertSame(['format', 'schemaVersion', 'template', 'sections', 'steps'], array_keys($document));
		self::assertSame(['title', 'description'], array_keys($document['template']));
		self::assertSame(['ref', 'title', 'description', 'notes', 'dependsOn', 'conditions'], array_keys($document['sections'][0]));
		self::assertSame(['ref', 'sectionRef', 'title', 'description', 'type', 'required', 'position', 'config', 'defaultAssignee', 'dueOffset'], array_keys($document['steps'][0]));

		$encoded = json_encode($document, JSON_THROW_ON_ERROR);
		foreach (['"id"', 'uuid', 'owner', 'status', 'createdAt', 'updatedAt', 'acl', 'templateId', 'sectionId', 'sourceSectionId'] as $forbidden) {
			self::assertStringNotContainsString('"' . $forbidden . '"', $encoded, 'export must not contain ' . $forbidden);
		}
		// An empty configuration serialises as a JSON object, not an array.
		self::assertSame([], (array)$document['steps'][0]['config']);
		self::assertStringContainsString('"config":{}', $encoded);
	}

	public function testExportOrdersSectionsAndStepsByPosition(): void {
		$this->addUser('alice');
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$first = $service->createSection($template->getId(), ['title' => 'First']);
		$second = $service->createSection($template->getId(), ['title' => 'Second']);
		$first->setPosition(1);
		$second->setPosition(0);
		$stepA = $service->createStep($second->getId(), ['title' => 'A', 'type' => 'TEXT']);
		$stepB = $service->createStep($second->getId(), ['title' => 'B', 'type' => 'TEXT']);
		$stepA->setPosition(1);
		$stepB->setPosition(0);

		$document = $this->exportServiceFor('alice')->export($template->getId());
		self::assertSame(['Second', 'First'], array_column($document['sections'], 'title'));
		self::assertSame(['B', 'A'], array_column($document['steps'], 'title'));
	}

	public function testExportCoversEveryStepTypeAndConfig(): void {
		$this->addUser('alice');
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$section = $service->createSection($template->getId(), ['title' => 'All']);
		foreach (['CHECK', 'CONFIRMATION', 'TEXT', 'NUMBER', 'SELECT', 'DATE', 'USER', 'FILE'] as $type) {
			$config = [];
			if ($type === 'SELECT') {
				$config = ['options' => ['a', 'b'], 'allowOther' => true];
			} elseif ($type === 'NUMBER') {
				$config = ['unit' => 'kg', 'decimals' => 2];
			} elseif ($type === 'TEXT') {
				$config = ['multiline' => true];
			}
			$service->createStep($section->getId(), ['title' => $type, 'type' => $type, 'required' => true, 'config' => $config, 'defaultAssignee' => 'principals/users/alice', 'dueOffset' => '60']);
		}

		$document = $this->exportServiceFor('alice')->export($template->getId());
		$byTitle = [];
		foreach ($document['steps'] as $step) {
			$byTitle[$step['title']] = (array)$step['config'];
		}
		// The complete persisted configuration is exported for every type.
		self::assertSame(['options' => ['a', 'b'], 'allowOther' => true], $byTitle['SELECT']);
		self::assertSame(['unit' => 'kg', 'decimals' => 2], $byTitle['NUMBER']);
		self::assertSame(['multiline' => true], $byTitle['TEXT']);
		self::assertSame([], $byTitle['FILE']);
		self::assertSame('principals/users/alice', $document['steps'][0]['defaultAssignee']);
		self::assertSame('60', $document['steps'][0]['dueOffset']);
		self::assertCount(8, $document['steps']);
	}

	public function testExportRetainsCompleteStepConfigurationAndEmptyObject(): void {
		$this->addUser('alice');
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$section = $service->createSection($template->getId(), ['title' => 'Config']);
		$service->createStep($section->getId(), ['title' => 'Text', 'type' => 'TEXT', 'config' => ['note' => 'keep', 'flag' => true]]);
		$service->createStep($section->getId(), ['title' => 'Select', 'type' => 'SELECT', 'config' => ['options' => ['z', 'a', 'm'], 'extra' => 'kept']]);
		$service->createStep($section->getId(), ['title' => 'Number', 'type' => 'NUMBER', 'config' => ['unit' => 'kg', 'decimals' => 3]]);
		$service->createStep($section->getId(), ['title' => 'NoUnit', 'type' => 'NUMBER', 'config' => []]);
		$service->createStep($section->getId(), ['title' => 'EmptyUnit', 'type' => 'NUMBER', 'config' => ['unit' => '']]);
		$service->createStep($section->getId(), ['title' => 'Bare', 'type' => 'CHECK', 'config' => []]);

		$document = $this->exportServiceFor('alice')->export($template->getId());
		$byTitle = [];
		foreach ($document['steps'] as $step) {
			$byTitle[$step['title']] = (array)$step['config'];
		}
		self::assertSame(['note' => 'keep', 'flag' => true], $byTitle['Text']);
		self::assertSame(['options' => ['z', 'a', 'm'], 'extra' => 'kept'], $byTitle['Select'], 'option order and extra keys are preserved');
		self::assertSame(['unit' => 'kg', 'decimals' => 3], $byTitle['Number']);
		self::assertArrayNotHasKey('unit', $byTitle['NoUnit'], 'an absent unit must not be invented');
		self::assertSame(['unit' => ''], $byTitle['EmptyUnit'], 'an explicitly empty unit is preserved');
		self::assertSame([], $byTitle['Bare']);

		$encoded = json_encode($document, JSON_THROW_ON_ERROR);
		self::assertStringContainsString('"config":{}', $encoded, 'empty configuration serialises as {}');
		self::assertStringNotContainsString('"config":[]', $encoded);
	}

	public function testExportIsDeterministic(): void {
		$this->addUser('alice');
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy', 'description' => 'D']);
		$section = $service->createSection($template->getId(), ['title' => 'Start', 'notes' => 'n']);
		$service->createStep($section->getId(), ['title' => 'Step', 'type' => 'TEXT']);

		$first = json_encode($this->exportServiceFor('alice')->export($template->getId()), JSON_THROW_ON_ERROR);
		$second = json_encode($this->exportServiceFor('alice')->export($template->getId()), JSON_THROW_ON_ERROR);
		self::assertSame($first, $second);
	}

	public function testExportRejectsUnresolvedStoredReferences(): void {
		$this->addUser('alice');
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$section = $service->createSection($template->getId(), ['title' => 'Broken']);
		$section->setDependsOnIds([999999]);

		try {
			$this->exportServiceFor('alice')->export($template->getId());
			self::fail('An unmappable dependency must abort the export');
		} catch (ValidationException $exception) {
			self::assertSame('export_reference_unresolved', $exception->getReason());
		}
	}

	public function testExportDoesNotWriteData(): void {
		$this->addUser('alice');
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$section = $service->createSection($template->getId(), ['title' => 'Start']);
		$service->createStep($section->getId(), ['title' => 'Step', 'type' => 'TEXT']);

		$version = $template->getVersion();
		$updatedAt = $template->getUpdatedAt();
		$this->exportServiceFor('alice')->export($template->getId());

		self::assertSame($version, $template->getVersion(), 'export must not bump the version');
		self::assertSame($updatedAt, $template->getUpdatedAt(), 'export must not touch the template');
		self::assertCount(1, $this->sections);
		self::assertCount(1, $this->steps);
	}

	public function testExportRejectsUnresolvedConditionStepReference(): void {
		$this->addUser('alice');
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Deploy']);
		$section = $service->createSection($template->getId(), ['title' => 'Broken']);
		$section->setConditions([['stepId' => 999999, 'operator' => 'is_true']]);

		try {
			$this->exportServiceFor('alice')->export($template->getId());
			self::fail('An unmappable condition step must abort the export');
		} catch (ValidationException $exception) {
			self::assertSame('export_reference_unresolved', $exception->getReason());
		}
	}

	public function testExportGroupAclViewerMayExport(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addGroup('engineering');
		$this->joinGroup('bob', 'engineering');
		$template = $this->serviceFor('alice')->createTemplate(['title' => 'Deploy']);
		$this->seedAcl($template->getId(), PrincipalType::Group->value, 'engineering', AclRole::Viewer->value);

		$document = $this->exportServiceFor('bob')->export($template->getId());
		self::assertSame('Deploy', $document['template']['title']);
	}

	public function testExportLargeTemplateKeepsRefsOrderAndRules(): void {
		$this->addUser('alice');
		$service = $this->serviceFor('alice');
		$template = $service->createTemplate(['title' => 'Large']);

		$control = $service->createSection($template->getId(), ['title' => 'Control']);
		$controlStep = $service->createStep($control->getId(), ['title' => 'Approved', 'type' => 'CONFIRMATION']);

		$created = [];
		for ($index = 1; $index <= 12; $index++) {
			$created[$index] = $service->createSection($template->getId(), ['title' => 'Section ' . $index]);
			// Two steps each so global step refs exceed t9.
			$service->createStep($created[$index]->getId(), ['title' => 'S' . $index . 'a', 'type' => 'TEXT']);
			$service->createStep($created[$index]->getId(), ['title' => 'S' . $index . 'b', 'type' => 'NUMBER', 'config' => ['unit' => 'kg']]);
		}
		// Multi-dependency + multi-condition final section.
		$service->createSection($template->getId(), [
			'title' => 'Final',
			'dependsOn' => [$created[10]->getId(), $created[11]->getId(), $created[12]->getId()],
			'conditions' => [
				['stepId' => $controlStep->getId(), 'operator' => 'is_true'],
				['stepId' => $service->getSteps($created[12]->getId())[1]->getId(), 'operator' => 'greater_than', 'value' => 2],
			],
		]);

		$document = $this->exportServiceFor('alice')->export($template->getId());

		// 1 control + 12 + 1 final = 14 sections, refs beyond s9.
		self::assertCount(14, $document['sections']);
		$sectionRefs = array_column($document['sections'], 'ref');
		self::assertSame('s1', $sectionRefs[0]);
		self::assertSame('s14', $sectionRefs[13]);
		self::assertSame(['Control', 'Section 1', 'Section 2', 'Section 3', 'Section 4', 'Section 5', 'Section 6', 'Section 7', 'Section 8', 'Section 9', 'Section 10', 'Section 11', 'Section 12', 'Final'], array_column($document['sections'], 'title'));

		// 1 + 24 + step refs: 25 steps, refs beyond t9.
		self::assertCount(25, $document['steps']);
		$stepRefs = array_column($document['steps'], 'ref');
		self::assertSame('t1', $stepRefs[0]);
		self::assertSame('t25', $stepRefs[24]);

		// Dependency refs are mapped and do not leak database ids.
		self::assertSame(['s11', 's12', 's13'], $document['sections'][13]['dependsOn']);
		self::assertSame('t1', $document['sections'][13]['conditions'][0]['stepRef']);
		self::assertSame('t25', $document['sections'][13]['conditions'][1]['stepRef']);
		self::assertSame(2.0, (float)($document['sections'][13]['conditions'][1]['value'] ?? 0));
		self::assertSame('s14', $this->findExportSectionRef($document, 'Final'));
		self::assertSame('t24', $document['steps'][23]['ref']);
		self::assertSame('t25', $document['steps'][24]['ref']);
	}

	/**
	 * @param array<string, mixed> $document Export document.
	 * @param string $title Section title.
	 */
	private function findExportSectionRef(array $document, string $title): ?string {
		foreach ($document['sections'] as $section) {
			if ($section['title'] === $title) {
				return $section['ref'];
			}
		}

		return null;
	}

	/**
	 * Snapshot the in-memory tables so the fake transaction can roll back.
	 *
	 * @return array{0: array<int, Template>, 1: array<int, TemplateSection>, 2: array<int, TemplateStep>, 3: array<int, TemplateAcl>, 4: int}
	 */
	public function captureState(): array {
		return [$this->templates, $this->sections, $this->steps, $this->aclEntries, $this->nextId];
	}

	/**
	 * @param array{0: array<int, Template>, 1: array<int, TemplateSection>, 2: array<int, TemplateStep>, 3: array<int, TemplateAcl>, 4: int} $state
	 */
	public function restoreState(array $state): void {
		[$this->templates, $this->sections, $this->steps, $this->aclEntries, $this->nextId] = $state;
	}

	private function importServiceFor(string $uid): TemplateImportService {
		/** @var TransactionRunner&MockObject $runner */
		$runner = $this->createMock(TransactionRunner::class);
		$runner->method('run')->willReturnCallback(function (callable $operation): mixed {
			$snapshot = $this->captureState();
			try {
				return $operation();
			} catch (\Throwable $exception) {
				$this->restoreState($snapshot);
				throw $exception;
			}
		});

		return new TemplateImportService(
			$this->serviceFor($uid),
			new PrincipalValidator($this->userManager, $this->groupManager),
			$runner,
		);
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function assertImportRejected(string $uid, array $document, string $reason): void {
		try {
			$this->importServiceFor($uid)->import($document);
			self::fail('expected import to be rejected with ' . $reason);
		} catch (ValidationException $exception) {
			self::assertSame($reason, $exception->getReason());
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function minimalDocument(): array {
		return [
			'format' => 'runbook-template',
			'schemaVersion' => 1,
			'template' => ['title' => 'Imported', 'description' => 'From file'],
			'sections' => [
				['ref' => 's1', 'title' => 'First', 'description' => '', 'notes' => '', 'dependsOn' => [], 'conditions' => []],
			],
			'steps' => [
				['ref' => 't1', 'sectionRef' => 's1', 'title' => 'Step', 'description' => '', 'type' => 'TEXT', 'required' => true, 'position' => 0, 'config' => ['note' => 'extra'], 'defaultAssignee' => null, 'dueOffset' => null],
			],
		];
	}

	private function buildRichTemplate(TemplateService $service): Template {
		$template = $service->createTemplate(['title' => 'Release', 'description' => 'Full flow']);
		$config = $service->createSection($template->getId(), ['title' => 'Config', 'description' => 'Setup', 'notes' => 'read me']);
		$all = $service->createSection($template->getId(), ['title' => 'All types']);
		$zero = $service->createSection($template->getId(), ['title' => 'Zero step']);

		$env = $service->createStep($config->getId(), [
			'title' => 'Environment', 'type' => 'SELECT', 'required' => true,
			'config' => ['options' => ['dev', 'prod'], 'allowOther' => true],
			'defaultAssignee' => 'principals/users/alice', 'dueOffset' => '30',
		]);
		$amount = $service->createStep($config->getId(), [
			'title' => 'Amount', 'type' => 'NUMBER', 'config' => ['unit' => 'kg', 'decimals' => 2, 'authored' => 'kept'],
		]);

		$configs = [
			'CHECK' => ['label' => 'checked'],
			'CONFIRMATION' => [],
			'TEXT' => ['note' => 'n', 'maxLength' => 100],
			'DATE' => ['min' => '2024-01-01'],
			'USER' => [],
			'FILE' => ['accept' => 'pdf'],
		];
		foreach ($configs as $type => $configData) {
			$service->createStep($all->getId(), ['title' => $type, 'type' => $type, 'config' => $configData]);
		}
		$service->createStep($all->getId(), [
			'title' => 'Assignee', 'type' => 'USER',
			'defaultAssignee' => 'principals/groups/engineering', 'dueOffset' => '1440',
		]);
		$service->createStep($all->getId(), ['title' => 'Due offset', 'type' => 'NUMBER', 'config' => ['unit' => '']]);

		// Alternative branches depend on the same section with mutually exclusive conditions.
		$service->createSection($template->getId(), [
			'title' => 'Branch prod', 'dependsOn' => [$config->getId()],
			'conditions' => [['stepId' => $env->getId(), 'operator' => 'equals', 'value' => 'prod']],
		]);
		$service->createSection($template->getId(), [
			'title' => 'Branch dev', 'dependsOn' => [$config->getId()],
			'conditions' => [['stepId' => $env->getId(), 'operator' => 'equals', 'value' => 'dev']],
		]);
		$service->createSection($template->getId(), [
			'title' => 'Final',
			'dependsOn' => [$config->getId(), $all->getId(), $zero->getId()],
			'conditions' => [
				['stepId' => $env->getId(), 'operator' => 'equals', 'value' => 'prod'],
				['stepId' => $amount->getId(), 'operator' => 'greater_than', 'value' => 5],
			],
		]);

		return $template;
	}

	public function testExportImportExportRoundTripPreservesContentAndFlow(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addGroup('engineering');

		$template = $this->buildRichTemplate($this->serviceFor('alice'));
		$exported = $this->exportServiceFor('alice')->export($template->getId());
		$exportedJson = json_encode($exported, JSON_THROW_ON_ERROR);

		$imported = $this->importServiceFor('bob')->import($exported);
		self::assertSame('bob', $imported->getOwner());
		self::assertSame('Release', $imported->getTitle());
		self::assertSame(TemplateStatus::Draft->value, $imported->getStatus());
		self::assertSame(1, $imported->getVersion());

		$reexported = $this->exportServiceFor('bob')->export($imported->getId());
		self::assertSame($exportedJson, json_encode($reexported, JSON_THROW_ON_ERROR), 'export → import → export must be lossless');

		// The original template is untouched by the import.
		self::assertSame($exportedJson, json_encode($this->exportServiceFor('alice')->export($template->getId()), JSON_THROW_ON_ERROR));
		self::assertCount(0, $this->aclEntries, 'the imported template copies no ACL rows');
	}

	public function testImportOrdersStepsByPositionWithDocumentTieBreaker(): void {
		$this->addUser('bob');
		$document = $this->minimalDocument();
		$document['sections'][] = ['ref' => 's2', 'title' => 'Second', 'description' => '', 'notes' => '', 'dependsOn' => [], 'conditions' => []];
		$document['steps'] = [
			['ref' => 't1', 'sectionRef' => 's1', 'title' => 'Third', 'description' => '', 'type' => 'TEXT', 'required' => false, 'position' => 5, 'config' => [], 'defaultAssignee' => null, 'dueOffset' => null],
			['ref' => 't2', 'sectionRef' => 's1', 'title' => 'FirstA', 'description' => '', 'type' => 'TEXT', 'required' => false, 'position' => 1, 'config' => [], 'defaultAssignee' => null, 'dueOffset' => null],
			['ref' => 't3', 'sectionRef' => 's1', 'title' => 'FirstB', 'description' => '', 'type' => 'TEXT', 'required' => false, 'position' => 1, 'config' => [], 'defaultAssignee' => null, 'dueOffset' => null],
		];

		$imported = $this->importServiceFor('bob')->import($document);
		$service = $this->serviceFor('bob');
		$sections = $service->getSections($imported->getId());
		self::assertCount(2, $sections, 'a zero-step section is preserved');

		$steps = $service->getSteps($sections[0]->getId());
		self::assertSame(['FirstA', 'FirstB', 'Third'], array_map(static fn (TemplateStep $step): string => $step->getTitle(), $steps));
		self::assertSame([0, 1, 2], array_map(static fn (TemplateStep $step): int => $step->getPosition(), $steps));
	}

	public function testImportRejectsUnsupportedSchemaVersion(): void {
		$this->addUser('bob');
		$document = $this->minimalDocument();
		$document['schemaVersion'] = 2;

		$this->assertImportRejected('bob', $document, 'unsupported_import_schema_version');
	}

	public function testImportRejectsMalformedDocuments(): void {
		$this->addUser('bob');

		$this->assertImportRejected('bob', [], 'invalid_import_format');

		$missingVersion = ['format' => 'runbook-template'];
		$this->assertImportRejected('bob', $missingVersion, 'invalid_import_document');

		$wrongFormat = $this->minimalDocument();
		$wrongFormat['format'] = 'something-else';
		$this->assertImportRejected('bob', $wrongFormat, 'invalid_import_format');

		$missingSections = $this->minimalDocument();
		unset($missingSections['sections']);
		$this->assertImportRejected('bob', $missingSections, 'invalid_import_document');

		$badStep = $this->minimalDocument();
		$badStep['steps'][0]['required'] = 'yes';
		$this->assertImportRejected('bob', $badStep, 'invalid_import_document');
	}

	public function testImportRejectsDuplicateAndMissingReferences(): void {
		$this->addUser('bob');

		$duplicateSection = $this->minimalDocument();
		$duplicateSection['sections'][] = ['ref' => 's1', 'title' => 'Again', 'description' => '', 'notes' => '', 'dependsOn' => [], 'conditions' => []];
		$this->assertImportRejected('bob', $duplicateSection, 'duplicate_import_reference');

		$duplicateStep = $this->minimalDocument();
		$duplicateStep['steps'][] = ['ref' => 't1', 'sectionRef' => 's1', 'title' => 'Again', 'description' => '', 'type' => 'TEXT', 'required' => false, 'position' => 1, 'config' => [], 'defaultAssignee' => null, 'dueOffset' => null];
		$this->assertImportRejected('bob', $duplicateStep, 'duplicate_import_reference');

		$missingSectionRef = $this->minimalDocument();
		$missingSectionRef['steps'][0]['sectionRef'] = 's9';
		$this->assertImportRejected('bob', $missingSectionRef, 'invalid_import_reference');

		$missingDependency = $this->minimalDocument();
		$missingDependency['sections'][0]['dependsOn'] = ['s9'];
		$this->assertImportRejected('bob', $missingDependency, 'invalid_import_reference');

		$missingCondition = $this->minimalDocument();
		$missingCondition['sections'][0]['conditions'] = [['stepRef' => 't9', 'operator' => 'equals', 'value' => 'x']];
		$this->assertImportRejected('bob', $missingCondition, 'invalid_import_reference');
	}

	public function testImportRejectsInvalidConditionOperatorAndValue(): void {
		$this->addUser('bob');

		$invalidOperator = $this->minimalDocument();
		$invalidOperator['sections'][] = ['ref' => 's2', 'title' => 'Gate', 'description' => '', 'notes' => '', 'dependsOn' => [], 'conditions' => [['stepRef' => 't1', 'operator' => 'greater_than', 'value' => 1]]];
		$this->assertImportRejected('bob', $invalidOperator, 'invalid_condition_operator');

		$invalidValue = $this->minimalDocument();
		$invalidValue['steps'][0]['type'] = 'NUMBER';
		$invalidValue['steps'][0]['config'] = ['unit' => 'kg'];
		$invalidValue['sections'][] = ['ref' => 's2', 'title' => 'Gate', 'description' => '', 'notes' => '', 'dependsOn' => [], 'conditions' => [['stepRef' => 't1', 'operator' => 'equals', 'value' => 'not-a-number']]];
		$this->assertImportRejected('bob', $invalidValue, 'invalid_condition_value');
	}

	public function testImportRejectsDependencyCycles(): void {
		$this->addUser('bob');
		$document = $this->minimalDocument();
		$document['sections'][] = ['ref' => 's2', 'title' => 'Second', 'description' => '', 'notes' => '', 'dependsOn' => ['s1'], 'conditions' => []];
		$document['sections'][0]['dependsOn'] = ['s2'];

		$this->assertImportRejected('bob', $document, 'section_dependency_cycle');
	}

	public function testImportRejectsUnknownDefaultAssignee(): void {
		$this->addUser('bob');
		$document = $this->minimalDocument();
		$document['steps'][0]['defaultAssignee'] = 'principals/users/ghost';

		$this->assertImportRejected('bob', $document, 'invalid_import_assignee');

		$malformed = $this->minimalDocument();
		$malformed['steps'][0]['defaultAssignee'] = 'alice';
		$this->assertImportRejected('bob', $malformed, 'invalid_import_assignee');
	}

	public function testImportEnforcesCreationPolicy(): void {
		$this->addUser('bob');
		$this->configValues[AdminSettings::KEY_TEMPLATE_CREATION_POLICY] = 'admins';

		try {
			$this->importServiceFor('bob')->import($this->minimalDocument());
			self::fail('creation policy must reject the import');
		} catch (ForbiddenException $exception) {
			self::assertSame('template_creation_forbidden', $exception->getReason());
		}

		self::assertCount(0, $this->templates);
	}

	public function testImportRejectsOversizedCollections(): void {
		$this->addUser('bob');
		$document = $this->minimalDocument();
		$document['sections'] = [];
		for ($index = 0; $index <= TemplateImportService::MAX_SECTIONS; $index++) {
			$document['sections'][] = ['ref' => 's' . $index, 'title' => 'S' . $index, 'description' => '', 'notes' => '', 'dependsOn' => [], 'conditions' => []];
		}

		$this->assertImportRejected('bob', $document, 'import_too_many_sections');
	}

	public function testImportRollsBackWhenALateStepFails(): void {
		$this->addUser('bob');
		$document = $this->minimalDocument();
		$document['steps'][] = ['ref' => 't2', 'sectionRef' => 's1', 'title' => 'Broken', 'description' => '', 'type' => 'NOT_A_TYPE', 'required' => false, 'position' => 1, 'config' => [], 'defaultAssignee' => null, 'dueOffset' => null];

		try {
			$this->importServiceFor('bob')->import($document);
			self::fail('an unknown step type must abort the import');
		} catch (ValidationException $exception) {
			self::assertSame('invalid_step_type', $exception->getReason());
		}

		self::assertCount(0, $this->templates, 'no template row is left behind');
		self::assertCount(0, $this->sections, 'no section row is left behind');
		self::assertCount(0, $this->steps, 'no step row is left behind');
		self::assertSame(1, $this->nextId, 'the rollback also restores identifier generation');
	}

	/**
	 * Build a structurally valid document whose canonical size is exactly
	 * `$targetBytes`, using only field values that respect the authoring limits
	 * (titles, descriptions, notes and configuration).
	 *
	 * @return array<string, mixed>
	 */
	private function boundaryDocument(int $targetBytes): array {
		$sections = [];
		for ($index = 0; $index < 98; $index++) {
			$sections[] = [
				'ref' => 's' . $index,
				'title' => 'Section ' . $index,
				'description' => str_repeat('a', 10000),
				'notes' => str_repeat('b', 10000),
				'dependsOn' => [],
				'conditions' => [],
			];
		}

		$document = [
			'format' => 'runbook-template',
			'schemaVersion' => 1,
			'template' => ['title' => 'Boundary', 'description' => ''],
			'sections' => $sections,
			'steps' => [[
				'ref' => 't1',
				'sectionRef' => 's0',
				'title' => 'Pad',
				'description' => '',
				'type' => 'TEXT',
				'required' => false,
				'position' => 0,
				'config' => ['pad' => ''],
				'defaultAssignee' => null,
				'dueOffset' => null,
			]],
		];

		$remaining = $targetBytes - TemplateImportService::documentSizeBytes($document);
		self::assertGreaterThanOrEqual(0, $remaining, 'the base document must not already exceed the target');
		self::assertLessThanOrEqual(59000, $remaining, 'the remaining bytes must fit in a valid configuration value');

		$document['steps'][0]['config']['pad'] = str_repeat('a', $remaining);
		self::assertSame($targetBytes, TemplateImportService::documentSizeBytes($document), 'the tuned document is exactly at the target');

		return $document;
	}

	public function testImportAcceptsAStructurallyValidDocumentAtTheCanonicalByteBoundary(): void {
		$this->addUser('bob');
		$document = $this->boundaryDocument(TemplateImportService::MAX_DOCUMENT_BYTES);

		// Exercise the JSON decoding path rather than passing an arbitrary size.
		$decoded = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);

		$imported = $this->importServiceFor('bob')->import($decoded);

		self::assertSame('Boundary', $imported->getTitle());
		self::assertCount(98, $this->serviceFor('bob')->getSections($imported->getId()));
		self::assertSame(10000, mb_strlen($this->serviceFor('bob')->getSections($imported->getId())[0]->getDescription()));
	}

	public function testImportRejectsOneByteOverTheCanonicalBoundaryWithoutCreatingAnything(): void {
		$this->addUser('bob');
		$document = $this->boundaryDocument(TemplateImportService::MAX_DOCUMENT_BYTES + 1);

		$this->assertImportRejected('bob', $document, 'import_document_too_large');

		self::assertCount(0, $this->templates, 'an oversized import must not create a template');
		self::assertCount(0, $this->sections);
		self::assertCount(0, $this->steps);
	}

	public function testImportCanonicalSizeIgnoresInsignificantWhitespace(): void {
		$this->addUser('bob');
		$document = $this->boundaryDocument(TemplateImportService::MAX_DOCUMENT_BYTES - 1000);

		// Pretty-printing makes the raw JSON larger than the limit, but the
		// canonical encoding (the single documented rule) is within it, so the
		// import is accepted. The raw request size is bounded by PHP/Nextcloud.
		$pretty = (string)json_encode($document, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
		self::assertGreaterThan(TemplateImportService::MAX_DOCUMENT_BYTES, strlen($pretty));

		$decoded = json_decode($pretty, true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);

		$imported = $this->importServiceFor('bob')->import($decoded);
		self::assertSame('Boundary', $imported->getTitle());
	}

	public function testImportSizeRuleUsesUnescapedUtf8NotDefaultJsonEncoding(): void {
		// A small document demonstrates the rule: default json_encode escapes
		// "é" to \u00e9 and overstates the size, while documentSizeBytes counts
		// the real UTF-8 bytes.
		$document = $this->minimalDocument();
		$document['template']['title'] = 'Configuração é ação';

		self::assertLessThan(
			strlen((string)json_encode($document, JSON_THROW_ON_ERROR)),
			TemplateImportService::documentSizeBytes($document),
			'default json_encode must not be used as the size proxy',
		);

		$unescaped = (string)json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		self::assertStringContainsString('é', $unescaped, 'unicode stays as UTF-8 when measuring');
		self::assertStringNotContainsString('\u00e9', $unescaped, 'unicode is not escaped when measuring');

		TemplateImportService::assertDocumentSize($document);
	}

	public function testImportAcceptsJsonDecodedDocumentWithAccentedText(): void {
		$this->addUser('bob');
		$document = $this->minimalDocument();
		$document['template']['title'] = 'Configuração';
		$document['template']['description'] = 'Ação';
		// An empty configuration object decodes to an empty PHP array.
		$document['steps'][0]['config'] = [];

		// Simulate the real HTTP path: Nextcloud JSON-decodes the raw body.
		$raw = json_encode($document, JSON_THROW_ON_ERROR);
		$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);

		$imported = $this->importServiceFor('bob')->import($decoded);
		self::assertSame('Configuração', $imported->getTitle());
		self::assertSame('Ação', $imported->getDescription());
	}

	public function testImportCanonicalSizeUsesPhpNumberAndSeparatorForms(): void {
		// PHP json_encode and JavaScript JSON.stringify serialise numbers
		// differently: PHP emits "1.0e+21" and "1.0e-6", JS emits "1e+21" and
		// "0.000001"; PHP escapes U+2028, JS keeps it literal. PHP is
		// authoritative, so its forms define the measured size.
		self::assertSame('1.0e+21', json_encode(1e21, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		self::assertSame('1.0e-6', json_encode(1e-6, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		self::assertSame('"a\u2028b"', json_encode("a\u{2028}b", JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'PHP escapes U+2028');

		$this->addUser('bob');
		$document = $this->minimalDocument();
		$document['template']['title'] = 'Numbers';
		$document['steps'][0]['config'] = ['exp' => 1e21, 'small' => 1e-6, 'sep' => "a\u{2028}b"];

		$decoded = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);

		$imported = $this->importServiceFor('bob')->import($decoded);
		$step = $this->serviceFor('bob')->getSteps($this->serviceFor('bob')->getSections($imported->getId())[0]->getId())[0];
		self::assertSame(1.0e21, $step->getConfigArray()['exp']);
		self::assertSame(1.0e-6, $step->getConfigArray()['small']);
		self::assertSame("a\u{2028}b", $step->getConfigArray()['sep']);
	}

	public function testImportAcceptsDocumentWhereAJavaScriptEstimateWouldExceedTheLimit(): void {
		// 26 steps x 8569 values of 1e-6. PHP emits "1.0e-6," (7 bytes) so the
		// canonical size stays under 2,000,000, while JS JSON.stringify emits
		// "0.000001," (9 bytes) per value, which would push a naive JS byte
		// estimate over the limit. The server's decision is authoritative.
		$valueCount = 8569;
		$stepCount = 26;
		self::assertSame(6, strlen((string)json_encode(1e-6, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
		self::assertSame(8, strlen('0.000001'), 'the JS form of 1e-6 is longer');

		$sections = [['ref' => 's0', 'title' => 'S', 'description' => '', 'notes' => '', 'dependsOn' => [], 'conditions' => []]];
		$steps = [];
		for ($index = 0; $index < $stepCount; $index++) {
			$steps[] = [
				'ref' => 't' . $index,
				'sectionRef' => 's0',
				'title' => 'Numbers',
				'description' => '',
				'type' => 'NUMBER',
				'required' => false,
				'position' => $index,
				'config' => ['v' => array_fill(0, $valueCount, 1e-6)],
				'defaultAssignee' => null,
				'dueOffset' => null,
			];
		}
		$document = [
			'format' => 'runbook-template',
			'schemaVersion' => 1,
			'template' => ['title' => 'Numbers', 'description' => ''],
			'sections' => $sections,
			'steps' => $steps,
		];

		$phpCanonical = TemplateImportService::documentSizeBytes($document);
		self::assertLessThanOrEqual(TemplateImportService::MAX_DOCUMENT_BYTES, $phpCanonical, 'PHP accepts this canonical size');

		$jsExtra = $stepCount * $valueCount * (strlen('0.000001') - 6);
		self::assertGreaterThan(
			TemplateImportService::MAX_DOCUMENT_BYTES,
			$phpCanonical + $jsExtra,
			'a JavaScript byte estimate for the same document would exceed the limit',
		);

		$this->addUser('bob');
		$imported = $this->importServiceFor('bob')->import($document);
		$section = $this->serviceFor('bob')->getSections($imported->getId())[0];
		self::assertCount($valueCount, $this->serviceFor('bob')->getSteps($section->getId())[0]->getConfigArray()['v']);
	}

	/**
	 * A minimal document whose first section holds a control step of `$type`
	 * and whose second section gates on it.
	 *
	 * @param array<string, mixed> $controlConfig
	 * @param list<array<string, mixed>> $conditions
	 * @return array<string, mixed>
	 */
	private function documentWithGate(string $type, array $controlConfig, array $conditions): array {
		$document = $this->minimalDocument();
		$document['steps'][0]['type'] = $type;
		$document['steps'][0]['config'] = $controlConfig;
		$document['sections'][] = [
			'ref' => 's2', 'title' => 'Gate', 'description' => '', 'notes' => '',
			'dependsOn' => [], 'conditions' => $conditions,
		];

		return $document;
	}

	private function assertNoImportRowsWereWritten(): void {
		self::assertCount(0, $this->templates, 'no template row');
		self::assertCount(0, $this->sections, 'no section row');
		self::assertCount(0, $this->steps, 'no step row');
		self::assertCount(0, $this->aclEntries, 'no ACL row');
		self::assertSame(1, $this->nextId, 'identifier generation untouched');
	}

	public function testImportRejectsNonFiniteNumbersWithoutAnUncaughtJsonError(): void {
		$this->addUser('bob');

		foreach ([INF, -INF, NAN] as $nonFinite) {
			$document = $this->minimalDocument();
			$document['steps'][0]['config'] = ['v' => $nonFinite];
			$this->assertImportRejected('bob', $document, 'invalid_import_document');
			$this->assertNoImportRowsWereWritten();
		}

		// A JSON body can carry this as `1e400`, which json_decode turns into INF.
		$decoded = json_decode('1e400');
		self::assertIsFloat($decoded);
		self::assertTrue(is_infinite($decoded));
		$document = $this->minimalDocument();
		$document['steps'][0]['type'] = 'NUMBER';
		$document['steps'][0]['config'] = ['v' => $decoded];
		$this->assertImportRejected('bob', $document, 'invalid_import_document');
		$this->assertNoImportRowsWereWritten();

		// A non-finite typed condition value is rejected the same way.
		$gate = $this->documentWithGate('NUMBER', ['unit' => 'kg'], [
			['stepRef' => 't1', 'operator' => 'equals', 'value' => INF],
		]);
		$this->assertImportRejected('bob', $gate, 'invalid_import_document');
		$this->assertNoImportRowsWereWritten();
	}

	public function testImportRejectsMalformedUtf8WithoutAnUncaughtJsonError(): void {
		$this->addUser('bob');

		$inConfig = $this->minimalDocument();
		$inConfig['steps'][0]['config'] = ['v' => "\xB1\x31"];
		$this->assertImportRejected('bob', $inConfig, 'invalid_import_document');
		$this->assertNoImportRowsWereWritten();

		$inTitle = $this->minimalDocument();
		$inTitle['template']['title'] = "bad \xC3\x28";
		$this->assertImportRejected('bob', $inTitle, 'invalid_import_document');
		$this->assertNoImportRowsWereWritten();
	}

	public function testImportRejectsExcessivelyNestedDocuments(): void {
		$this->addUser('bob');
		$deep = [];
		for ($index = 0; $index < 600; $index++) {
			$deep = ['a' => $deep];
		}
		$document = $this->minimalDocument();
		$document['steps'][0]['config'] = $deep;

		$this->assertImportRejected('bob', $document, 'invalid_import_document');
		$this->assertNoImportRowsWereWritten();
	}

	public function testImportIgnoresUnknownFieldsAndDoesNotInjectServerState(): void {
		$this->addUser('bob');
		$document = $this->minimalDocument();
		$document['id'] = 999;
		$document['uuid'] = 'injected-envelope';
		$document['owner'] = 'mallory';
		$document['status'] = 'PUBLISHED';
		$document['acl'] = [['role' => 'OWNER']];
		$document['permissions'] = ['canView' => true];
		$document['runs'] = [['id' => 1]];
		$document['template']['id'] = 7;
		$document['template']['owner'] = 'mallory';
		$document['template']['status'] = 'PUBLISHED';
		$document['template']['uuid'] = 'injected-template';
		$document['sections'][0]['id'] = 42;
		$document['sections'][0]['templateId'] = 7;
		$document['sections'][0]['status'] = 'ARCHIVED';
		$document['steps'][0]['id'] = 43;
		$document['steps'][0]['sectionId'] = 42;
		$document['steps'][0]['uuid'] = 'injected-step';

		$imported = $this->importServiceFor('bob')->import($document);

		self::assertSame('bob', $imported->getOwner());
		self::assertSame(TemplateStatus::Draft->value, $imported->getStatus());
		self::assertSame(1, $imported->getVersion());
		self::assertNotSame('injected-envelope', $imported->getUuid());
		self::assertNotSame('injected-template', $imported->getUuid());
		self::assertCount(0, $this->aclEntries, 'imported ACLs must not be applied');

		$export = $this->exportServiceFor('bob')->export($imported->getId());
		self::assertSame(['format', 'schemaVersion', 'template', 'sections', 'steps'], array_keys($export));
		$encoded = json_encode($export, JSON_THROW_ON_ERROR);
		foreach (['id', 'uuid', 'owner', 'status', 'acl', 'permissions', 'runs', 'templateId', 'sectionId'] as $forbidden) {
			self::assertStringNotContainsString('"' . $forbidden . '"', $encoded, 'export must not contain ' . $forbidden);
		}
	}

	public function testImportRejectsSelfDependencyAndRollsBack(): void {
		$this->addUser('bob');
		$document = $this->minimalDocument();
		$document['sections'][0]['dependsOn'] = ['s1'];

		$this->assertImportRejected('bob', $document, 'section_self_dependency');
		$this->assertNoImportRowsWereWritten();
	}

	public function testImportRejectsCyclesAndRollsBack(): void {
		$this->addUser('bob');
		$document = $this->minimalDocument();
		$document['sections'][] = ['ref' => 's2', 'title' => 'Second', 'description' => '', 'notes' => '', 'dependsOn' => ['s1'], 'conditions' => []];
		$document['sections'][0]['dependsOn'] = ['s2'];

		$this->assertImportRejected('bob', $document, 'section_dependency_cycle');
		$this->assertNoImportRowsWereWritten();
	}

	public function testImportRejectsDuplicateAndConflictingConditionsAndRollsBack(): void {
		$this->addUser('bob');

		$duplicate = $this->documentWithGate('CHECK', [], [
			['stepRef' => 't1', 'operator' => 'is_true'],
			['stepRef' => 't1', 'operator' => 'is_true'],
		]);
		$this->assertImportRejected('bob', $duplicate, 'duplicate_section_condition');
		$this->assertNoImportRowsWereWritten();

		$conflicting = $this->documentWithGate('CHECK', [], [
			['stepRef' => 't1', 'operator' => 'is_true'],
			['stepRef' => 't1', 'operator' => 'is_false'],
		]);
		$this->assertImportRejected('bob', $conflicting, 'conflicting_section_conditions');
		$this->assertNoImportRowsWereWritten();
	}

	public function testImportRejectsAmbiguousGateAcrossSections(): void {
		$this->addUser('bob');
		$document = $this->documentWithGate('CHECK', [], [
			['stepRef' => 't1', 'operator' => 'is_true'],
		]);
		$document['sections'][] = [
			'ref' => 's3', 'title' => 'Gate 2', 'description' => '', 'notes' => '',
			'dependsOn' => [], 'conditions' => [['stepRef' => 't1', 'operator' => 'is_true']],
		];

		$this->assertImportRejected('bob', $document, 'duplicate_section_condition');
		$this->assertNoImportRowsWereWritten();
	}

	/**
	 * @return list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3: string}>
	 */
	public static function conditionMatrix(): array {
		return [
			// Invalid operator for the controlling step type.
			['CHECK', [], ['stepRef' => 't1', 'operator' => 'equals', 'value' => 'x'], 'invalid_condition_operator'],
			['CONFIRMATION', [], ['stepRef' => 't1', 'operator' => 'equals', 'value' => 'x'], 'invalid_condition_operator'],
			['NUMBER', ['unit' => 'kg'], ['stepRef' => 't1', 'operator' => 'is_true'], 'invalid_condition_operator'],
			['SELECT', ['options' => ['a', 'b']], ['stepRef' => 't1', 'operator' => 'is_true'], 'invalid_condition_operator'],
			['TEXT', [], ['stepRef' => 't1', 'operator' => 'is_true'], 'invalid_condition_operator'],
			['DATE', [], ['stepRef' => 't1', 'operator' => 'is_true'], 'invalid_condition_operator'],
			['USER', [], ['stepRef' => 't1', 'operator' => 'is_true'], 'invalid_condition_operator'],
			// FILE steps cannot be controlled at all.
			['FILE', [], ['stepRef' => 't1', 'operator' => 'equals', 'value' => 'x'], 'invalid_condition'],
			// Wrong value type for the controlling step type.
			['NUMBER', ['unit' => 'kg'], ['stepRef' => 't1', 'operator' => 'equals', 'value' => 'abc'], 'invalid_condition_value'],
			['SELECT', ['options' => ['a', 'b']], ['stepRef' => 't1', 'operator' => 'equals', 'value' => 'zzz'], 'invalid_condition_value'],
			['TEXT', [], ['stepRef' => 't1', 'operator' => 'equals', 'value' => ''], 'invalid_condition_value'],
			['DATE', [], ['stepRef' => 't1', 'operator' => 'equals', 'value' => ''], 'invalid_condition_value'],
			['USER', [], ['stepRef' => 't1', 'operator' => 'equals', 'value' => ''], 'invalid_condition_value'],
		];
	}

	/**
	 * @param array<string, mixed> $controlConfig
	 * @param array<string, mixed> $condition
	 */
	#[DataProvider('conditionMatrix')]
	public function testImportConditionMatrixRejectsInvalidOperatorsAndValues(string $type, array $controlConfig, array $condition, string $reason): void {
		$this->addUser('bob');
		$document = $this->documentWithGate($type, $controlConfig, [$condition]);

		$this->assertImportRejected('bob', $document, $reason);
		$this->assertNoImportRowsWereWritten();
	}

	public function testImportAcceptsTypedConditionValueForBooleanStep(): void {
		$this->addUser('bob');
		// CHECK/CONFIRMATION conditions ignore any extra value field.
		$document = $this->documentWithGate('CHECK', [], [
			['stepRef' => 't1', 'operator' => 'is_true', 'value' => 'ignored'],
		]);

		$imported = $this->importServiceFor('bob')->import($document);
		$sections = $this->serviceFor('bob')->getSections($imported->getId());
		self::assertSame(
			[['stepId' => $this->serviceFor('bob')->getSteps($sections[0]->getId())[0]->getId(), 'operator' => 'is_true']],
			$sections[1]->getConditions(),
			'the boolean gate is stored without an invented value',
		);
	}

	public function testImportRejectsTooManyStepsBeforeWriting(): void {
		// The step-count limit is enforced by the cheap pre-encode guard. A full
		// 5,000-step acceptance case is intentionally not run: the in-memory
		// authoring harness is quadratic per step and it would slow the suite by
		// minutes without proving more than this boundary check.
		$this->addUser('bob');
		$document = $this->minimalDocument();
		$steps = [];
		for ($index = 0; $index <= TemplateImportService::MAX_STEPS; $index++) {
			$steps[] = ['ref' => 't' . $index, 'sectionRef' => 's1', 'title' => 'S' . $index, 'description' => '', 'type' => 'TEXT', 'required' => false, 'position' => $index, 'config' => [], 'defaultAssignee' => null, 'dueOffset' => null];
		}
		$document['steps'] = $steps;

		$this->assertImportRejected('bob', $document, 'import_too_many_steps');
		$this->assertNoImportRowsWereWritten();
	}

	public function testImportRejectsTooManyDependenciesBeforeWriting(): void {
		$this->addUser('bob');
		$document = $this->minimalDocument();
		$document['sections'] = [];
		for ($index = 0; $index < 500; $index++) {
			$dependsOn = $index >= 20 ? ['s0', 's1', 's2', 's3', 's4', 's5', 's6', 's7', 's8', 's9', 's10'] : [];
			$document['sections'][] = ['ref' => 's' . $index, 'title' => 'S' . $index, 'description' => '', 'notes' => '', 'dependsOn' => $dependsOn, 'conditions' => []];
		}
		$document['steps'][0]['sectionRef'] = 's0';

		$this->assertImportRejected('bob', $document, 'import_too_many_dependencies');
		$this->assertNoImportRowsWereWritten();
	}

	public function testImportRejectsTooManyConditionsBeforeWriting(): void {
		$this->addUser('bob');
		$document = $this->minimalDocument();
		$document['steps'][0]['type'] = 'CHECK';
		$document['steps'][0]['config'] = [];
		$document['sections'] = [
			['ref' => 's0', 'title' => 'Control', 'description' => '', 'notes' => '', 'dependsOn' => [], 'conditions' => []],
		];
		$document['steps'][0]['sectionRef'] = 's0';
		for ($index = 1; $index < 500; $index++) {
			$document['sections'][] = [
				'ref' => 's' . $index, 'title' => 'S' . $index, 'description' => '', 'notes' => '',
				'dependsOn' => [],
				'conditions' => array_fill(0, 11, ['stepRef' => 't1', 'operator' => 'is_true']),
			];
		}

		$this->assertImportRejected('bob', $document, 'import_too_many_conditions');
		$this->assertNoImportRowsWereWritten();
	}

	public function testImportAcceptsMaximumSectionCount(): void {
		$this->addUser('bob');
		$document = $this->minimalDocument();
		$document['sections'] = [];
		for ($index = 0; $index < TemplateImportService::MAX_SECTIONS; $index++) {
			$document['sections'][] = ['ref' => 's' . $index, 'title' => 'S' . $index, 'description' => '', 'notes' => '', 'dependsOn' => [], 'conditions' => []];
		}
		$document['steps'] = [];

		$imported = $this->importServiceFor('bob')->import($document);
		self::assertCount(TemplateImportService::MAX_SECTIONS, $this->serviceFor('bob')->getSections($imported->getId()));
	}

	public function testImportRollsBackWhenSectionCreationFails(): void {
		$this->addUser('bob');
		$document = $this->minimalDocument();
		// The template row is inserted first; the oversized section text is only
		// caught by the authoring service, so this proves the rollback.
		$document['sections'][0]['description'] = str_repeat('a', 10001);

		$this->assertImportRejected('bob', $document, 'description_too_long');
		$this->assertNoImportRowsWereWritten();
	}

	public function testImportRollsBackWhenStepCreationFails(): void {
		$this->addUser('bob');
		$document = $this->minimalDocument();
		$document['steps'][0]['description'] = str_repeat('a', 10001);

		$this->assertImportRejected('bob', $document, 'description_too_long');
		$this->assertNoImportRowsWereWritten();
	}

	public function testImportDoesNotModifyAnExistingTemplate(): void {
		$this->addUser('alice');
		$existing = $this->serviceFor('alice')->createTemplate(['title' => 'Existing']);
		$section = $this->serviceFor('alice')->createSection($existing->getId(), ['title' => 'Keep']);
		$this->serviceFor('alice')->createStep($section->getId(), ['title' => 'Keep step', 'type' => 'TEXT']);
		$before = $this->exportServiceFor('alice')->export($existing->getId());
		$beforeUpdatedAt = $this->serviceFor('alice')->getTemplate($existing->getId())->getUpdatedAt();

		$this->addUser('bob');
		$imported = $this->importServiceFor('bob')->import($this->minimalDocument());

		self::assertNotSame($existing->getId(), $imported->getId());
		self::assertSame(
			json_encode($before, JSON_THROW_ON_ERROR),
			json_encode($this->exportServiceFor('alice')->export($existing->getId()), JSON_THROW_ON_ERROR),
			'the existing template is untouched',
		);
		self::assertSame($beforeUpdatedAt, $this->serviceFor('alice')->getTemplate($existing->getId())->getUpdatedAt());
	}

	public function testExportDeniesAdministratorWithoutViewAccess(): void {
		$this->addUser('alice');
		$this->addUser('admin');
		$this->adminUsers[] = 'admin';
		$template = $this->serviceFor('alice')->createTemplate(['title' => 'Deploy']);

		$this->expectException(ForbiddenException::class);
		$this->exportServiceFor('admin')->export($template->getId());
	}

	public function testExportNeverLeaksRunOrCollaborationData(): void {
		$this->addUser('alice');
		$template = $this->serviceFor('alice')->createTemplate(['title' => 'Deploy']);
		$section = $this->serviceFor('alice')->createSection($template->getId(), ['title' => 'Start']);
		$this->serviceFor('alice')->createStep($section->getId(), ['title' => 'Step', 'type' => 'TEXT']);

		$encoded = json_encode($this->exportServiceFor('alice')->export($template->getId()), JSON_THROW_ON_ERROR);
		$forbidden = ['owner', 'status', 'uuid', 'acl', 'runId', 'responses', 'comments', 'evidence', 'notifications', 'storagePath', 'permissions', 'createdAt', 'updatedAt', 'templateId', 'sectionId', 'password', 'token'];
		foreach ($forbidden as $token) {
			self::assertStringNotContainsString('"' . $token . '"', $encoded, 'export must not leak ' . $token);
		}
	}

	public function testImportRejectsMalformedConfigurationShapes(): void {
		$this->addUser('bob');
		foreach (['nope', 123, true] as $badConfig) {
			$document = $this->minimalDocument();
			$document['steps'][0]['config'] = $badConfig;
			$this->assertImportRejected('bob', $document, 'invalid_import_document');
			$this->assertNoImportRowsWereWritten();
		}
	}

	public function testImportPreservesMaliciousAuthoringText(): void {
		$this->addUser('bob');
		$script = '<script>alert(1)</script>';
		$img = '"><img src=x onerror=alert(1)>';
		$entities = '&lt;b&gt;&amp;&#39;';
		$path = '../../etc/passwd';
		$separator = "a\u{2028}b";

		$document = $this->minimalDocument();
		$document['template']['title'] = $script;
		$document['template']['description'] = $img;
		$document['sections'][0]['notes'] = $entities;
		$document['steps'][0]['config'] = ['path' => $path, 'sep' => $separator];

		$imported = $this->importServiceFor('bob')->import($document);
		$export = $this->exportServiceFor('bob')->export($imported->getId());

		self::assertSame($script, $export['template']['title']);
		self::assertSame($img, $export['template']['description']);
		self::assertSame($entities, $export['sections'][0]['notes']);
		$config = (array)$export['steps'][0]['config'];
		self::assertSame($path, $config['path']);
		self::assertSame($separator, $config['sep']);
	}

	public function testExportAllowsEveryAssignableAclRole(): void {
		$this->addUser('alice');
		$this->addUser('viewer');
		$this->addUser('executor');
		$this->addUser('editor');
		$template = $this->serviceFor('alice')->createTemplate(['title' => 'Deploy']);
		$this->seedAcl($template->getId(), PrincipalType::User->value, 'viewer', AclRole::Viewer->value);
		$this->seedAcl($template->getId(), PrincipalType::User->value, 'executor', AclRole::Executor->value);
		$this->seedAcl($template->getId(), PrincipalType::User->value, 'editor', AclRole::Editor->value);

		foreach (['viewer', 'executor', 'editor'] as $uid) {
			self::assertSame('Deploy', $this->exportServiceFor($uid)->export($template->getId())['template']['title']);
		}
	}

	/**
	 * Cross-runtime contract: the same pretty-printed fixture bytes the frontend
	 * parser/formatter test consumes are imported here through the PHP service.
	 *
	 * What this proves: fixture → import → export preserves the authoring
	 * content, flow references, step configuration and typed condition values
	 * when compared semantically, and a second import/export cycle reproduces the
	 * same compact document. It does not claim byte identity with the fixture:
	 * the fixture is pretty-printed (2-space) while the export is compact, and
	 * values are normalized through JSON storage (for example a numeric
	 * condition value round-trips as `5`).
	 */
	public function testCrossRuntimeFixtureImportsAndReexportsSemanticallyEquivalentContent(): void {
		$this->addUser('alice');
		$this->addUser('bob');

		$path = dirname(__DIR__, 3) . '/tests/fixtures/template-export-v1.json';
		self::assertFileExists($path, 'the shared contract fixture must exist');
		$raw = (string)file_get_contents($path);
		$document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($document);

		$imported = $this->importServiceFor('bob')->import($document);
		self::assertSame('bob', $imported->getOwner());
		self::assertSame(TemplateStatus::Draft->value, $imported->getStatus());
		self::assertSame(1, $imported->getVersion());
		self::assertCount(0, $this->aclEntries, 'no ACL rows are transferred');

		$service = $this->serviceFor('bob');
		$sections = $service->getSections($imported->getId());
		self::assertSame(
			['Configuration', 'Manual notes', 'Production', 'Development', 'Final checks'],
			array_map(static fn (TemplateSection $section): string => $section->getTitle(), $sections),
		);
		self::assertCount(0, $service->getSteps($sections[1]->getId()), 'the zero-step section is preserved');

		$types = [];
		$assignee = null;
		foreach ($sections as $section) {
			foreach ($service->getSteps($section->getId()) as $step) {
				$types[] = $step->getType();
				if ($step->getTitle() === 'Approver') {
					$assignee = $step->getDefaultAssignee();
				}
			}
		}
		sort($types);
		self::assertSame(['CHECK', 'CONFIRMATION', 'DATE', 'FILE', 'NUMBER', 'SELECT', 'TEXT', 'USER'], $types);
		self::assertSame('principals/users/alice', $assignee, 'the resolvable default assignee is preserved');

		$configSteps = $service->getSteps($sections[0]->getId());
		self::assertSame(
			[
				['stepId' => $configSteps[0]->getId(), 'operator' => 'equals', 'value' => 'prod'],
				['stepId' => $configSteps[1]->getId(), 'operator' => 'greater_than', 'value' => 5.5],
			],
			$sections[2]->getConditions(),
			'the AND conditions are re-mapped to new ids with their typed values',
		);
		$sectionIndexById = [];
		foreach ($sections as $index => $section) {
			$sectionIndexById[$section->getId()] = $index;
		}
		$finalDependencies = array_map(static fn (int $id): int => $sectionIndexById[$id], $sections[4]->getDependsOnIds());
		sort($finalDependencies);
		self::assertSame([2, 3], $finalDependencies, 'both branch dependencies are preserved');

		// Fixture → import → export: compare the exported document with the
		// fixture semantically (not byte-for-byte; see the class docblock above).
		$exported = $this->exportServiceFor('bob')->export($imported->getId());
		self::assertSame($document['template']['title'], $exported['template']['title']);
		self::assertSame($document['template']['description'], $exported['template']['description']);
		self::assertCount(count($document['sections']), $exported['sections']);
		foreach ($document['sections'] as $index => $fixtureSection) {
			$exportSection = $exported['sections'][$index];
			self::assertSame($fixtureSection['ref'], $exportSection['ref']);
			self::assertSame($fixtureSection['title'], $exportSection['title']);
			self::assertSame($fixtureSection['description'], $exportSection['description']);
			self::assertSame($fixtureSection['notes'], $exportSection['notes']);
			self::assertSame($fixtureSection['dependsOn'], $exportSection['dependsOn']);
			self::assertCount(count($fixtureSection['conditions']), $exportSection['conditions']);
			foreach ($fixtureSection['conditions'] as $conditionIndex => $fixtureCondition) {
				$exportCondition = $exportSection['conditions'][$conditionIndex];
				self::assertSame($fixtureCondition['stepRef'], $exportCondition['stepRef']);
				self::assertSame($fixtureCondition['operator'], $exportCondition['operator']);
				// Authoring validation documents the stored type per controlling
				// step: string operators (SELECT/TEXT/DATE/USER) keep a string,
				// NUMBER operators store a float, and boolean operators
				// (CHECK/CONFIRMATION) store no value. Assert the exact type and
				// presence instead of a permissive comparison.
				if (array_key_exists('value', $fixtureCondition)) {
					self::assertArrayHasKey('value', $exportCondition);
					self::assertSame($fixtureCondition['value'], $exportCondition['value'] ?? null, 'the typed condition value is preserved');
				} else {
					self::assertArrayNotHasKey('value', $exportCondition, 'a boolean condition keeps no value');
				}
			}
		}
		self::assertCount(count($document['steps']), $exported['steps']);
		foreach ($document['steps'] as $index => $fixtureStep) {
			$exportStep = $exported['steps'][$index];
			foreach (['ref', 'sectionRef', 'title', 'description', 'type', 'required', 'position', 'defaultAssignee', 'dueOffset'] as $key) {
				self::assertSame($fixtureStep[$key], $exportStep[$key], $key . ' of step ' . $index);
			}
			// Step configuration is stored verbatim (authoring only normalizes
			// SELECT options/ordering and validates the NUMBER unit), so keys and
			// scalar types are preserved; compare strictly after unwrapping the
			// exported JSON object into an array.
			self::assertSame((array)$fixtureStep['config'], (array)$exportStep['config'], 'config of step ' . $index);
		}

		// A second import/export cycle is stable: both documents are produced by
		// the same encoder, so here a byte comparison is meaningful.
		$secondDocument = json_decode(json_encode($exported, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($secondDocument);
		$second = $this->importServiceFor('bob')->import($secondDocument);
		self::assertSame(
			json_encode($exported, JSON_THROW_ON_ERROR),
			json_encode($this->exportServiceFor('bob')->export($second->getId()), JSON_THROW_ON_ERROR),
			'a second import/export cycle reproduces the same compact document',
		);
	}
}
