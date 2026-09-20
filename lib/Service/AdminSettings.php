<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\AppInfo\Application;
use OCA\Runbook\Enum\TemplateCreationPolicy;
use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * Central access point for the Runbook administration settings.
 *
 * All configuration keys, defaults, bounds and normalization rules live here so
 * the rest of the codebase never touches raw keys. Values are stored through the
 * typed Nextcloud app configuration API, which caches values internally and
 * keeps changes effective without a process restart.
 */
class AdminSettings {
	// Configuration keys.
	public const KEY_TEMPLATE_CREATION_POLICY = 'template_creation_policy';
	public const KEY_TEMPLATE_CREATOR_GROUPS = 'template_creator_groups';
	public const KEY_COMMENTS_ENABLED = 'comments_enabled';
	public const KEY_STEP_REOPEN_ENABLED = 'step_reopen_enabled';
	public const KEY_REQUIRE_SKIP_REASON = 'require_skip_reason';
	public const KEY_RUN_REOPEN_ENABLED = 'run_reopen_enabled';
	public const KEY_MAX_ATTACHMENT_SIZE = 'max_attachment_size';
	public const KEY_DASHBOARD_ENABLED = 'dashboard_enabled';
	public const KEY_NOTIFICATIONS_ENABLED = 'notifications_enabled';
	public const KEY_SEARCH_ENABLED = 'search_enabled';
	public const KEY_RETENTION_DAYS = 'retention_days';

	// Defaults.
	public const DEFAULT_TEMPLATE_CREATION_POLICY = TemplateCreationPolicy::Everyone->value;
	public const DEFAULT_MAX_ATTACHMENT_SIZE = 26214400;

	// Bounds.
	public const MIN_ATTACHMENT_SIZE = 1024;
	/** Hard safety upper bound, independent of the configured value. */
	public const MAX_ATTACHMENT_SIZE = 1073741824;
	public const MAX_RETENTION_DAYS = 36500;
	private const MAX_GROUP_LIST = 200;
	private const MAX_GROUP_ID_LENGTH = 255;

	public function __construct(
		private readonly IAppConfig $config,
		private readonly IGroupManager $groupManager,
	) {
	}

	/**
	 * Full default configuration, used when nothing is stored yet.
	 *
	 * @return array{
	 *     templateCreationPolicy: string,
	 *     templateCreatorGroups: list<string>,
	 *     commentsEnabled: bool,
	 *     stepReopenEnabled: bool,
	 *     requireSkipReason: bool,
	 *     runReopenEnabled: bool,
	 *     maxAttachmentSize: int,
	 *     dashboardEnabled: bool,
	 *     notificationsEnabled: bool,
	 *     searchEnabled: bool,
	 *     retentionDays: int
	 * }
	 */
	public function getDefaults(): array {
		return [
			'templateCreationPolicy' => self::DEFAULT_TEMPLATE_CREATION_POLICY,
			'templateCreatorGroups' => [],
			'commentsEnabled' => true,
			'stepReopenEnabled' => true,
			'requireSkipReason' => true,
			'runReopenEnabled' => true,
			'maxAttachmentSize' => self::DEFAULT_MAX_ATTACHMENT_SIZE,
			'dashboardEnabled' => true,
			'notificationsEnabled' => true,
			'searchEnabled' => true,
			'retentionDays' => 0,
		];
	}

	/**
	 * Effective settings with defaults applied.
	 *
	 * @return array{
	 *     templateCreationPolicy: string,
	 *     templateCreatorGroups: list<string>,
	 *     commentsEnabled: bool,
	 *     stepReopenEnabled: bool,
	 *     requireSkipReason: bool,
	 *     runReopenEnabled: bool,
	 *     maxAttachmentSize: int,
	 *     dashboardEnabled: bool,
	 *     notificationsEnabled: bool,
	 *     searchEnabled: bool,
	 *     retentionDays: int
	 * }
	 */
	public function getAll(): array {
		return [
			'templateCreationPolicy' => $this->getTemplateCreationPolicy()->value,
			'templateCreatorGroups' => $this->getTemplateCreatorGroups(),
			'commentsEnabled' => $this->isCommentsEnabled(),
			'stepReopenEnabled' => $this->isStepReopenEnabled(),
			'requireSkipReason' => $this->isSkipReasonRequired(),
			'runReopenEnabled' => $this->isRunReopenEnabled(),
			'maxAttachmentSize' => $this->getMaxAttachmentSize(),
			'dashboardEnabled' => $this->isDashboardEnabled(),
			'notificationsEnabled' => $this->isNotificationsEnabled(),
			'searchEnabled' => $this->isSearchEnabled(),
			'retentionDays' => $this->getRetentionDays(),
		];
	}

