<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\Template;
use OCA\Runbook\Db\TemplateAclMapper;
use OCA\Runbook\Db\TemplateMapper;
use OCA\Runbook\Db\TemplateSection;
use OCA\Runbook\Db\TemplateSectionMapper;
use OCA\Runbook\Db\TemplateStep;
use OCA\Runbook\Db\TemplateStepMapper;
use OCA\Runbook\Enum\StepType;
use OCA\Runbook\Enum\TemplateStatus;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

/**
 * Business logic for template authoring.
 *
 * All authorization is enforced here, never in the frontend. Effective access
 * is resolved through {@see PermissionService}: owners have full control, and
 * users reached through direct or group ACL entries get their strongest role.
 */
class TemplateService {
	private const MAX_TITLE_LENGTH = 255;
	private const MAX_DESCRIPTION_LENGTH = 10000;
	private const MAX_NOTES_LENGTH = 10000;
	private const MAX_ASSIGNEE_LENGTH = 255;
	private const MAX_DUE_OFFSET_MINUTES = 525600;
	private const MAX_CONFIG_LENGTH = 60000;

	public function __construct(
		private readonly TemplateMapper $templates,
		private readonly TemplateSectionMapper $sections,
		private readonly TemplateStepMapper $steps,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $timeFactory,
		private readonly ISecureRandom $secureRandom,
		private readonly TemplateAclMapper $aclMapper,
		private readonly PermissionService $permissionService,
		private readonly TemplateCreationPolicyService $creationPolicy,
	) {
	}

	/**
	 * Templates the current user can access: owned templates plus templates
	 * shared through a direct user or group ACL entry.
	 *
	 * @return list<Template>
	 */
	public function listTemplates(): array {
		$uid = $this->currentUserId();
		$groupIds = $this->permissionService->getUserGroupIds($uid);
		$templateIds = $this->aclMapper->findTemplateIdsForPrincipal($uid, $groupIds);

		return $this->templates->findAccessible($uid, $templateIds);
	}

