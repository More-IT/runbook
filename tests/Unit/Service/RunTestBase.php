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
use OCA\Runbook\Db\FilesCleanup;
use OCA\Runbook\Db\FilesCleanupMapper;
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
use OCA\Runbook\Service\AttachmentReconciliationService;
use OCA\Runbook\Service\AttachmentService;
use OCA\Runbook\Service\CommentService;
use OCA\Runbook\Service\DueNotificationService;
use OCA\Runbook\Service\EvidenceLockService;
use OCA\Runbook\Service\EvidenceStorage;
use OCA\Runbook\Service\FilesAttachmentStorage;
use OCA\Runbook\Service\FilesCleanupService;
use OCA\Runbook\Service\FilesRootProvider;
use OCA\Runbook\Service\FileTypeDetector;
use OCA\Runbook\Service\FlowService;
use OCA\Runbook\Service\LegacyMigrationService;
use OCA\Runbook\Service\MentionService;
use OCA\Runbook\Service\NotificationService;
use OCA\Runbook\Service\PermissionService;
use OCA\Runbook\Service\PrincipalValidator;
use OCA\Runbook\Service\RunAccessService;
use OCA\Runbook\Service\RunAclService;
use OCA\Runbook\Service\RunDestinationResolver;
use OCA\Runbook\Service\RunService;
use OCA\Runbook\Service\RunStepService;
use OCA\Runbook\Service\StepResponseValidator;
use OCA\Runbook\Service\TemplateCreationPolicyService;
use OCA\Runbook\Service\TemplateDestinationService;
use OCA\Runbook\Service\TransactionRunner;
use OCA\Runbook\Service\UploadedFileReader;
use OCA\Runbook\Service\ValidationException;
use OCA\Runbook\Service\WorkService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Constants;
use OCP\Files\AlreadyExistsException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Node;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
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
	/** When true, attachment metadata updates fail (to test #55 resume). */
	protected bool $failAttachmentUpdate = false;
	/** When true, run metadata inserts fail (to test destination compensation). */
	protected bool $failRunInsert = false;
	/** When true, reading a legacy AppData source fails (#55). */
	protected bool $failAppDataRead = false;
	/** When true, deleting a migrated AppData source fails (#55). */
	protected bool $failAppDataDelete = false;

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
	/** @var FilesCleanupMapper&MockObject */
	protected FilesCleanupMapper $filesCleanupMapper;
	/** @var array<int, FilesCleanup> In-memory durable cleanup records (#54). */
	protected array $filesCleanups = [];
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
	protected FlowService $flowService;
	protected MentionService $mentionService;
	protected NotificationService $notificationService;
	/** @var array<string, int> */
	protected array $heldLocks = [];
	/** @var ILockingProvider&MockObject */
	protected ILockingProvider $lockingProvider;
	protected EvidenceLockService $evidenceLock;
	/** @var FilesRootProvider&MockObject */
	protected FilesRootProvider $filesRoot;
	/** @var Folder&MockObject */
	protected Folder $userFilesFolder;
	/** @var array<string, Node&MockObject> */
	protected array $userRootChildren = [];
	/** @var array<int, array<string, Node&MockObject>> */
	protected array $folderChildrenById = [];
	/** @var list<int> */
	protected array $deletedFolderIds = [];
	/** @var list<int> */
	protected array $deletedFileIds = [];
	/** @var array<int, Folder> Managed Files folders of runs created with addFilesRun(). */
	protected array $filesRunFolders = [];
	protected int $nextFileId = 1;

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

		$this->heldLocks = [];
		$this->lockingProvider = $this->createMock(ILockingProvider::class);
		$this->lockingProvider->method('acquireLock')->willReturnCallback(function (string $path, int $type): void {
			if (isset($this->heldLocks[$path])) {
				throw new LockedException($path);
			}
			$this->heldLocks[$path] = $type;
		});
		$this->lockingProvider->method('releaseLock')->willReturnCallback(function (string $path, int $type): void {
			unset($this->heldLocks[$path]);
		});
		$this->lockingProvider->method('isLocked')->willReturnCallback(
			fn (string $path, int $type): bool => isset($this->heldLocks[$path]),
		);
		// Short retry budget so contention tests stay fast.
		$this->evidenceLock = new EvidenceLockService($this->lockingProvider, 2, 0);

		// In-memory Files view: a view root whose direct children include the
		// default `Runbook` base folder (created on demand).
		$this->nextFileId = 1;
		$this->userRootChildren = [];
		$this->folderChildrenById = [];
		$this->deletedFolderIds = [];
		$this->deletedFileIds = [];
		$this->filesRunFolders = [];
		$this->filesRoot = $this->createMock(FilesRootProvider::class);
		$this->userFilesFolder = $this->makeFolderMock('/files', $this->nextFileId++, $this->userRootChildren);
		$this->filesRoot->method('getUserFolder')->willReturn($this->userFilesFolder);

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
		$this->configureFilesCleanupMapper();

		$this->permissionService = new PermissionService($this->aclMapper, $this->userManager, $this->groupManager);
		$this->validator = new StepResponseValidator($this->userManager);
		$this->principalValidator = new PrincipalValidator($this->userManager, $this->groupManager);
		$this->runAccess = new RunAccessService($this->runAclMapper, $this->runStepMapper, $this->userManager, $this->groupManager);
		$this->flowService = new FlowService();
		$this->mentionService = new MentionService($this->commentMentionMapper, $this->userManager, $this->timeFactory);

		$this->configureNotifications();
	}

	/**
	 * In-memory durable Files cleanup records (#54).
	 */
	private function configureFilesCleanupMapper(): void {
		$this->filesCleanups = [];
		$this->filesCleanupMapper = $this->createMock(FilesCleanupMapper::class);
		$this->filesCleanupMapper->method('insert')->willReturnCallback(function (FilesCleanup $cleanup): FilesCleanup {
			$cleanup->setId($this->nextId++);
			$this->filesCleanups[$cleanup->getId()] = $cleanup;

			return $cleanup;
		});
		$this->filesCleanupMapper->method('update')->willReturnCallback(function (FilesCleanup $cleanup): FilesCleanup {
			$this->filesCleanups[$cleanup->getId()] = $cleanup;

			return $cleanup;
		});
		$this->filesCleanupMapper->method('delete')->willReturnCallback(function (FilesCleanup $cleanup): FilesCleanup {
			unset($this->filesCleanups[$cleanup->getId()]);

			return $cleanup;
		});
		$this->filesCleanupMapper->method('find')->willReturnCallback(function (int $id): FilesCleanup {
			if (!isset($this->filesCleanups[$id])) {
				throw new DoesNotExistException('cleanup record not found');
			}

			return $this->filesCleanups[$id];
		});
		$this->filesCleanupMapper->method('findPending')->willReturnCallback(function (): array {
			$pending = array_values(array_filter(
				$this->filesCleanups,
				static fn (FilesCleanup $cleanup): bool => $cleanup->getStatus() === FilesCleanup::STATUS_PENDING,
			));
			usort($pending, static fn (FilesCleanup $a, FilesCleanup $b): int => [$a->getCreatedAt(), $a->getId()] <=> [$b->getCreatedAt(), $b->getId()]);

			return $pending;
		});
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
		$mock->method('hasKey')->willReturnCallback(
			fn (string $app, string $key, ?bool $lazy = false): bool => array_key_exists($key, $this->appConfigValues),
		);
		$mock->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->appConfigValues[$key]);
		});

		return $mock;
	}

	protected function setAppConfig(string $key, mixed $value): void {
		$this->appConfigValues[$key] = $value;
	}

	/**
	 * Configure the global administration destination reference (#47) in the
	 * current atomic single-key representation.
	 */
	protected function setAdminDestination(string $storageId, int $fileId, string $path, string $configuredBy): void {
		$this->setAppConfig(AdminSettings::KEY_DESTINATION_REFERENCE, json_encode([
			'v' => 1,
			'storageId' => $storageId,
			'fileId' => $fileId,
			'path' => $path,
			'configuredBy' => $configuredBy,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
	}

	/**
	 * Configure a template's destination reference (#48).
	 */
	protected function setTemplateDestination(Template $template, string $storageId, int $fileId, ?string $path, string $configuredBy): void {
		$template->setDestinationStorageId($storageId);
		$template->setDestinationFileId($fileId);
		$template->setDestinationPath($path);
		$template->setDestinationConfiguredBy($configuredBy);
	}

	/**
	 * Configure the destination using the deprecated four-key representation
	 * (compatibility coverage for pre-migration instances).
	 */
	protected function setLegacyAdminDestination(string $storageId, int $fileId, string $path, string $configuredBy): void {
		$this->setAppConfig(AdminSettings::KEY_DESTINATION_STORAGE_ID, $storageId);
		$this->setAppConfig(AdminSettings::KEY_DESTINATION_FILE_ID, $fileId);
		$this->setAppConfig(AdminSettings::KEY_DESTINATION_PATH, $path);
		$this->setAppConfig(AdminSettings::KEY_DESTINATION_CONFIGURED_BY, $configuredBy);
	}

	/**
	 * Add a top-level folder to the in-memory Files view.
	 */
	protected function addUserFolder(string $name, int $id, bool $creatable = true): Folder {
		$children = [];
		$folder = $this->makeFolderMock($name, $id, $children, $creatable);
		$this->userRootChildren[$name] = $folder;

		return $folder;
	}

	/**
	 * Add a top-level file to the in-memory Files view (issue #53 copy tests).
	 */
	protected function addUserFile(string $name, int $id, string $content = '', bool $readable = true): File {
		$file = $this->makeFileMock($name, $id, $this->userRootChildren, $content, true, true, $readable);
		$this->userRootChildren[$name] = $file;

		return $file;
	}

	/**
	 * A run with a frozen Files destination and an existing managed folder in the
	 * in-memory view (issue #50).
	 *
	 * @return array{0: Run, 1: Folder}
	 */
	protected function addFilesRun(string $owner, string $status = RunStatus::Active->value, bool $creatable = true, ?callable $newFile = null, bool $deletable = true): array {
		$run = $this->addRun($owner, $status);
		$children = [];
		$folder = $this->makeFolderMock('RunFolder', $this->nextFileId++, $children, $creatable, $deletable, null, $newFile);
		$this->userRootChildren['RunFolder'] = $folder;

		$run->setDestinationViewUid($owner);
		$run->setDestinationSource('default');
		$run->setDestinationStorageId('home::test');
		$run->setDestinationFileId($this->nextId++);
		$run->setRunFolderFileId($folder->getId());
		$run->setRunFolderStorageId('home::test');
		$this->runs[$run->getId()] = $run;
		$this->filesRunFolders[$run->getId()] = $folder;

		return [$run, $folder];
	}

	/**
	 * A Files run with one step of the given type assigned to the owner.
	 *
	 * @return array{0: Run, 1: RunStep, 2: Folder}
	 */
	protected function filesRunWithStepOfType(string $owner, string $type, bool $required, string $status = RunStepStatus::Pending->value): array {
		[$run, $folder] = $this->addFilesRun($owner);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), $type, $required, $status, 0, [], PrincipalType::User->value, $owner);

		return [$run, $step, $folder];
	}

	/**
	 * The managed Files folder of a run created with {@see addFilesRun()}.
	 */
	protected function filesFolder(Run $run): Folder {
		self::assertArrayHasKey($run->getId(), $this->filesRunFolders);

		return $this->filesRunFolders[$run->getId()];
	}

	/**
	 * Seed a pre-existing AppData evidence row (as written before the Files
	 * milestone) so compatibility behaviour can be tested without the upload path.
	 */
	protected function seedAppDataAttachment(Run $run, int $stepId, string $filename, string $content, string $uploaderUid): Attachment {
		$storageKey = str_pad(dechex(count($this->evidenceFiles) + 1), 32, '0', STR_PAD_LEFT);
		$this->evidenceStorage->write($run->getId(), $stepId, $storageKey, $content);

		$attachment = new Attachment();
		$attachment->setId($this->nextId++);
		$attachment->setUuid('00000000-0000-4000-8000-000000000000');
		$attachment->setRunId($run->getId());
		$attachment->setStepId($stepId);
		$attachment->setUploaderUid($uploaderUid);
		$attachment->setFilename($filename);
		$attachment->setStorageKind(Attachment::STORAGE_KIND_APPDATA);
		$attachment->setStorageKey($storageKey);
		$attachment->setMimeType('text/plain');
		$attachment->setSize(strlen($content));
		$attachment->setChecksum(hash('sha256', $content));
		$attachment->setCreatedAt($this->now);
		$this->attachments[$attachment->getId()] = $attachment;

		return $attachment;
	}

	/**
	 * Seed a pre-existing Files evidence row without going through the upload
	 * path (used to test reads/deletes after folder permissions change).
	 */
	protected function seedFilesAttachment(Run $run, int $stepId, File $file, string $filename, string $content, string $uploaderUid): Attachment {
		$attachment = new Attachment();
		$attachment->setId($this->nextId++);
		$attachment->setUuid('00000000-0000-4000-8000-000000000001');
		$attachment->setRunId($run->getId());
		$attachment->setStepId($stepId);
		$attachment->setUploaderUid($uploaderUid);
		$attachment->setFilename($filename);
		$attachment->setStorageKind(Attachment::STORAGE_KIND_FILES);
		$attachment->setStorageKey('');
		$attachment->setStorageId($file->getStorage()->getId());
		$attachment->setFileId($file->getId());
		$attachment->setMimeType('text/plain');
		$attachment->setSize(strlen($content));
		$attachment->setChecksum(hash('sha256', $content));
		$attachment->setCreatedAt($this->now);
		$this->attachments[$attachment->getId()] = $attachment;

		return $attachment;
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
			if ($this->failRunInsert) {
				throw new \OCP\DB\Exception('Simulated run insert failure');
			}
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
		$this->attachmentMapper->method('update')->willReturnCallback(function (Attachment $attachment): Attachment {
			if ($this->failAttachmentUpdate) {
				$this->failAttachmentUpdate = false;
				throw new \OCP\DB\Exception('Simulated metadata update failure');
			}
			$this->attachments[$attachment->getId()] = $attachment;

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
		$this->attachmentMapper->method('countByRunAndStep')->willReturnCallback(
			fn (int $runId, int $stepId): int => count(array_filter(
				$this->attachments,
				static fn (Attachment $attachment): bool => $attachment->getRunId() === $runId && $attachment->getStepId() === $stepId,
			)),
		);
		$this->attachmentMapper->method('findRunIdsByStorageKind')->willReturnCallback(function (string $storageKind, int $limit = 0): array {
			$ids = [];
			foreach ($this->attachments as $attachment) {
				if ($attachment->getStorageKind() === $storageKind) {
					$ids[$attachment->getRunId()] = true;
				}
			}
			$result = array_map(static fn (int $id): int => $id, array_keys($ids));
			sort($result);
			if ($limit > 0) {
				$result = array_slice($result, 0, $limit);
			}

			return $result;
		});
		$this->attachmentMapper->method('countByStorageKind')->willReturnCallback(
			fn (string $storageKind): int => count(array_filter(
				$this->attachments,
				static fn (Attachment $attachment): bool => $attachment->getStorageKind() === $storageKind,
			)),
		);
		$this->attachmentMapper->method('findRunIdsByMigrationReason')->willReturnCallback(function (string $reason): array {
			$ids = [];
			foreach ($this->attachments as $attachment) {
				if ($attachment->getMigrationReason() === $reason) {
					$ids[$attachment->getRunId()] = true;
				}
			}
			$result = array_map(static fn (int $id): int => $id, array_keys($ids));
			sort($result);

			return $result;
		});
		$this->attachmentMapper->method('countByMigrationReason')->willReturnCallback(
			fn (string $reason): int => count(array_filter(
				$this->attachments,
				static fn (Attachment $attachment): bool => $attachment->getMigrationReason() === $reason,
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
			if ($this->failAppDataRead) {
				throw new \RuntimeException('Simulated AppData read failure');
			}
			$key = $runId . ':' . $stepId . ':' . $storageKey;
			if (!isset($this->evidenceFiles[$key])) {
				throw new \OCP\Files\NotFoundException('evidence not found');
			}

			return $this->evidenceFiles[$key];
		});
		$this->evidenceStorage->method('delete')->willReturnCallback(function (int $runId, int $stepId, string $storageKey): void {
			if ($this->failAppDataDelete) {
				throw new \RuntimeException('Simulated AppData delete failure');
			}
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
		$user->method('isEnabled')->willReturn(true);

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

	/**
	 * @param array<string, Node&MockObject> $children
	 * @param (callable(string): Folder)|null $newFolder Optional replacement for
	 *                                                   folder creation (used to control the folder returned/injected).
	 * @param (callable(string, ?string): File)|null $newFile Optional replacement
	 *                                                        for file creation (used to simulate marker-write failures).
	 * @return Folder&MockObject
	 */
	protected function makeFolderMock(
		string $path,
		int $id,
		array &$children,
		bool $creatable = true,
		bool $deletable = true,
		?callable $newFolder = null,
		?callable $newFile = null,
	): Folder {
		$this->folderChildrenById[$id] = &$children;
		/** @var Folder&MockObject $folder */
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getPath')->willReturn($path);
		$folder->method('getName')->willReturn(basename($path));
		$folder->method('getRelativePath')->willReturnCallback(static function (string $nodePath): string {
			return str_starts_with($nodePath, '/') ? $nodePath : '/' . $nodePath;
		});
		$folder->method('getStorage')->willReturn($this->makeStorageMock());
		$folder->method('getMountPoint')->willReturn($this->makeMountMock());
		$folder->method('isCreatable')->willReturn($creatable);
		$folder->method('isDeletable')->willReturn($deletable);
		$folder->method('getPermissions')->willReturn($creatable ? Constants::PERMISSION_ALL : Constants::PERMISSION_READ);
		$folder->method('verifyPath')->willReturnCallback(static function (): void {
		});
		$folder->method('getDirectoryListing')->willReturnCallback(function () use (&$children): array {
			return array_values($children);
		});
		$folder->method('nodeExists')->willReturnCallback(function (string $name) use (&$children): bool {
			return isset($children[$name]);
		});
		$folder->method('get')->willReturnCallback(function (string $name) use (&$children): Node {
			if (!isset($children[$name])) {
				throw new FilesNotFoundException($name);
			}

			return $children[$name];
		});
		$folder->method('getById')->willReturnCallback(function (int $childId) use (&$children): array {
			return $this->findByIdInChildren($children, $childId);
		});
		$folder->method('newFolder')->willReturnCallback($newFolder ?? function (string $name) use (&$children): Folder {
			if (isset($children[$name])) {
				throw new AlreadyExistsException($name);
			}
			$childChildren = [];
			$child = $this->makeFolderMock($name, $this->nextFileId++, $childChildren);
			$children[$name] = $child;

			return $child;
		});
		$folder->method('newFile')->willReturnCallback($newFile ?? function (string $name, ?string $content = null) use (&$children): File {
			if (isset($children[$name])) {
				throw new AlreadyExistsException($name);
			}
			$child = $this->makeFileMock($name, $this->nextFileId++, $children, $content ?? '');
			$children[$name] = $child;

			return $child;
		});
		$folder->method('getNonExistingName')->willReturnCallback(function (string $name) use (&$children): string {
			$candidate = $name;
			$index = 1;
			while (isset($children[$candidate])) {
				$index++;
				$candidate = $name . ' (' . $index . ')';
			}

			return $candidate;
		});
		$folder->method('isSubNode')->willReturnCallback(function (Node $node) use (&$children): bool {
			return $this->childrenContainId($children, (int)$node->getId());
		});
		$folder->method('delete')->willReturnCallback(function () use (&$children, $path, $id): void {
			$this->deletedFolderIds[] = $id;
			unset($children[basename($path)]);
		});

		return $folder;
	}

	/**
	 * Recursively find nodes by id in an in-memory child map.
	 *
	 * @param array<string, Node&MockObject> $children
	 * @return list<Node&MockObject>
	 */
	private function findByIdInChildren(array $children, int $id): array {
		$result = [];
		foreach ($children as $child) {
			$childId = (int)$child->getId();
			if ($childId === $id) {
				$result[] = $child;
			}
			if (isset($this->folderChildrenById[$childId])) {
				foreach ($this->findByIdInChildren($this->folderChildrenById[$childId], $id) as $nested) {
					$result[] = $nested;
				}
			}
		}

		return $result;
	}

	/**
	 * Recursively test whether an in-memory child map contains a node id.
	 *
	 * @param array<string, Node&MockObject> $children
	 */
	private function childrenContainId(array $children, int $id): bool {
		foreach ($children as $child) {
			$childId = (int)$child->getId();
			if ($childId === $id) {
				return true;
			}
			if (isset($this->folderChildrenById[$childId]) && $this->childrenContainId($this->folderChildrenById[$childId], $id)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, Node&MockObject> $children The parent's child map, so
	 *                                                 `delete()` removes this file from the in-memory tree.
	 * @return File&MockObject
	 */
	protected function makeFileMock(string $path, int $id, array &$children, string $content = '', bool $creatable = true, bool $deletable = true, bool $readable = true): File {
		/** @var File&MockObject $file */
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn($path);
		$file->method('getName')->willReturn(basename($path));
		$file->method('getStorage')->willReturn($this->makeStorageMock());
		$file->method('getMountPoint')->willReturn($this->makeMountMock());
		$file->method('isCreatable')->willReturn($creatable);
		$file->method('isDeletable')->willReturn($deletable);
		$file->method('isReadable')->willReturn($readable);
		$file->method('getPermissions')->willReturn($readable ? Constants::PERMISSION_ALL : Constants::PERMISSION_READ);
		$file->method('getSize')->willReturnCallback(function () use (&$content): int {
			return strlen($content);
		});
		$file->method('getContent')->willReturnCallback(function () use (&$content): string {
			return $content;
		});
		$file->method('putContent')->willReturnCallback(function (string $data) use (&$content): void {
			$content = $data;
		});
		$file->method('delete')->willReturnCallback(function () use (&$children, $path, $id): void {
			$this->deletedFileIds[] = $id;
			unset($children[basename($path)]);
		});

		return $file;
	}

	private function makeStorageMock(): IStorage {
		/** @var IStorage&MockObject $storage */
		$storage = $this->createMock(IStorage::class);
		$storage->method('getId')->willReturn('home::test');
		$storage->method('getOwner')->willReturn(false);

		return $storage;
	}

	private function makeMountMock(): IMountPoint {
		/** @var IMountPoint&MockObject $mount */
		$mount = $this->createMock(IMountPoint::class);
		$mount->method('getStorageRootId')->willReturn(7);
		$mount->method('getMountType')->willReturn('local');
		$mount->method('getMountProvider')->willReturn('OC\\Files\\Mount\\LocalHomeMountProvider');
		$mount->method('getMountId')->willReturn(null);
		$mount->method('getNumericStorageId')->willReturn(1);
		$mount->method('getMountPoint')->willReturn('/files/');

		return $mount;
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
			$this->attachmentMapper,
			$this->evidenceStorage,
			$this->filesAttachmentStorage(),
			$this->attachmentReconciliationService(),
			$this->groupManager,
			$this->flowService,
			$this->runDestinationResolver(),
			$this->templateDestinationService(),
			$this->filesCleanupService(),
		);
	}

	protected function runDestinationResolver(): RunDestinationResolver {
		return new RunDestinationResolver(
			$this->filesRoot,
			$this->lockingProvider,
			$this->userManager,
			$this->adminSettings,
			new NullLogger(),
		);
	}

	protected function templateDestinationService(): TemplateDestinationService {
		return new TemplateDestinationService($this->runDestinationResolver());
	}

	/**
	 * Files-backed evidence storage using the in-memory Files view (#50).
	 */
	protected function filesAttachmentStorage(): FilesAttachmentStorage {
		return new FilesAttachmentStorage(
			$this->runDestinationResolver(),
			$this->attachmentReconciliationService(),
			new NullLogger(),
		);
	}

	/**
	 * Out-of-band Files reconciliation using the in-memory Files view (#52).
	 */
	protected function attachmentReconciliationService(): AttachmentReconciliationService {
		return new AttachmentReconciliationService($this->filesRoot, $this->attachmentMapper, new NullLogger());
	}

	/**
	 * Legacy AppData → Files migration using the in-memory doubles (#55).
	 */
	protected function legacyMigrationService(): LegacyMigrationService {
		return new LegacyMigrationService(
			$this->attachmentMapper,
			$this->runMapper,
			$this->templateMapper,
			$this->templateDestinationService(),
			$this->evidenceStorage,
			$this->filesAttachmentStorage(),
			$this->runDestinationResolver(),
			$this->timeFactory,
			$this->appConfig,
			$this->lockingProvider,
			new NullLogger(),
		);
	}

	/**
	 * Durable managed-folder cleanup using the in-memory Files view (#54).
	 */
	protected function filesCleanupService(): FilesCleanupService {
		return new FilesCleanupService(
			$this->filesCleanupMapper,
			$this->runDestinationResolver(),
			$this->timeFactory,
			new NullLogger(),
		);
	}

	/**
	 * The managed base folder (`Files/Runbook`) created by the resolver.
	 */
	protected function baseFolder(): Folder {
		self::assertArrayHasKey('Runbook', $this->userRootChildren);
		$folder = $this->userRootChildren['Runbook'];
		self::assertInstanceOf(Folder::class, $folder);

		return $folder;
	}

	protected function runStepServiceFor(string $uid): RunStepService {
		return new RunStepService(
			$this->runMapper,
			$this->runSectionMapper,
			$this->runStepMapper,
			$this->attachmentMapper,
			$this->attachmentReconciliationService(),
			$this->evidenceLock,
			$this->runAccess,
			$this->principalValidator,
			$this->validator,
			$this->activityServiceFor($uid),
			$this->notificationService,
			$this->adminSettings,
			$this->sessionFor($uid),
			$this->timeFactory,
			$this->flowService,
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
			$this->evidenceLock,
			$this->runAccess,
			$this->evidenceStorage,
			$this->filesAttachmentStorage(),
			$this->runDestinationResolver(),
			$this->attachmentReconciliationService(),
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
			$this->flowService,
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

	/**
	 * Seed a persisted evidence attachment directly (bypassing upload rules) so
	 * tests can exercise steps whose run state forbids a fresh upload.
	 */
	protected function addAttachment(int $runId, int $stepId, string $uploaderUid, string $filename = 'evidence.txt'): Attachment {
		$attachment = new Attachment();
		$attachment->setId($this->nextId++);
		$attachment->setUuid('00000000-0000-4000-8000-000000000000');
		$attachment->setRunId($runId);
		$attachment->setStepId($stepId);
		$attachment->setUploaderUid($uploaderUid);
		$attachment->setFilename($filename);
		$attachment->setStorageKey('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
		$attachment->setMimeType('text/plain');
		$attachment->setSize(1);
		$attachment->setChecksum(hash('sha256', 'x'));
		$attachment->setCreatedAt($this->now);
		$this->attachments[$attachment->getId()] = $attachment;

		return $attachment;
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
