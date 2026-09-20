<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\ActivityEvent;
use OCA\Runbook\Db\ActivityMapper;
use OCA\Runbook\Db\Attachment;
use OCA\Runbook\Db\AttachmentMapper;
use OCA\Runbook\Db\Comment;
use OCA\Runbook\Db\CommentMapper;
use OCA\Runbook\Db\CommentMention;
use OCA\Runbook\Db\CommentMentionMapper;
use OCA\Runbook\Db\NotificationDelivery;
use OCA\Runbook\Db\NotificationDeliveryMapper;
use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunAcl;
use OCA\Runbook\Db\RunAclMapper;
use OCA\Runbook\Db\RunMapper;
use OCA\Runbook\Db\RunSection;
use OCA\Runbook\Db\RunSectionMapper;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Db\RunStepMapper;
use OCA\Runbook\Db\Template;
use OCA\Runbook\Db\TemplateAcl;
use OCA\Runbook\Db\TemplateAclMapper;
use OCA\Runbook\Db\TemplateMapper;
use OCA\Runbook\Db\TemplateSection;
use OCA\Runbook\Db\TemplateSectionMapper;
use OCA\Runbook\Db\TemplateStep;
use OCA\Runbook\Db\TemplateStepMapper;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Enum\TemplateStatus;
use OCA\Runbook\Service\ActivityService;
use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\AttachmentService;
use OCA\Runbook\Service\CommentService;
use OCA\Runbook\Service\DueNotificationService;
use OCA\Runbook\Service\EvidenceStorage;
use OCA\Runbook\Service\FileTypeDetector;
use OCA\Runbook\Service\MentionService;
use OCA\Runbook\Service\NotificationService;
use OCA\Runbook\Service\PermissionService;
use OCA\Runbook\Service\PrincipalValidator;
use OCA\Runbook\Service\RunAccessService;
use OCA\Runbook\Service\RunAclService;
use OCA\Runbook\Service\RunService;
use OCA\Runbook\Service\RunStepService;
use OCA\Runbook\Service\StepResponseValidator;
use OCA\Runbook\Service\TemplateCreationPolicyService;
use OCA\Runbook\Service\TransactionRunner;
use OCA\Runbook\Service\UploadedFileReader;
use OCA\Runbook\Service\ValidationException;
use OCA\Runbook\Service\WorkService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as NotificationManager;
use OCP\Notification\INotification;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Shared in-memory fixture for run service tests.
 *
 * Mappers are mocked with array backed behaviour so execution, assignment and
 * work aggregation rules can be tested without a database.
 */
abstract class RunTestBase extends TestCase {
	/** @var array<int, Template> */
	protected array $templates = [];
	/** @var array<int, TemplateSection> */
	protected array $templateSections = [];
	/** @var array<int, TemplateStep> */
	protected array $templateSteps = [];
	/** @var list<TemplateAcl> */
	protected array $aclEntries = [];
	/** @var array<string, string> */
	protected array $existingUsers = [];
	/** @var array<string, string> */
	protected array $existingGroups = [];
	/** @var array<string, list<string>> */
	protected array $userGroups = [];
	/** @var array<int, Run> */
	protected array $runs = [];
	/** @var array<int, RunSection> */
	protected array $runSections = [];
	/** @var array<int, RunStep> */
	protected array $runSteps = [];
	/** @var array<int, RunAcl> */
	protected array $runAclEntries = [];
	/** @var array<int, Comment> */
	protected array $comments = [];
	/** @var array<int, CommentMention> */
	protected array $commentMentions = [];
	/** @var array<int, Attachment> */
	protected array $attachments = [];
	/** @var array<int, ActivityEvent> */
	protected array $activityEvents = [];
	/** @var array<int, NotificationDelivery> */
	protected array $notificationDeliveries = [];
	/** @var array<string, string> */
	protected array $userTimezones = [];
	/** @var list<array{user: string, subject: string, params: array<string, mixed>, link: string}> */
	protected array $sentNotifications = [];
	/** Number of upcoming notification attempts that should fail. */
	protected int $failNotifications = 0;
	/** When true, attachment metadata inserts fail (to test orphan cleanup). */
	protected bool $failAttachmentInsert = false;
	/** @var array<string, mixed> */
	private array $pendingNotification = [];
	/** @var array<string, string> */
	protected array $evidenceFiles = [];
	protected ?string $forcedMimeType = null;
	protected int $nextId = 1;
	protected int $now = 1000;