	/**
	 * Effective permissions of the current user on a template.
	 *
	 * @return array{role: string|null, canView: bool, canExecute: bool, canEdit: bool, canManageAcl: bool, canDelete: bool}
	 */
	public function getPermissions(Template $template): array {
		$role = $this->permissionService->getEffectiveRole($template, $this->currentUserId());
		$archived = $template->getStatus() === TemplateStatus::Archived->value;

		return [
			'role' => $role?->value,
			'canView' => $role !== null,
			'canExecute' => $role !== null && $role->canExecute(),
			'canEdit' => $role !== null && $role->canEdit() && !$archived,
			'canManageAcl' => $role !== null && $role->canManageAcl() && !$archived,
			'canDelete' => $role !== null && $role->canDelete(),
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function createTemplate(array $data): Template {
		$owner = $this->currentUserId();
		if (!$this->creationPolicy->canCreate($owner)) {
			throw new ForbiddenException('template_creation_forbidden');
		}

		$title = $this->normalizeTitle($this->readString($data, 'title') ?? '');
		$description = $this->normalizeDescription($this->readString($data, 'description') ?? '');
		$now = $this->timeFactory->getTime();

		$template = new Template();
		$template->setUuid($this->generateUuid());
		$template->setTitle($title);
		$template->setDescription($description);
		$template->setVersion(1);
		$template->setStatus(TemplateStatus::Draft->value);
		$template->setOwner($owner);
		$template->setCreatedAt($now);
		$template->setUpdatedAt($now);

		return $this->templates->insert($template);
	}

	public function getTemplate(int $id): Template {
		$template = $this->loadTemplate($id);
		$this->assertCanView($template);

		return $template;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function updateTemplate(int $id, array $data): Template {
		$template = $this->loadTemplate($id);
		$this->assertCanEditContent($template);

		$changed = false;

		$title = $this->readString($data, 'title');
		if ($title !== null) {
			$title = $this->normalizeTitle($title);
			if ($title !== $template->getTitle()) {
				$template->setTitle($title);
				$changed = true;
			}
		}

		$description = $this->readString($data, 'description');
		if ($description !== null) {
			$description = $this->normalizeDescription($description);
			if ($description !== $template->getDescription()) {
				$template->setDescription($description);
				$changed = true;
			}
		}

		if ($changed) {
			$this->touch($template);
		}

		return $template;
	}

	public function deleteTemplate(int $id): void {
		$template = $this->requireOwnerTemplate($id);
		$this->templates->delete($template);
	}

	public function publishTemplate(int $id): Template {
		$template = $this->requireOwnerTemplate($id);

		if ($template->getStatus() === TemplateStatus::Archived->value) {
			throw new ConflictException('template_archived');
		}

		if ($template->getStatus() === TemplateStatus::Published->value) {
			return $template;
		}

		if (trim($template->getTitle()) === '') {
			throw new ValidationException('template_title_required');
		}

		$now = $this->timeFactory->getTime();
		$template->setStatus(TemplateStatus::Published->value);
		$template->setPublishedAt($now);
		if ($template->getVersion() < 1) {
			$template->setVersion(1);
		}
		$template->setUpdatedAt($now);

		return $this->templates->update($template);
	}

	public function archiveTemplate(int $id): Template {
		$template = $this->requireOwnerTemplate($id);

		if ($template->getStatus() === TemplateStatus::Archived->value) {
			throw new ConflictException('template_already_archived');
		}

		$now = $this->timeFactory->getTime();
		$template->setStatus(TemplateStatus::Archived->value);
		$template->setArchivedAt($now);
		$template->setUpdatedAt($now);

		return $this->templates->update($template);
	}

	/**
	 * @return list<TemplateSection>
	 */
	public function getSections(int $templateId): array {
		$template = $this->loadTemplate($templateId);
		$this->assertCanView($template);

		return $this->sections->findByTemplate($templateId);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function createSection(int $templateId, array $data): TemplateSection {
		$template = $this->loadTemplate($templateId);
		$this->assertCanEditContent($template);

		$title = $this->normalizeTitle($this->requireString($data, 'title', 'section_title_required'));
		$description = $this->normalizeDescription($this->readString($data, 'description') ?? '');
		$notes = $this->normalizeNotes($this->readString($data, 'notes') ?? '');

		$section = new TemplateSection();
		$section->setTemplateId($templateId);
		$section->setTitle($title);
		$section->setDescription($description);
		$section->setNotes($notes);
		$section->setPosition(count($this->sections->findByTemplate($templateId)));

		$section = $this->sections->insert($section);
		$this->touch($template);

		return $section;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function updateSection(int $id, array $data): TemplateSection {
		$section = $this->requireSection($id);
		$template = $this->loadTemplate($section->getTemplateId());
		$this->assertCanEditContent($template);

		$changed = false;

		$title = $this->readString($data, 'title');
		if ($title !== null) {
			$title = $this->normalizeTitle($this->requireString($data, 'title', 'section_title_required'));
			if ($title !== $section->getTitle()) {
				$section->setTitle($title);
				$changed = true;
			}
		}

		$description = $this->readString($data, 'description');
		if ($description !== null) {
			$description = $this->normalizeDescription($description);
			if ($description !== $section->getDescription()) {
				$section->setDescription($description);
				$changed = true;
			}
		}

		$notes = $this->readString($data, 'notes');
		if ($notes !== null) {
			$notes = $this->normalizeNotes($notes);
			if ($notes !== $section->getNotes()) {
				$section->setNotes($notes);
				$changed = true;
			}
		}

		if ($changed) {
			$section = $this->sections->update($section);
			$this->touch($template);
		}

		return $section;
	}

	public function deleteSection(int $id): void {
		$section = $this->requireSection($id);
		$template = $this->loadTemplate($section->getTemplateId());
		$this->assertCanEditContent($template);

		$templateId = $section->getTemplateId();
		$this->sections->delete($section);
		$this->normalizeSectionPositions($templateId);
		$this->touch($template);
	}

	/**
	 * Move a section to a new zero-based position within its template.
	 *
	 * @return list<TemplateSection>
	 */
	public function reorderSection(int $id, int $position): array {
		$section = $this->requireSection($id);
		$template = $this->loadTemplate($section->getTemplateId());
		$this->assertCanEditContent($template);

		if ($position < 0) {
			throw new ValidationException('invalid_position');
		}

		$templateId = $section->getTemplateId();
		$siblings = $this->sections->findByTemplate($templateId);
		$remaining = array_values(array_filter(
			$siblings,
			static fn (TemplateSection $candidate): bool => $candidate->getId() !== $section->getId(),
		));

		if ($position > count($remaining)) {
			throw new ValidationException('invalid_position');
		}

		array_splice($remaining, $position, 0, [$section]);

		$changed = false;
		foreach ($remaining as $index => $item) {
			if ($item->getPosition() !== $index) {
				$item->setPosition($index);
				$this->sections->update($item);
				$changed = true;
			}
		}

		// Reordering to the current position changes nothing and must not touch
		// the template or bump the version of a published template.
		if ($changed) {
			$this->touch($template);
		}

		return $remaining;
	}

	/**
	 * @return list<TemplateStep>
	 */
	public function getSteps(int $sectionId): array {
		$section = $this->requireSection($sectionId);
		$template = $this->loadTemplate($section->getTemplateId());
		$this->assertCanView($template);

		return $this->steps->findBySection($sectionId);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function createStep(int $sectionId, array $data): TemplateStep {
		$section = $this->requireSection($sectionId);
		$template = $this->loadTemplate($section->getTemplateId());
		$this->assertCanEditContent($template);

		$type = $this->parseStepType($this->readString($data, 'type'));
		$config = $this->validateConfig($type, $this->readArray($data, 'config') ?? []);

		$step = new TemplateStep();
		$step->setSectionId($sectionId);
		$step->setUuid($this->generateUuid());
		$step->setTitle($this->normalizeTitle($this->requireString($data, 'title', 'step_title_required')));
		$step->setDescription($this->normalizeDescription($this->readString($data, 'description') ?? ''));
		$step->setType($type->value);
		$step->setRequired($this->readBool($data, 'required') ?? false);
		$step->setPosition(count($this->steps->findBySection($sectionId)));
		$step->setConfigArray($config);
		$step->setDefaultAssignee($this->normalizeAssignee($this->readString($data, 'defaultAssignee')));
		$step->setDueOffset($this->normalizeDueOffset($this->readString($data, 'dueOffset')));

		$step = $this->steps->insert($step);
		$this->touch($template);

		return $step;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function updateStep(int $id, array $data): TemplateStep {
		$step = $this->requireStep($id);
		$section = $this->requireSection($step->getSectionId());
		$template = $this->loadTemplate($section->getTemplateId());
		$this->assertCanEditContent($template);

		$title = $this->readString($data, 'title');
		if ($title !== null) {
			$title = $this->normalizeTitle($this->requireString($data, 'title', 'step_title_required'));
		} else {
			$title = $step->getTitle();
		}

		$description = $this->readString($data, 'description');
		$description = $description === null
			? $step->getDescription()
			: $this->normalizeDescription($description);

		$rawType = $this->readString($data, 'type');
		$type = $rawType === null ? StepType::from($step->getType()) : $this->parseStepType($rawType);

		$required = $this->readBool($data, 'required') ?? $step->getRequired();

		$rawConfig = $this->readArray($data, 'config');
		$config = $this->validateConfig($type, $rawConfig ?? $step->getConfigArray());

		$assignee = array_key_exists('defaultAssignee', $data)
			? $this->normalizeAssignee($this->readString($data, 'defaultAssignee'))
			: $step->getDefaultAssignee();

		$dueOffset = array_key_exists('dueOffset', $data)
			? $this->normalizeDueOffset($this->readString($data, 'dueOffset'))
			: $step->getDueOffset();

		$changed = false;

		if ($type->value !== $step->getType()) {
			$step->setType($type->value);
			$changed = true;
		}
		if ($config !== $step->getConfigArray()) {
			$step->setConfigArray($config);
			$changed = true;
		}
		if ($title !== $step->getTitle()) {
			$step->setTitle($title);
			$changed = true;
		}
		if ($description !== $step->getDescription()) {
			$step->setDescription($description);
			$changed = true;
		}
		if ($required !== $step->getRequired()) {
			$step->setRequired($required);
			$changed = true;
		}
		if ($assignee !== $step->getDefaultAssignee()) {
			$step->setDefaultAssignee($assignee);
			$changed = true;
		}
		if ($dueOffset !== $step->getDueOffset()) {
			$step->setDueOffset($dueOffset);
			$changed = true;
		}

		if ($changed) {
			$step = $this->steps->update($step);
			$this->touch($template);
		}

		return $step;
	}

	public function deleteStep(int $id): void {
		$step = $this->requireStep($id);
		$section = $this->requireSection($step->getSectionId());
		$template = $this->loadTemplate($section->getTemplateId());
		$this->assertCanEditContent($template);

		$sectionId = $step->getSectionId();
		$this->steps->delete($step);
		$this->normalizeStepPositions($sectionId);
		$this->touch($template);
	}

	/**
	 * Move a step to a new zero-based position within its section.
	 *
	 * @return list<TemplateStep>
	 */
	public function reorderStep(int $id, int $position): array {
		$step = $this->requireStep($id);
		$section = $this->requireSection($step->getSectionId());
		$template = $this->loadTemplate($section->getTemplateId());
		$this->assertCanEditContent($template);

		if ($position < 0) {
			throw new ValidationException('invalid_position');
		}

		$sectionId = $step->getSectionId();
		$siblings = $this->steps->findBySection($sectionId);
		$remaining = array_values(array_filter(
			$siblings,
			static fn (TemplateStep $candidate): bool => $candidate->getId() !== $step->getId(),
		));

		if ($position > count($remaining)) {
			throw new ValidationException('invalid_position');
		}

		array_splice($remaining, $position, 0, [$step]);

		$changed = false;
		foreach ($remaining as $index => $item) {
			if ($item->getPosition() !== $index) {
				$item->setPosition($index);
				$this->steps->update($item);
				$changed = true;
			}
		}

		// Reordering to the current position changes nothing and must not touch
		// the template or bump the version of a published template.
		if ($changed) {
			$this->touch($template);
		}

		return $remaining;
	}

	private function touch(Template $template): void {
		$template->setUpdatedAt($this->timeFactory->getTime());
		if ($template->getStatus() === TemplateStatus::Published->value) {
			$template->setVersion($template->getVersion() + 1);
		}
		$this->templates->update($template);
	}

	private function normalizeSectionPositions(int $templateId): void {
		foreach ($this->sections->findByTemplate($templateId) as $index => $section) {
			if ($section->getPosition() !== $index) {
				$section->setPosition($index);
				$this->sections->update($section);
			}
		}
	}

	private function normalizeStepPositions(int $sectionId): void {
		foreach ($this->steps->findBySection($sectionId) as $index => $step) {
			if ($step->getPosition() !== $index) {
				$step->setPosition($index);
				$this->steps->update($step);
			}
		}
	}

	private function loadTemplate(int $id): Template {
		try {
			return $this->templates->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('template_not_found');
		}
	}

	private function requireOwnerTemplate(int $id): Template {
		$template = $this->loadTemplate($id);
		if (!$this->permissionService->isOwner($template, $this->currentUserId())) {
			throw new ForbiddenException('not_owner');
		}

		return $template;
	}

	private function requireSection(int $id): TemplateSection {
		try {
			return $this->sections->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('section_not_found');
		}
	}

	private function requireStep(int $id): TemplateStep {
		try {
			return $this->steps->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('step_not_found');
		}
	}

	private function assertCanView(Template $template): void {
		if (!$this->permissionService->canView($template, $this->currentUserId())) {
			throw new ForbiddenException('not_allowed');
		}
	}

	private function assertCanEditContent(Template $template): void {
		if (!$this->permissionService->canEdit($template, $this->currentUserId())) {
			throw new ForbiddenException('not_allowed');
		}
		$this->assertNotArchived($template);
	}

	private function assertNotArchived(Template $template): void {
		if ($template->getStatus() === TemplateStatus::Archived->value) {
			throw new ConflictException('template_archived');
		}
	}

	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new ForbiddenException('not_authenticated');
		}

		return $user->getUID();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function readString(array $data, string $key): ?string {
		if (!array_key_exists($key, $data) || $data[$key] === null) {
			return null;
		}
		if (!is_string($data[$key])) {
			throw new ValidationException('invalid_field');
		}

		return $data[$key];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function requireString(array $data, string $key, string $reason): string {
		$value = $this->readString($data, $key);
		if ($value === null) {
			throw new ValidationException($reason);
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function readBool(array $data, string $key): ?bool {
		if (!array_key_exists($key, $data) || $data[$key] === null) {
			return null;
		}
		if (!is_bool($data[$key])) {
			throw new ValidationException('invalid_field');
		}

		return $data[$key];
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>|null
	 */
	private function readArray(array $data, string $key): ?array {
		if (!array_key_exists($key, $data) || $data[$key] === null) {
			return null;
		}
		if (!is_array($data[$key])) {
			throw new ValidationException('invalid_field');
		}

		/** @var array<string, mixed> $value */
		$value = $data[$key];

		return $value;
	}

	private function normalizeTitle(string $title): string {
		$title = trim($title);
		if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
			throw new ValidationException('title_too_long');
		}

		return $title;
	}

	private function normalizeDescription(string $description): string {
		if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
			throw new ValidationException('description_too_long');
		}

		return $description;
	}

	private function normalizeNotes(string $notes): string {
		if (mb_strlen($notes) > self::MAX_NOTES_LENGTH) {
			throw new ValidationException('notes_too_long');
		}

		return $notes;
	}

	private function normalizeAssignee(?string $assignee): ?string {
		if ($assignee === null) {
			return null;
		}
		$assignee = trim($assignee);
		if ($assignee === '') {
			return null;
		}
		if (mb_strlen($assignee) > self::MAX_ASSIGNEE_LENGTH) {
			throw new ValidationException('assignee_too_long');
		}

		return $assignee;
	}

	/**
	 * Due offsets are stored as a non-negative number of minutes from the run
	 * start, for example "60" (one hour) or "1440" (one day).
	 */
	private function normalizeDueOffset(?string $dueOffset): ?string {
		if ($dueOffset === null) {
			return null;
		}
		$dueOffset = trim($dueOffset);
		if ($dueOffset === '') {
			return null;
		}
		if (preg_match('/^[0-9]+$/', $dueOffset) !== 1) {
			throw new ValidationException('invalid_due_offset');
		}
		if ((int)$dueOffset > self::MAX_DUE_OFFSET_MINUTES) {
			throw new ValidationException('invalid_due_offset');
		}

		return (string)(int)$dueOffset;
	}

	private function parseStepType(?string $type): StepType {
		if ($type === null) {
			throw new ValidationException('step_type_required');
		}
		$parsed = StepType::tryFrom($type);
		if ($parsed === null) {
			throw new ValidationException('invalid_step_type');
		}

		return $parsed;
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	private function validateConfig(StepType $type, array $config): array {
		$normalized = [];
		foreach ($config as $key => $value) {
			$normalized[(string)$key] = $value;
		}

		if ($type === StepType::Select) {
			$options = $normalized['options'] ?? null;
			if (!is_array($options) || $options === []) {
				throw new ValidationException('select_options_required');
			}
			$normalizedOptions = [];
			foreach ($options as $option) {
				if (!is_string($option) || trim($option) === '') {
					throw new ValidationException('invalid_select_option');
				}
				$normalizedOptions[] = trim($option);
			}
			$normalized['options'] = $normalizedOptions;
		} elseif ($type === StepType::Number) {
			$unit = $normalized['unit'] ?? null;
			if ($unit !== null) {
				if (!is_string($unit) || mb_strlen($unit) > 32) {
					throw new ValidationException('invalid_unit');
				}
			}
		}

		$encoded = json_encode($normalized, JSON_THROW_ON_ERROR);
		if (strlen($encoded) > self::MAX_CONFIG_LENGTH) {
			throw new ValidationException('config_too_large');
		}

		return $normalized;
	}

	private function generateUuid(): string {
		$hex = $this->secureRandom->generate(32, '0123456789abcdef');
		$hex = substr_replace($hex, '4', 12, 1);
		$variant = dechex(0x8 | ((int)hexdec($hex[16]) & 0x3));
		$hex = substr_replace($hex, $variant, 16, 1);

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr($hex, 0, 8),
			substr($hex, 8, 4),
			substr($hex, 12, 4),
			substr($hex, 16, 4),
			substr($hex, 20, 12),
		);
	}
}