	/**
	 * Validation bounds exposed to the settings UI.
	 *
	 * @return array{minAttachmentSize: int, maxAttachmentSize: int, maxRetentionDays: int}
	 */
	public function getBounds(): array {
		return [
			'minAttachmentSize' => self::MIN_ATTACHMENT_SIZE,
			'maxAttachmentSize' => self::MAX_ATTACHMENT_SIZE,
			'maxRetentionDays' => self::MAX_RETENTION_DAYS,
		];
	}

	public function getTemplateCreationPolicy(): TemplateCreationPolicy {
		$value = $this->config->getValueString(
			Application::APP_ID,
			self::KEY_TEMPLATE_CREATION_POLICY,
			self::DEFAULT_TEMPLATE_CREATION_POLICY,
		);

		return TemplateCreationPolicy::tryFrom($value) ?? TemplateCreationPolicy::Everyone;
	}

	/**
	 * @return list<string>
	 */
	public function getTemplateCreatorGroups(): array {
		$value = $this->config->getValueArray(Application::APP_ID, self::KEY_TEMPLATE_CREATOR_GROUPS, []);

		return $this->normalizeIds($value);
	}

	public function isCommentsEnabled(): bool {
		return $this->config->getValueBool(Application::APP_ID, self::KEY_COMMENTS_ENABLED, true);
	}

	public function isStepReopenEnabled(): bool {
		return $this->config->getValueBool(Application::APP_ID, self::KEY_STEP_REOPEN_ENABLED, true);
	}

	public function isSkipReasonRequired(): bool {
		return $this->config->getValueBool(Application::APP_ID, self::KEY_REQUIRE_SKIP_REASON, true);
	}

	public function isRunReopenEnabled(): bool {
		return $this->config->getValueBool(Application::APP_ID, self::KEY_RUN_REOPEN_ENABLED, true);
	}

	public function isDashboardEnabled(): bool {
		return $this->config->getValueBool(Application::APP_ID, self::KEY_DASHBOARD_ENABLED, true);
	}

	public function isNotificationsEnabled(): bool {
		return $this->config->getValueBool(Application::APP_ID, self::KEY_NOTIFICATIONS_ENABLED, true);
	}

	public function isSearchEnabled(): bool {
		return $this->config->getValueBool(Application::APP_ID, self::KEY_SEARCH_ENABLED, true);
	}

	/**
	 * Configured maximum attachment size in bytes, clamped to the hard bounds.
	 */
	public function getMaxAttachmentSize(): int {
		$value = $this->config->getValueInt(
			Application::APP_ID,
			self::KEY_MAX_ATTACHMENT_SIZE,
			self::DEFAULT_MAX_ATTACHMENT_SIZE,
		);

		return max(self::MIN_ATTACHMENT_SIZE, min(self::MAX_ATTACHMENT_SIZE, $value));
	}

	public function getRetentionDays(): int {
		$value = $this->config->getValueInt(Application::APP_ID, self::KEY_RETENTION_DAYS, 0);

		return max(0, min(self::MAX_RETENTION_DAYS, $value));
	}