	/** @var TemplateMapper&MockObject */
	protected TemplateMapper $templateMapper;
	/** @var TemplateSectionMapper&MockObject */
	protected TemplateSectionMapper $templateSectionMapper;
	/** @var TemplateStepMapper&MockObject */
	protected TemplateStepMapper $templateStepMapper;
	/** @var TemplateAclMapper&MockObject */
	protected TemplateAclMapper $aclMapper;
	/** @var RunMapper&MockObject */
	protected RunMapper $runMapper;
	/** @var RunSectionMapper&MockObject */
	protected RunSectionMapper $runSectionMapper;
	/** @var RunStepMapper&MockObject */
	protected RunStepMapper $runStepMapper;
	/** @var RunAclMapper&MockObject */
	protected RunAclMapper $runAclMapper;
	/** @var CommentMapper&MockObject */
	protected CommentMapper $commentMapper;
	/** @var CommentMentionMapper&MockObject */
	protected CommentMentionMapper $commentMentionMapper;
	/** @var AttachmentMapper&MockObject */
	protected AttachmentMapper $attachmentMapper;
	/** @var ActivityMapper&MockObject */
	protected ActivityMapper $activityMapper;
	/** @var NotificationDeliveryMapper&MockObject */
	protected NotificationDeliveryMapper $deliveryMapper;
	/** @var NotificationManager&MockObject */
	protected NotificationManager $notificationManager;
	/** @var IURLGenerator&MockObject */
	protected IURLGenerator $urlGenerator;
	/** @var EvidenceStorage&MockObject */
	protected EvidenceStorage $evidenceStorage;
	/** @var UploadedFileReader&MockObject */
	protected UploadedFileReader $fileReader;
	/** @var FileTypeDetector&MockObject */
	protected FileTypeDetector $fileTypeDetector;
	/** @var IUserManager&MockObject */
	protected IUserManager $userManager;
	/** @var IGroupManager&MockObject */
	protected IGroupManager $groupManager;
	/** @var ITimeFactory&MockObject */
	protected ITimeFactory $timeFactory;
	/** @var ISecureRandom&MockObject */
	protected ISecureRandom $secureRandom;
	/** @var TransactionRunner&MockObject */
	protected TransactionRunner $transactionRunner;
	/** @var IConfig&MockObject */
	protected IConfig $config;
	protected IAppConfig $appConfig;
	/** @var array<string, mixed> */
	protected array $appConfigValues = [];
	/** @var list<string> */
	protected array $adminUsers = [];
	protected AdminSettings $adminSettings;
	protected TemplateCreationPolicyService $creationPolicy;
	protected PermissionService $permissionService;
	protected StepResponseValidator $validator;
	protected PrincipalValidator $principalValidator;
	protected RunAccessService $runAccess;
	protected MentionService $mentionService;
	protected NotificationService $notificationService;

