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

	// Global administration destination folder reference (#47). The reference is
	// persisted as ONE validated JSON value under a single key so that a save or
	// clear is a single atomic write and readers can never observe a mix of old
	// and new fields.
	public const KEY_DESTINATION_REFERENCE = 'destination_reference';

	// Deprecated (pre-single-key) four-key representation. It is still read for
	// compatibility with instances configured before the atomic representation
	// existed, and is migrated to KEY_DESTINATION_REFERENCE on the next write.
	public const KEY_DESTINATION_STORAGE_ID = 'destination_storage_id';
	public const KEY_DESTINATION_FILE_ID = 'destination_file_id';
	public const KEY_DESTINATION_PATH = 'destination_path';
	public const KEY_DESTINATION_CONFIGURED_BY = 'destination_configured_by';

	// Schema version embedded in the single-key reference value.
	private const DESTINATION_REFERENCE_VERSION = 1;

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
	 * Human/UI description of the global administration destination (#47).
	 *
	 * Never exposes internal identity (storage id / file id); only whether the
	 * setting is configured, whether the stored reference is complete, and the
	 * advisory display path plus the selecting administrator.
	 *
	 * @return array{configured: bool, valid: bool, path: string|null, configuredBy: string|null}
	 */
	public function describeDestination(): array {
		try {
			$reference = $this->readStoredReference();
		} catch (ValidationException) {
			return [
				'configured' => true,
				'valid' => false,
				'path' => null,
				'configuredBy' => null,
			];
		}

		if ($reference === null) {
			return [
				'configured' => false,
				'valid' => false,
				'path' => null,
				'configuredBy' => null,
			];
		}

		return [
			'configured' => true,
			'valid' => true,
			'path' => $reference->path,
			'configuredBy' => $reference->configuredBy,
		];
	}

	/**
	 * The stored global destination reference, or null when it is explicitly
	 * unset.
	 *
	 * The reference is read from a single authoritative value, so a concurrent
	 * update either yields the complete old value or the complete new value. A
	 * malformed, partial or internally conflicting stored configuration fails
	 * closed and is never returned as if it were unset.
	 *
	 * @throws ValidationException When the stored reference is corrupt.
	 */
	public function getDestinationReference(): ?DestinationReference {
		return $this->readStoredReference();
	}

	/**
	 * Persist the global destination reference.
	 *
	 * The value is written as one validated JSON value under a single key, so the
	 * replacement is atomic: a failed write leaves the previous reference intact.
	 * Any complete legacy four-key reference is migrated into the single key
	 * first, so a valid configured destination can never be observed as unset
	 * during the transition.
	 *
	 * @throws ValidationException When the captured identity is not complete.
	 */
	public function saveDestinationReference(string $storageId, int $fileId, ?string $path, string $configuredBy): DestinationReference {
		if ($storageId === '' || $fileId <= 0 || $configuredBy === '') {
			throw new ValidationException('destination_invalid_config');
		}

		$reference = new DestinationReference($storageId, $fileId, $path, $configuredBy);
		$app = Application::APP_ID;
		$this->consolidateLegacyRepresentation($app);
		$this->writeReference($app, $reference);

		return $reference;
	}

	/**
	 * Remove the global destination reference, restoring the explicit unset
	 * state (issue #46 default behaviour).
	 *
	 * The authoritative value is removed last, so a failure at any step leaves
	 * the previous reference in place.
	 */
	public function clearDestinationReference(): void {
		$app = Application::APP_ID;
		$this->consolidateLegacyRepresentation($app);
		$this->deleteReference($app);
	}

	/**
	 * Resolve the effective stored reference.
	 *
	 * @return DestinationReference|null null when explicitly unset.
	 *
	 * @throws ValidationException When the stored configuration is corrupt,
	 *                             partial or self-conflicting.
	 */
	private function readStoredReference(): ?DestinationReference {
		$app = Application::APP_ID;
		$newPresent = $this->config->hasKey($app, self::KEY_DESTINATION_REFERENCE);
		$legacy = $this->readLegacyReference($app);

		if (!$newPresent && $legacy === null) {
			return null;
		}

		if ($newPresent) {
			$reference = $this->parseReference($this->config->getValueString($app, self::KEY_DESTINATION_REFERENCE, ''));
			if ($reference === null) {
				// A present but unreadable/malformed authoritative value is never
				// ignored in favour of a possibly stale legacy value.
				throw new ValidationException('destination_invalid_config');
			}
			if ($legacy !== null && $legacy['complete']) {
				$legacyReference = $legacy['reference'];
				if ($legacyReference === null || !$this->sameReference($legacyReference, $reference)) {
					// Two complete but disagreeing representations are ambiguous.
					throw new ValidationException('destination_invalid_config');
				}
			}

			// A partial legacy value alongside a valid authoritative value is
			// migration/cleanup residue and is ignored.
			return $reference;
		}

		if ($legacy['complete']) {
			return $legacy['reference'];
		}

		throw new ValidationException('destination_invalid_config');
	}

	/**
	 * Read the deprecated four-key representation.
	 *
	 * @return array{complete: bool, reference: DestinationReference|null}|null
	 *                                                                          null when none of the legacy keys is present.
	 */
	private function readLegacyReference(string $app): ?array {
		$present = $this->config->hasKey($app, self::KEY_DESTINATION_STORAGE_ID)
			|| $this->config->hasKey($app, self::KEY_DESTINATION_FILE_ID)
			|| $this->config->hasKey($app, self::KEY_DESTINATION_PATH)
			|| $this->config->hasKey($app, self::KEY_DESTINATION_CONFIGURED_BY);
		if (!$present) {
			return null;
		}

		$storageId = $this->config->getValueString($app, self::KEY_DESTINATION_STORAGE_ID, '');
		$fileId = $this->config->getValueInt($app, self::KEY_DESTINATION_FILE_ID, 0);
		$path = $this->config->getValueString($app, self::KEY_DESTINATION_PATH, '');
		$configuredBy = $this->config->getValueString($app, self::KEY_DESTINATION_CONFIGURED_BY, '');

		if ($storageId === '' || $fileId <= 0 || $configuredBy === '') {
			return ['complete' => false, 'reference' => null];
		}

		return [
			'complete' => true,
			'reference' => new DestinationReference($storageId, $fileId, $path !== '' ? $path : null, $configuredBy),
		];
	}

	/**
	 * Parse and validate the single-key reference value.
	 */
	private function parseReference(string $raw): ?DestinationReference {
		if ($raw === '') {
			return null;
		}
		try {
			$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		if (!is_array($data)) {
			return null;
		}
		if (($data['v'] ?? null) !== self::DESTINATION_REFERENCE_VERSION) {
			return null;
		}

		$storageId = $data['storageId'] ?? null;
		$fileId = $data['fileId'] ?? null;
		$configuredBy = $data['configuredBy'] ?? null;
		$path = $data['path'] ?? null;

		if (!is_string($storageId) || $storageId === '') {
			return null;
		}
		if (!is_int($fileId) || $fileId <= 0) {
			return null;
		}
		if (!is_string($configuredBy) || $configuredBy === '') {
			return null;
		}
		if ($path !== null && !is_string($path)) {
			return null;
		}

		return new DestinationReference($storageId, $fileId, ($path === '' || $path === null) ? null : $path, $configuredBy);
	}

	private function sameReference(DestinationReference $a, DestinationReference $b): bool {
		return $a->storageId === $b->storageId
			&& $a->fileId === $b->fileId
			&& $a->configuredBy === $b->configuredBy
			&& ($a->path ?? '') === ($b->path ?? '');
	}

	/**
	 * Atomically write the authoritative single-key reference.
	 *
	 * @throws \RuntimeException When the configuration backend reports failure.
	 */
	private function writeReference(string $app, DestinationReference $reference): void {
		$encoded = json_encode([
			'v' => self::DESTINATION_REFERENCE_VERSION,
			'storageId' => $reference->storageId,
			'fileId' => $reference->fileId,
			'path' => $reference->path ?? '',
			'configuredBy' => $reference->configuredBy,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

		if ($this->config->setValueString($app, self::KEY_DESTINATION_REFERENCE, $encoded) === false) {
			throw new \RuntimeException('destination_persistence_failed');
		}
	}

	private function deleteReference(string $app): void {
		if ($this->config->hasKey($app, self::KEY_DESTINATION_REFERENCE)) {
			$this->config->deleteKey($app, self::KEY_DESTINATION_REFERENCE);
		}
	}

	/**
	 * Make the deprecated four-key representation disappear without ever leaving
	 * the logical value unset: the current value is first preserved under the
	 * authoritative key, then the legacy keys are removed. A failure aborts the
	 * caller while the previous reference is still stored.
	 */
	private function consolidateLegacyRepresentation(string $app): void {
		$legacy = $this->readLegacyReference($app);
		if ($legacy === null) {
			return;
		}

		if (!$this->config->hasKey($app, self::KEY_DESTINATION_REFERENCE)
			&& $legacy['complete']
			&& $legacy['reference'] !== null) {
			$this->writeReference($app, $legacy['reference']);
		}

		foreach ([
			self::KEY_DESTINATION_STORAGE_ID,
			self::KEY_DESTINATION_FILE_ID,
			self::KEY_DESTINATION_PATH,
			self::KEY_DESTINATION_CONFIGURED_BY,
		] as $key) {
			if ($this->config->hasKey($app, $key)) {
				$this->config->deleteKey($app, $key);
			}
		}
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