	/**
	 * Validate and persist the given settings.
	 *
	 * @param array<string, mixed> $input
	 * @return array{
	 *     templateCreationPolicy: string,
	 *     templateCreatorGroups: list<string>,
	 *     commentsEnabled: bool,
	 *     stepReopenEnabled: bool,
	 *     requireSkipReason: bool,
	 *     runReopenEnabled: bool,
	 *     maxAttachmentSize: int,
	 *     dashboardEnabled: bool,
	 *     notificationsEnabled: bool,
	 *     searchEnabled: bool,
	 *     retentionDays: int
	 * }
	 */
	public function update(array $input): array {
		$policy = $this->validatePolicy($input['templateCreationPolicy'] ?? null);
		$groups = $this->validateGroups($input['templateCreatorGroups'] ?? null);
		$comments = $this->validateBool($input['commentsEnabled'] ?? null);
		$stepReopen = $this->validateBool($input['stepReopenEnabled'] ?? null);
		$skipReason = $this->validateBool($input['requireSkipReason'] ?? null);
		$runReopen = $this->validateBool($input['runReopenEnabled'] ?? null);
		$maxSize = $this->validateAttachmentSize($input['maxAttachmentSize'] ?? null);
		$dashboard = $this->validateBool($input['dashboardEnabled'] ?? null);
		$notifications = $this->validateBool($input['notificationsEnabled'] ?? null);
		$search = $this->validateBool($input['searchEnabled'] ?? null);
		$retention = $this->validateRetention($input['retentionDays'] ?? null);

		$app = Application::APP_ID;
		$this->config->setValueString($app, self::KEY_TEMPLATE_CREATION_POLICY, $policy->value);
		$this->config->setValueArray($app, self::KEY_TEMPLATE_CREATOR_GROUPS, $groups);
		$this->config->setValueBool($app, self::KEY_COMMENTS_ENABLED, $comments);
		$this->config->setValueBool($app, self::KEY_STEP_REOPEN_ENABLED, $stepReopen);
		$this->config->setValueBool($app, self::KEY_REQUIRE_SKIP_REASON, $skipReason);
		$this->config->setValueBool($app, self::KEY_RUN_REOPEN_ENABLED, $runReopen);
		$this->config->setValueInt($app, self::KEY_MAX_ATTACHMENT_SIZE, $maxSize);
		$this->config->setValueBool($app, self::KEY_DASHBOARD_ENABLED, $dashboard);
		$this->config->setValueBool($app, self::KEY_NOTIFICATIONS_ENABLED, $notifications);
		$this->config->setValueBool($app, self::KEY_SEARCH_ENABLED, $search);
		$this->config->setValueInt($app, self::KEY_RETENTION_DAYS, $retention);

		return $this->getAll();
	}

	private function validatePolicy(mixed $value): TemplateCreationPolicy {
		if (!is_string($value)) {
			throw new ValidationException('invalid_template_creation_policy');
		}

		return TemplateCreationPolicy::tryFrom($value)
			?? throw new ValidationException('invalid_template_creation_policy');
	}

	/**
	 * @return list<string>
	 */
	private function validateGroups(mixed $value): array {
		if (!is_array($value)) {
			throw new ValidationException('invalid_template_creator_groups');
		}
		if (count($value) > self::MAX_GROUP_LIST) {
			throw new ValidationException('too_many_template_creator_groups');
		}

		$groups = [];
		foreach ($value as $entry) {
			if (!is_string($entry)) {
				throw new ValidationException('invalid_template_creator_groups');
			}
			$id = trim($entry);
			if ($id === '' || mb_strlen($id) > self::MAX_GROUP_ID_LENGTH) {
				throw new ValidationException('invalid_template_creator_groups');
			}
			if (!$this->groupManager->groupExists($id)) {
				throw new ValidationException('unknown_group');
			}
			$groups[$id] = true;
		}

		return array_keys($groups);
	}

	private function validateBool(mixed $value): bool {
		if (!is_bool($value)) {
			throw new ValidationException('invalid_boolean');
		}

		return $value;
	}

	private function validateAttachmentSize(mixed $value): int {
		if (!is_int($value)) {
			throw new ValidationException('invalid_attachment_size');
		}
		if ($value < self::MIN_ATTACHMENT_SIZE || $value > self::MAX_ATTACHMENT_SIZE) {
			throw new ValidationException('invalid_attachment_size');
		}

		return $value;
	}

	private function validateRetention(mixed $value): int {
		if (!is_int($value)) {
			throw new ValidationException('invalid_retention_days');
		}
		if ($value < 0 || $value > self::MAX_RETENTION_DAYS) {
			throw new ValidationException('invalid_retention_days');
		}

		return $value;
	}

	/**
	 * @param array<array-key, mixed> $values
	 * @return list<string>
	 */
	private function normalizeIds(array $values): array {
		$ids = [];
		foreach ($values as $value) {
			if (!is_string($value)) {
				continue;
			}
			$id = trim($value);
			if ($id !== '') {
				$ids[$id] = true;
			}
		}

		return array_keys($ids);
	}
}