	protected function setUp(): void {
		$this->nextId = 1;
		$this->now = 1000;
		$this->forcedMimeType = null;
		$this->failNotifications = 0;
		$this->failAttachmentInsert = false;
		$this->sentNotifications = [];

		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->secureRandom->method('generate')->willReturnCallback(
			static fn (int $length, string $characters = ''): string => str_repeat('a', $length),
		);

		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, string $default = ''): string => $this->userTimezones[$uid] ?? 'UTC',
		);

		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('userExists')->willReturnCallback(
			fn (string $uid, array $excludeBackends = []): bool => array_key_exists($uid, $this->existingUsers),
		);
		$this->userManager->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => array_key_exists($uid, $this->existingUsers) ? $this->userMock($uid) : null,
		);
		$this->userManager->method('getDisplayName')->willReturnCallback(
			static fn (string $uid): string => $uid,
		);

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('getUserGroupIds')->willReturnCallback(
			fn (IUser $user): array => $this->userGroups[$user->getUID()] ?? [],
		);
		$this->groupManager->method('groupExists')->willReturnCallback(
			fn (string $gid): bool => array_key_exists($gid, $this->existingGroups),
		);
		$this->groupManager->method('get')->willReturnCallback(
			fn (string $gid): ?IGroup => array_key_exists($gid, $this->existingGroups) ? $this->groupMock($gid) : null,
		);
		$this->groupManager->method('isAdmin')->willReturnCallback(
			fn (string $uid): bool => in_array($uid, $this->adminUsers, true),
		);

		$this->appConfig = $this->appConfigMock();
		$this->adminSettings = new AdminSettings($this->appConfig, $this->groupManager);
		$this->creationPolicy = new TemplateCreationPolicyService($this->adminSettings, $this->groupManager, $this->userManager);

		$this->transactionRunner = $this->createMock(TransactionRunner::class);
		$this->transactionRunner->method('run')->willReturnCallback(
			static fn (callable $operation): mixed => $operation(),
		);

		$this->configureTemplateMappers();
		$this->configureAclMapper();
		$this->configureRunMappers();
		$this->configureRunAclMapper();
		$this->configureCollaborationMappers();

		$this->permissionService = new PermissionService($this->aclMapper, $this->userManager, $this->groupManager);
		$this->validator = new StepResponseValidator($this->userManager);
		$this->principalValidator = new PrincipalValidator($this->userManager, $this->groupManager);
		$this->runAccess = new RunAccessService($this->runAclMapper, $this->runStepMapper, $this->userManager, $this->groupManager);
		$this->mentionService = new MentionService($this->commentMentionMapper, $this->userManager, $this->timeFactory);

		$this->configureNotifications();
	}

	private function configureNotifications(): void {
		$this->deliveryMapper = $this->createMock(NotificationDeliveryMapper::class);
		$this->deliveryMapper->method('insert')->willReturnCallback(function (NotificationDelivery $delivery): NotificationDelivery {
			$delivery->setId($this->nextId++);
			$this->notificationDeliveries[$delivery->getId()] = $delivery;

			return $delivery;
		});
		$this->deliveryMapper->method('findByKey')->willReturnCallback(function (string $key): ?NotificationDelivery {
			foreach ($this->notificationDeliveries as $delivery) {
				if ($delivery->getDedupeKey() === $key) {
					return $delivery;
				}
			}

			return null;
		});
		$this->deliveryMapper->method('claimForDelivery')->willReturnCallback(function (int $id, int $now, int $maxAttempts, int $staleBefore): bool {
			$delivery = $this->notificationDeliveries[$id] ?? null;
			if ($delivery === null) {
				return false;
			}
			if ($delivery->getStatus() === NotificationDelivery::STATUS_SENT) {
				return false;
			}
			if ($delivery->getAttempts() >= $maxAttempts) {
				return false;
			}
			if ($delivery->getStatus() === NotificationDelivery::STATUS_PENDING && $delivery->getUpdatedAt() >= $staleBefore) {
				return false;
			}

			$delivery->setStatus(NotificationDelivery::STATUS_PENDING);
			$delivery->setAttempts($delivery->getAttempts() + 1);
			$delivery->setUpdatedAt($now);

			return true;
		});
		$this->deliveryMapper->method('markSent')->willReturnCallback(function (int $id, int $now): void {
			if (isset($this->notificationDeliveries[$id])) {
				$this->notificationDeliveries[$id]->setStatus(NotificationDelivery::STATUS_SENT);
				$this->notificationDeliveries[$id]->setUpdatedAt($now);
			}
		});
		$this->deliveryMapper->method('markFailed')->willReturnCallback(function (int $id, int $now): void {
			if (isset($this->notificationDeliveries[$id])) {
				$this->notificationDeliveries[$id]->setStatus(NotificationDelivery::STATUS_FAILED);
				$this->notificationDeliveries[$id]->setUpdatedAt($now);
			}
		});
		$this->deliveryMapper->method('find')->willReturnCallback(function (int $id): NotificationDelivery {
			if (!isset($this->notificationDeliveries[$id])) {
				throw new DoesNotExistException('notification delivery not found');
			}

			return $this->notificationDeliveries[$id];
		});

		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRoute')->willReturn('/index.php/apps/runbook/');
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example.com/index.php/apps/runbook/');
		$this->urlGenerator->method('imagePath')->willReturn('/apps/runbook/img/app-dark.svg');
		$this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $url): string => 'https://cloud.example.com' . $url,
		);

		$this->notificationManager = $this->createMock(NotificationManager::class);
		$this->notificationManager->method('createNotification')->willReturnCallback(
			fn (): INotification => $this->notificationMock(),
		);
		$this->notificationManager->method('notify')->willReturnCallback(function (): void {
			if ($this->failNotifications > 0) {
				$this->failNotifications--;

				throw new \RuntimeException('Simulated notification failure');
			}

			$this->sentNotifications[] = [
				'user' => (string)($this->pendingNotification['user'] ?? ''),
				'subject' => (string)($this->pendingNotification['subject'] ?? ''),
				'params' => is_array($this->pendingNotification['params'] ?? null) ? $this->pendingNotification['params'] : [],
				'link' => (string)($this->pendingNotification['link'] ?? ''),
			];
			$this->pendingNotification = [];
		});

		$this->notificationService = new NotificationService(
			$this->notificationManager,
			$this->userManager,
			$this->groupManager,
			$this->deliveryMapper,
			$this->runAccess,
			$this->adminSettings,
			$this->timeFactory,
			$this->urlGenerator,
			new NullLogger(),
		);
	}

	/**
	 * Typed in-memory app configuration double backed by an array.
	 */
	private function appConfigMock(): IAppConfig {
		/** @var IAppConfig&MockObject $mock */
		$mock = $this->createMock(IAppConfig::class);
		$mock->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '', bool $lazy = false): string => is_string($this->appConfigValues[$key] ?? null) ? $this->appConfigValues[$key] : $default,
		);
		$mock->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0, bool $lazy = false): int => is_int($this->appConfigValues[$key] ?? null) ? $this->appConfigValues[$key] : $default,
		);
		$mock->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false, bool $lazy = false): bool => is_bool($this->appConfigValues[$key] ?? null) ? $this->appConfigValues[$key] : $default,
		);
		$mock->method('getValueArray')->willReturnCallback(
			fn (string $app, string $key, array $default = [], bool $lazy = false): array => is_array($this->appConfigValues[$key] ?? null) ? $this->appConfigValues[$key] : $default,
		);
		$mock->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
			$this->appConfigValues[$key] = $value;

			return true;
		});
		$mock->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value, bool $lazy = false, bool $sensitive = false): bool {
			$this->appConfigValues[$key] = $value;

			return true;
		});
		$mock->method('setValueBool')->willReturnCallback(function (string $app, string $key, bool $value, bool $lazy = false): bool {
			$this->appConfigValues[$key] = $value;

			return true;
		});
		$mock->method('setValueArray')->willReturnCallback(function (string $app, string $key, array $value, bool $lazy = false, bool $sensitive = false): bool {
			$this->appConfigValues[$key] = $value;

			return true;
		});

		return $mock;
	}

	protected function setAppConfig(string $key, mixed $value): void {
		$this->appConfigValues[$key] = $value;
	}

	protected function addAdmin(string $uid): void {
		$this->adminUsers[] = $uid;
	}

	private function notificationMock(): INotification {
		/** @var INotification&MockObject $notification */
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setUser')->willReturnCallback(function (string $user) use ($notification): INotification {
			$this->pendingNotification['user'] = $user;

			return $notification;
		});
		$notification->method('setSubject')->willReturnCallback(function (string $subject, array $params = []) use ($notification): INotification {
			$this->pendingNotification['subject'] = $subject;
			$this->pendingNotification['params'] = $params;

			return $notification;
		});
		$notification->method('setLink')->willReturnCallback(function (string $link) use ($notification): INotification {
			$this->pendingNotification['link'] = $link;

			return $notification;
		});

		return $notification;
	}

	private function configureTemplateMappers(): void {
		$this->templateMapper = $this->createMock(TemplateMapper::class);
		$this->templateMapper->method('find')->willReturnCallback(function (int $id): Template {
			if (!isset($this->templates[$id])) {
				throw new DoesNotExistException('template not found');
			}

			return $this->templates[$id];
		});
		$this->templateMapper->method('delete')->willReturnCallback(function (Template $template): Template {
			unset($this->templates[$template->getId()]);
			foreach ($this->templateSections as $id => $section) {
				if ($section->getTemplateId() === $template->getId()) {
					unset($this->templateSections[$id]);
				}
			}
			foreach ($this->templateSteps as $id => $step) {
				unset($this->templateSteps[$id]);
			}

			return $template;
		});

		$this->templateSectionMapper = $this->createMock(TemplateSectionMapper::class);
		$this->templateSectionMapper->method('findByTemplate')->willReturnCallback(function (int $templateId): array {
			$result = array_values(array_filter(
				$this->templateSections,
				static fn (TemplateSection $section): bool => $section->getTemplateId() === $templateId,
			));
			usort($result, static fn (TemplateSection $a, TemplateSection $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

			return $result;
		});

		$this->templateStepMapper = $this->createMock(TemplateStepMapper::class);
		$this->templateStepMapper->method('findBySection')->willReturnCallback(function (int $sectionId): array {
			$result = array_values(array_filter(
				$this->templateSteps,
				static fn (TemplateStep $step): bool => $step->getSectionId() === $sectionId,
			));
			usort($result, static fn (TemplateStep $a, TemplateStep $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

			return $result;
		});
	}

	private function configureAclMapper(): void {
		$this->aclMapper = $this->createMock(TemplateAclMapper::class);
		$this->aclMapper->method('findByTemplate')->willReturnCallback(
			fn (int $templateId): array => array_values(array_filter(
				$this->aclEntries,
				static fn (TemplateAcl $entry): bool => $entry->getTemplateId() === $templateId,
			)),
		);
	}

	private function configureRunMappers(): void {
		$this->runMapper = $this->createMock(RunMapper::class);
		$this->runMapper->method('insert')->willReturnCallback(function (Run $run): Run {
			$run->setId($this->nextId++);
			$this->runs[$run->getId()] = $run;

			return $run;
		});
		$this->runMapper->method('update')->willReturnCallback(function (Run $run): Run {
			$this->runs[$run->getId()] = $run;

			return $run;
		});
		$this->runMapper->method('delete')->willReturnCallback(function (Run $run): Run {
			unset($this->runs[$run->getId()]);

			return $run;
		});
		$this->runMapper->method('find')->willReturnCallback(function (int $id): Run {
			if (!isset($this->runs[$id])) {
				throw new DoesNotExistException('run not found');
			}

			return $this->runs[$id];
		});
		$this->runMapper->method('findByOwner')->willReturnCallback(function (string $owner): array {
			$result = array_values(array_filter(
				$this->runs,
				static fn (Run $run): bool => $run->getOwner() === $owner,
			));
			usort($result, static fn (Run $a, Run $b): int => [$b->getUpdatedAt(), $b->getId()] <=> [$a->getUpdatedAt(), $a->getId()]);

			return $result;
		});
		$this->runMapper->method('findByIds')->willReturnCallback(function (array $ids): array {
			$result = [];
			foreach ($ids as $id) {
				if (isset($this->runs[$id])) {
					$result[] = $this->runs[$id];
				}
			}

			return $result;
		});
		$this->runMapper->method('findAccessible')->willReturnCallback(function (string $owner, array $runIds, ?int $limit = null): array {
			$result = array_values(array_filter(
				$this->runs,
				static fn (Run $run): bool => $run->getOwner() === $owner || in_array($run->getId(), $runIds, true),
			));
			usort($result, static fn (Run $a, Run $b): int => [$b->getUpdatedAt(), $b->getId()] <=> [$a->getUpdatedAt(), $a->getId()]);
			if ($limit !== null) {
				$result = array_slice($result, 0, $limit);
			}

			return $result;
		});
		$this->runMapper->method('countAccessible')->willReturnCallback(function (string $owner, array $runIds, ?string $status = null): int {
			return count(array_filter(
				$this->runs,
				static fn (Run $run): bool => ($run->getOwner() === $owner || in_array($run->getId(), $runIds, true))
					&& ($status === null || $run->getStatus() === $status),
			));
		});
		$this->runMapper->method('countAccessibleCompletedSince')->willReturnCallback(function (string $owner, array $runIds, int $from): int {
			return count(array_filter(
				$this->runs,
				static fn (Run $run): bool => ($run->getOwner() === $owner || in_array($run->getId(), $runIds, true))
					&& $run->getStatus() === RunStatus::Completed->value
					&& $run->getCompletedAt() !== null
					&& $run->getCompletedAt() >= $from,
			));
		});

		$this->runSectionMapper = $this->createMock(RunSectionMapper::class);
		$this->runSectionMapper->method('insert')->willReturnCallback(function (RunSection $section): RunSection {
			$section->setId($this->nextId++);
			$this->runSections[$section->getId()] = $section;

			return $section;
		});
		$this->runSectionMapper->method('update')->willReturnCallback(function (RunSection $section): RunSection {
			$this->runSections[$section->getId()] = $section;

			return $section;
		});
		$this->runSectionMapper->method('find')->willReturnCallback(function (int $id): RunSection {
			if (!isset($this->runSections[$id])) {
				throw new DoesNotExistException('run section not found');
			}

			return $this->runSections[$id];
		});
		$this->runSectionMapper->method('findByRun')->willReturnCallback(function (int $runId): array {
			$result = array_values(array_filter(
				$this->runSections,
				static fn (RunSection $section): bool => $section->getRunId() === $runId,
			));
			usort($result, static fn (RunSection $a, RunSection $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

			return $result;
		});
		$this->runSectionMapper->method('findByIds')->willReturnCallback(function (array $ids): array {
			$result = [];
			foreach ($ids as $id) {
				if (isset($this->runSections[$id])) {
					$result[] = $this->runSections[$id];
				}
			}

			return $result;
		});

		$this->runStepMapper = $this->createMock(RunStepMapper::class);
		$this->runStepMapper->method('insert')->willReturnCallback(function (RunStep $step): RunStep {
			$step->setId($this->nextId++);
			$this->runSteps[$step->getId()] = $step;

			return $step;
		});
		$this->runStepMapper->method('update')->willReturnCallback(function (RunStep $step): RunStep {
			$this->runSteps[$step->getId()] = $step;

			return $step;
		});
		$this->runStepMapper->method('find')->willReturnCallback(function (int $id): RunStep {
			if (!isset($this->runSteps[$id])) {
				throw new DoesNotExistException('run step not found');
			}

			return $this->runSteps[$id];
		});
		$this->runStepMapper->method('findBySection')->willReturnCallback(function (int $sectionId): array {
			$result = array_values(array_filter(
				$this->runSteps,
				static fn (RunStep $step): bool => $step->getRunSectionId() === $sectionId,
			));
			usort($result, static fn (RunStep $a, RunStep $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

			return $result;
		});
		$this->runStepMapper->method('findBySections')->willReturnCallback(function (array $sectionIds): array {
			$result = array_values(array_filter(
				$this->runSteps,
				static fn (RunStep $step): bool => in_array($step->getRunSectionId(), $sectionIds, true),
			));
			usort($result, static fn (RunStep $a, RunStep $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

			return $result;
		});
		$this->runStepMapper->method('findByRun')->willReturnCallback(function (int $runId): array {
			$sectionIds = array_map(
				static fn (RunSection $section): int => $section->getId(),
				array_filter($this->runSections, static fn (RunSection $section): bool => $section->getRunId() === $runId),
			);
			$result = array_values(array_filter(
				$this->runSteps,
				static fn (RunStep $step): bool => in_array($step->getRunSectionId(), $sectionIds, true),
			));
			usort($result, static fn (RunStep $a, RunStep $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

			return $result;
		});
		$this->runStepMapper->method('findAssignedTo')->willReturnCallback(function (string $uid, array $groupIds, int $limit): array {
			$result = array_values(array_filter(
				$this->runSteps,
				fn (RunStep $step): bool => $this->matchesAssignee($step, $uid, $groupIds),
			));

			return array_slice($result, 0, $limit);
		});
		$this->runStepMapper->method('findAssignedInRun')->willReturnCallback(function (int $runId, string $uid, array $groupIds): array {
			$sectionIds = array_map(
				static fn (RunSection $section): int => $section->getId(),
				array_filter($this->runSections, static fn (RunSection $section): bool => $section->getRunId() === $runId),
			);
			foreach ($this->runSteps as $step) {
				if (in_array($step->getRunSectionId(), $sectionIds, true) && $this->matchesAssignee($step, $uid, $groupIds)) {
					return [$step];
				}
			}

			return [];
		});
		$this->runStepMapper->method('findDistinctRunIdsForPrincipal')->willReturnCallback(function (string $uid, array $groupIds): array {
			$runIds = [];
			foreach ($this->runSteps as $step) {
				if (!$this->matchesAssignee($step, $uid, $groupIds)) {
					continue;
				}
				$section = $this->runSections[$step->getRunSectionId()] ?? null;
				if ($section !== null) {
					$runIds[] = $section->getRunId();
				}
			}

			/** @var list<int> $unique */
			$unique = array_values(array_unique($runIds));

			return $unique;
		});
		$this->runStepMapper->method('countAssignedActive')->willReturnCallback(function (string $uid, array $groupIds): int {
			return count(array_filter($this->runSteps, function (RunStep $step) use ($uid, $groupIds): bool {
				$section = $this->runSections[$step->getRunSectionId()] ?? null;
				$run = $section !== null ? ($this->runs[$section->getRunId()] ?? null) : null;
				$status = RunStepStatus::tryFrom($step->getStatus());

				return $run !== null
					&& $run->getStatus() === RunStatus::Active->value
					&& ($status === RunStepStatus::Pending || $status === RunStepStatus::InProgress)
					&& $this->matchesAssignee($step, $uid, $groupIds);
			}));
		});
		$this->runStepMapper->method('countAssignedOverdue')->willReturnCallback(function (string $uid, array $groupIds, int $now): int {
			return count(array_filter($this->runSteps, function (RunStep $step) use ($uid, $groupIds, $now): bool {
				$dueAt = $step->getDueAt();
				if ($dueAt === null || $dueAt >= $now) {
					return false;
				}
				$section = $this->runSections[$step->getRunSectionId()] ?? null;
				$run = $section !== null ? ($this->runs[$section->getRunId()] ?? null) : null;
				$status = RunStepStatus::tryFrom($step->getStatus());

				return $run !== null
					&& $run->getStatus() === RunStatus::Active->value
					&& ($status === RunStepStatus::Pending || $status === RunStepStatus::InProgress)
					&& $this->matchesAssignee($step, $uid, $groupIds);
			}));
		});
		$this->runStepMapper->method('countAssignedCompletedSince')->willReturnCallback(function (string $uid, array $groupIds, int $from): int {
			return count(array_filter($this->runSteps, function (RunStep $step) use ($uid, $groupIds, $from): bool {
				$completedAt = $step->getCompletedAt();

				return $step->getStatus() === RunStepStatus::Completed->value
					&& $completedAt !== null
					&& $completedAt >= $from
					&& $this->matchesAssignee($step, $uid, $groupIds);
			}));
		});
	}

	private function configureRunAclMapper(): void {
		$this->runAclMapper = $this->createMock(RunAclMapper::class);
		$this->runAclMapper->method('insert')->willReturnCallback(function (RunAcl $entry): RunAcl {
			$entry->setId($this->nextId++);
			$this->runAclEntries[$entry->getId()] = $entry;

			return $entry;
		});
		$this->runAclMapper->method('find')->willReturnCallback(function (int $id): RunAcl {
			if (!isset($this->runAclEntries[$id])) {
				throw new DoesNotExistException('run acl entry not found');
			}

			return $this->runAclEntries[$id];
		});
		$this->runAclMapper->method('findByRun')->willReturnCallback(
			fn (int $runId): array => array_values(array_filter(
				$this->runAclEntries,
				static fn (RunAcl $entry): bool => $entry->getRunId() === $runId,
			)),
		);
		$this->runAclMapper->method('deleteByRun')->willReturnCallback(function (int $runId): void {
			foreach ($this->runAclEntries as $id => $entry) {
				if ($entry->getRunId() === $runId) {
					unset($this->runAclEntries[$id]);
				}
			}
		});
		$this->runAclMapper->method('findRunIdsForPrincipal')->willReturnCallback(function (string $uid, array $groupIds): array {
			$runIds = [];
			foreach ($this->runAclEntries as $entry) {
				$type = PrincipalType::tryFrom($entry->getPrincipalType());
				$applies = ($type === PrincipalType::User && $entry->getPrincipalId() === $uid)
					|| ($type === PrincipalType::Group && in_array($entry->getPrincipalId(), $groupIds, true));
				if ($applies) {
					$runIds[] = $entry->getRunId();
				}
			}

			/** @var list<int> $unique */
			$unique = array_values(array_unique($runIds));

			return $unique;
		});
	}

	private function configureCollaborationMappers(): void {
		$this->commentMapper = $this->createMock(CommentMapper::class);
		$this->commentMapper->method('insert')->willReturnCallback(function (Comment $comment): Comment {
			$comment->setId($this->nextId++);
			$this->comments[$comment->getId()] = $comment;

			return $comment;
		});
		$this->commentMapper->method('update')->willReturnCallback(function (Comment $comment): Comment {
			$this->comments[$comment->getId()] = $comment;

			return $comment;
		});
		$this->commentMapper->method('delete')->willReturnCallback(function (Comment $comment): Comment {
			unset($this->comments[$comment->getId()]);
			foreach ($this->commentMentions as $id => $mention) {
				if ($mention->getCommentId() === $comment->getId()) {
					unset($this->commentMentions[$id]);
				}
			}

			return $comment;
		});
		$this->commentMapper->method('find')->willReturnCallback(function (int $id): Comment {
			if (!isset($this->comments[$id])) {
				throw new DoesNotExistException('comment not found');
			}

			return $this->comments[$id];
		});
		$this->commentMapper->method('findByRun')->willReturnCallback(function (int $runId): array {
			$result = array_values(array_filter(
				$this->comments,
				static fn (Comment $comment): bool => $comment->getRunId() === $runId,
			));
			usort($result, static fn (Comment $a, Comment $b): int => [$a->getCreatedAt(), $a->getId()] <=> [$b->getCreatedAt(), $b->getId()]);

			return $result;
		});

		$this->commentMentionMapper = $this->createMock(CommentMentionMapper::class);
		$this->commentMentionMapper->method('insert')->willReturnCallback(function (CommentMention $mention): CommentMention {
			$mention->setId($this->nextId++);
			$this->commentMentions[$mention->getId()] = $mention;

			return $mention;
		});
		$this->commentMentionMapper->method('findByComment')->willReturnCallback(
			fn (int $commentId): array => array_values(array_filter(
				$this->commentMentions,
				static fn (CommentMention $mention): bool => $mention->getCommentId() === $commentId,
			)),
		);
		$this->commentMentionMapper->method('findByComments')->willReturnCallback(
			fn (array $commentIds): array => array_values(array_filter(
				$this->commentMentions,
				static fn (CommentMention $mention): bool => in_array($mention->getCommentId(), $commentIds, true),
			)),
		);
		$this->commentMentionMapper->method('deleteByComment')->willReturnCallback(function (int $commentId): void {
			foreach ($this->commentMentions as $id => $mention) {
				if ($mention->getCommentId() === $commentId) {
					unset($this->commentMentions[$id]);
				}
			}
		});

		$this->attachmentMapper = $this->createMock(AttachmentMapper::class);
		$this->attachmentMapper->method('insert')->willReturnCallback(function (Attachment $attachment): Attachment {
			if ($this->failAttachmentInsert) {
				throw new \OCP\DB\Exception('Simulated metadata insert failure');
			}
			$attachment->setId($this->nextId++);
			$this->attachments[$attachment->getId()] = $attachment;

			return $attachment;
		});
		$this->attachmentMapper->method('delete')->willReturnCallback(function (Attachment $attachment): Attachment {
			unset($this->attachments[$attachment->getId()]);

			return $attachment;
		});
		$this->attachmentMapper->method('find')->willReturnCallback(function (int $id): Attachment {
			if (!isset($this->attachments[$id])) {
				throw new DoesNotExistException('attachment not found');
			}

			return $this->attachments[$id];
		});
		$this->attachmentMapper->method('findByRun')->willReturnCallback(function (int $runId): array {
			$result = array_values(array_filter(
				$this->attachments,
				static fn (Attachment $attachment): bool => $attachment->getRunId() === $runId,
			));
			usort($result, static fn (Attachment $a, Attachment $b): int => [$a->getCreatedAt(), $a->getId()] <=> [$b->getCreatedAt(), $b->getId()]);

			return $result;
		});
		$this->attachmentMapper->method('findByStep')->willReturnCallback(
			fn (int $stepId): array => array_values(array_filter(
				$this->attachments,
				static fn (Attachment $attachment): bool => $attachment->getStepId() === $stepId,
			)),
		);

		$this->activityMapper = $this->createMock(ActivityMapper::class);
		$this->activityMapper->method('insert')->willReturnCallback(function (ActivityEvent $event): ActivityEvent {
			$event->setId($this->nextId++);
			$this->activityEvents[$event->getId()] = $event;

			return $event;
		});
		$this->activityMapper->method('find')->willReturnCallback(function (int $id): ActivityEvent {
			if (!isset($this->activityEvents[$id])) {
				throw new DoesNotExistException('activity event not found');
			}

			return $this->activityEvents[$id];
		});
		$this->activityMapper->method('findByRun')->willReturnCallback(function (int $runId, int $limit, bool $descending = true): array {
			$result = array_values(array_filter(
				$this->activityEvents,
				static fn (ActivityEvent $event): bool => $event->getRunId() === $runId,
			));
			usort($result, static function (ActivityEvent $a, ActivityEvent $b) use ($descending): int {
				$order = [$a->getCreatedAt(), $a->getId()] <=> [$b->getCreatedAt(), $b->getId()];

				return $descending ? -$order : $order;
			});

			return array_slice($result, 0, $limit);
		});

		$this->evidenceStorage = $this->createMock(EvidenceStorage::class);
		$this->evidenceStorage->method('write')->willReturnCallback(function (int $runId, int $stepId, string $storageKey, string $content): void {
			$this->evidenceFiles[$runId . ':' . $stepId . ':' . $storageKey] = $content;
		});
		$this->evidenceStorage->method('read')->willReturnCallback(function (int $runId, int $stepId, string $storageKey): string {
			$key = $runId . ':' . $stepId . ':' . $storageKey;
			if (!isset($this->evidenceFiles[$key])) {
				throw new \OCP\Files\NotFoundException('evidence not found');
			}

			return $this->evidenceFiles[$key];
		});
		$this->evidenceStorage->method('delete')->willReturnCallback(function (int $runId, int $stepId, string $storageKey): void {
			unset($this->evidenceFiles[$runId . ':' . $stepId . ':' . $storageKey]);
		});

		$this->fileReader = $this->createMock(UploadedFileReader::class);
		$this->fileReader->method('read')->willReturnCallback(function (array $file): array {
			if (!array_key_exists('name', $file) && !array_key_exists('content', $file)) {
				throw new ValidationException('attachment_upload_failed');
			}

			return [
				'name' => (string)($file['name'] ?? 'evidence.txt'),
				'content' => (string)($file['content'] ?? ''),
			];
		});

		$this->fileTypeDetector = $this->createMock(FileTypeDetector::class);
		$this->fileTypeDetector->method('detect')->willReturnCallback(function (string $content, string $filename): string {
			if ($this->forcedMimeType !== null) {
				return $this->forcedMimeType;
			}
			if (str_starts_with($content, "\x89PNG")) {
				return 'image/png';
			}
			if (str_starts_with($content, '%PDF')) {
				return 'application/pdf';
			}

			return 'text/plain';
		});
	}

	/**
	 * @param array<array-key, string> $groupIds
	 */
	private function matchesAssignee(RunStep $step, string $uid, array $groupIds): bool {
		$type = $step->getAssigneeType();
		$id = $step->getAssigneeId();
		if ($type === null || $id === null) {
			return false;
		}

		if ($type === PrincipalType::User->value) {
			return $id === $uid;
		}

		return in_array($id, $groupIds, true);
	}

	/**
	 * @return IUser&MockObject
	 */
	private function userMock(string $uid): IUser {
		/** @var IUser&MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($uid);

		return $user;
	}

	/**
	 * @return IGroup&MockObject
	 */
	private function groupMock(string $gid): IGroup {
		/** @var IGroup&MockObject $group */
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$group->method('getUsers')->willReturnCallback(function () use ($gid): array {
			$users = [];
			foreach ($this->userGroups as $uid => $groups) {
				if (in_array($gid, $groups, true)) {
					$users[] = $this->userMock($uid);
				}
			}

			return $users;
		});

		return $group;
	}

	protected function addUser(string $uid): void {
		$this->existingUsers[$uid] = $uid;
	}

	protected function addGroup(string $gid): void {
		$this->existingGroups[$gid] = $gid;
	}

	protected function addUserTimezone(string $uid, string $timezone): void {
		$this->userTimezones[$uid] = $timezone;
	}

	protected function joinGroup(string $uid, string $gid): void {
		$this->userGroups[$uid][] = $gid;
	}

	protected function runServiceFor(string $uid): RunService {
		return new RunService(
			$this->templateMapper,
			$this->templateSectionMapper,
			$this->templateStepMapper,
			$this->permissionService,
			$this->runMapper,
			$this->runSectionMapper,
			$this->runStepMapper,
			$this->runAclMapper,
			$this->runAccess,
			$this->principalValidator,
			$this->sessionFor($uid),
			$this->timeFactory,
			$this->secureRandom,
			$this->transactionRunner,
			$this->activityServiceFor($uid),
			$this->notificationService,
			$this->adminSettings,
		);
	}

	protected function runStepServiceFor(string $uid): RunStepService {
		return new RunStepService(
			$this->runMapper,
			$this->runSectionMapper,
			$this->runStepMapper,
			$this->runAccess,
			$this->principalValidator,
			$this->validator,
			$this->activityServiceFor($uid),
			$this->notificationService,
			$this->adminSettings,
			$this->sessionFor($uid),
			$this->timeFactory,
		);
	}

	protected function runAclServiceFor(string $uid): RunAclService {
		return new RunAclService(
			$this->runMapper,
			$this->runAclMapper,
			$this->runAccess,
			$this->principalValidator,
			$this->sessionFor($uid),
			$this->timeFactory,
			$this->transactionRunner,
			$this->activityServiceFor($uid),
			$this->notificationService,
		);
	}

	protected function dueNotificationService(): DueNotificationService {
		return new DueNotificationService(
			$this->runStepMapper,
			$this->runSectionMapper,
			$this->runMapper,
			$this->notificationService,
			$this->adminSettings,
			$this->timeFactory,
			$this->config,
			new NullLogger(),
		);
	}

	protected function activityServiceFor(string $uid): ActivityService {
		return new ActivityService(
			$this->activityMapper,
			$this->sessionFor($uid),
			$this->timeFactory,
		);
	}

	protected function commentServiceFor(string $uid): CommentService {
		return new CommentService(
			$this->commentMapper,
			$this->mentionService,
			$this->runServiceFor($uid),
			$this->runAccess,
			$this->activityServiceFor($uid),
			$this->notificationService,
			$this->adminSettings,
			$this->sessionFor($uid),
			$this->timeFactory,
			$this->secureRandom,
		);
	}

	protected function attachmentServiceFor(string $uid): AttachmentService {
		return new AttachmentService(
			$this->attachmentMapper,
			$this->runServiceFor($uid),
			$this->runStepServiceFor($uid),
			$this->runAccess,
			$this->evidenceStorage,
			$this->activityServiceFor($uid),
			$this->adminSettings,
			$this->fileReader,
			$this->fileTypeDetector,
			$this->sessionFor($uid),
			$this->timeFactory,
			$this->secureRandom,
		);
	}

	protected function workServiceFor(string $uid): WorkService {
		return new WorkService(
			$this->runMapper,
			$this->runSectionMapper,
			$this->runStepMapper,
			$this->runAclMapper,
			$this->runAccess,
			$this->sessionFor($uid),
			$this->timeFactory,
			$this->config,
		);
	}

	/**
	 * @return IUserSession&MockObject
	 */
	private function sessionFor(string $uid): IUserSession {
		/** @var IUser&MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		/** @var IUserSession&MockObject $session */
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}

	protected function addTemplate(string $owner, string $status = TemplateStatus::Published->value, int $version = 2): Template {
		$template = new Template();
		$template->setId($this->nextId++);
		$template->setUuid('00000000-0000-4000-8000-000000000000');
		$template->setTitle('Deploy');
		$template->setDescription('Deploy description');
		$template->setVersion($version);
		$template->setStatus($status);
		$template->setOwner($owner);
		$template->setCreatedAt($this->now);
		$template->setUpdatedAt($this->now);
		$this->templates[$template->getId()] = $template;

		return $template;
	}

	protected function addTemplateSection(int $templateId, string $title, int $position): TemplateSection {
		$section = new TemplateSection();
		$section->setId($this->nextId++);
		$section->setTemplateId($templateId);
		$section->setTitle($title);
		$section->setDescription($title . ' description');
		$section->setPosition($position);
		$this->templateSections[$section->getId()] = $section;

		return $section;
	}

	/**
	 * @param array<string, mixed> $config
	 */
	protected function addTemplateStep(
		int $sectionId,
		string $title,
		string $type,
		bool $required,
		int $position,
		array $config = [],
		?string $defaultAssignee = null,
		?string $dueOffset = null,
	): TemplateStep {
		$step = new TemplateStep();
		$step->setId($this->nextId++);
		$step->setSectionId($sectionId);
		$step->setUuid('00000000-0000-4000-8000-00000000000' . ($position % 10));
		$step->setTitle($title);
		$step->setDescription($title . ' description');
		$step->setType($type);
		$step->setRequired($required);
		$step->setPosition($position);
		$step->setConfigArray($config);
		$step->setDefaultAssignee($defaultAssignee);
		$step->setDueOffset($dueOffset);
		$this->templateSteps[$step->getId()] = $step;

		return $step;
	}

	protected function seedAcl(int $templateId, string $principalType, string $principalId, string $role): void {
		$entry = new TemplateAcl();
		$entry->setId($this->nextId++);
		$entry->setTemplateId($templateId);
		$entry->setPrincipalType($principalType);
		$entry->setPrincipalId($principalId);
		$entry->setRole($role);
		$entry->setCreatedAt($this->now);
		$entry->setUpdatedAt($this->now);
		$this->aclEntries[] = $entry;
	}

	protected function addRun(string $owner, string $status = RunStatus::Active->value, ?int $dueAt = null): Run {
		$run = new Run();
		$run->setId($this->nextId++);
		$run->setUuid('00000000-0000-4000-8000-000000000000');
		$run->setTemplateId(1);
		$run->setTemplateVersion(1);
		$run->setTitle('Run');
		$run->setDescription('');
		$run->setOwner($owner);
		$run->setStatus($status);
		$run->setCreatedAt($this->now);
		$run->setStartedAt($this->now);
		$run->setDueAt($dueAt);
		$run->setUpdatedAt($this->now);
		$this->runs[$run->getId()] = $run;

		return $run;
	}

	protected function addRunSection(int $runId, int $position): RunSection {
		$section = new RunSection();
		$section->setId($this->nextId++);
		$section->setRunId($runId);
		$section->setTitle('Section');
		$section->setDescription('');
		$section->setPosition($position);
		$this->runSections[$section->getId()] = $section;

		return $section;
	}

	/**
	 * @param array<string, mixed> $config
	 */
	protected function addRunStep(
		int $runSectionId,
		string $type,
		bool $required,
		string $status = RunStepStatus::Pending->value,
		int $position = 0,
		array $config = [],
		?string $assigneeType = null,
		?string $assigneeId = null,
		?int $dueAt = null,
	): RunStep {
		$step = new RunStep();
		$step->setId($this->nextId++);
		$step->setRunSectionId($runSectionId);
		$step->setUuid('00000000-0000-4000-8000-00000000000' . ($position % 10));
		$step->setTitle('Step');
		$step->setDescription('');
		$step->setType($type);
		$step->setRequired($required);
		$step->setPosition($position);
		$step->setConfigArray($config);
		$step->setStatus($status);
		$step->setAssigneeType($assigneeType);
		$step->setAssigneeId($assigneeId);
		$step->setDueAt($dueAt);
		$this->runSteps[$step->getId()] = $step;

		return $step;
	}

	protected function seedRunAcl(int $runId, string $principalType, string $principalId, string $role): RunAcl {
		$entry = new RunAcl();
		$entry->setId($this->nextId++);
		$entry->setRunId($runId);
		$entry->setPrincipalType($principalType);
		$entry->setPrincipalId($principalId);
		$entry->setRole($role);
		$entry->setCreatedAt($this->now);
		$entry->setUpdatedAt($this->now);
		$this->runAclEntries[$entry->getId()] = $entry;

		return $entry;
	}
}
