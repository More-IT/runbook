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
use OCP\IGroupManager;
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
		private readonly IGroupManager $groupManager,
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
	 * Restore an archived template to the active listing.
	 *
	 * Only the owner or a Nextcloud administrator may unarchive. The template
	 * returns to PUBLISHED when it had been published before, otherwise to
	 * DRAFT, so publishing history and content are preserved.
	 */
	public function unarchiveTemplate(int $id): Template {
		$template = $this->requireOwnerOrAdminTemplate($id);

		if ($template->getStatus() !== TemplateStatus::Archived->value) {
			throw new ConflictException('template_not_archived');
		}

		$now = $this->timeFactory->getTime();
		$target = $template->getPublishedAt() !== null
			? TemplateStatus::Published->value
			: TemplateStatus::Draft->value;
		$template->setStatus($target);
		$template->setArchivedAt(null);
		$template->setUpdatedAt($now);

		return $this->templates->update($template);
	}

	/**
	 * Duplicate a template into a new independent draft owned by the current
	 * user.
	 *
	 * Only the template owner or a Nextcloud administrator may duplicate.
	 * Sections, steps and their configuration are copied. Runs, activity,
	 * comments, evidence and the source ACL are intentionally never copied.
	 */
	public function duplicateTemplate(int $id): Template {
		$source = $this->requireOwnerOrAdminTemplate($id);

		$uid = $this->currentUserId();
		$now = $this->timeFactory->getTime();
		$copy = new Template();
		$copy->setUuid($this->generateUuid());
		$copy->setTitle($this->copyTitle($source->getTitle()));
		$copy->setDescription($source->getDescription());
		$copy->setVersion(1);
		$copy->setStatus(TemplateStatus::Draft->value);
		$copy->setOwner($uid);
		$copy->setCreatedAt($now);
		$copy->setUpdatedAt($now);
		$copy = $this->templates->insert($copy);

		foreach ($this->sections->findByTemplate($source->getId()) as $sourceSection) {
			$section = new TemplateSection();
			$section->setTemplateId($copy->getId());
			$section->setTitle($sourceSection->getTitle());
			$section->setDescription($sourceSection->getDescription());
			$section->setNotes($sourceSection->getNotes());
			$section->setPosition($sourceSection->getPosition());
			$section = $this->sections->insert($section);

			foreach ($this->steps->findBySection($sourceSection->getId()) as $sourceStep) {
				$step = new TemplateStep();
				$step->setSectionId($section->getId());
				$step->setUuid($this->generateUuid());
				$step->setTitle($sourceStep->getTitle());
				$step->setDescription($sourceStep->getDescription());
				$step->setType($sourceStep->getType());
				$step->setRequired($sourceStep->getRequired());
				$step->setPosition($sourceStep->getPosition());
				$step->setConfigArray($sourceStep->getConfigArray());
				$step->setDefaultAssignee($sourceStep->getDefaultAssignee());
				$step->setDueOffset($sourceStep->getDueOffset());
				$this->steps->insert($step);
			}
		}

		return $copy;
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
		$dependsOn = $this->normalizeDependsOn($data['dependsOn'] ?? null, $templateId, null);
		$conditions = $this->normalizeConditions($data['conditions'] ?? $data['condition'] ?? null, $templateId, null);
		$this->assertNoAmbiguousCondition($templateId, null, $conditions);

		$section = new TemplateSection();
		$section->setTemplateId($templateId);
		$section->setTitle($title);
		$section->setDescription($description);
		$section->setNotes($notes);
		$section->setDependsOnIds($dependsOn);
		$section->setConditions($conditions);
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

		if (array_key_exists('dependsOn', $data)) {
			$dependsOn = $this->normalizeDependsOn($data['dependsOn'], $section->getTemplateId(), $section->getId());
			$this->assertNoDependencyCycles($section->getTemplateId(), [$section->getId() => $dependsOn]);
			if ($dependsOn !== $section->getDependsOnIds()) {
				$section->setDependsOnIds($dependsOn);
				$changed = true;
			}
		}

		if (array_key_exists('conditions', $data) || array_key_exists('condition', $data)) {
			$raw = array_key_exists('conditions', $data) ? $data['conditions'] : $data['condition'];
			$conditions = $this->normalizeConditions($raw, $section->getTemplateId(), $section->getId());
			$this->assertNoAmbiguousCondition($section->getTemplateId(), $section->getId(), $conditions);
			if ($conditions !== $section->getConditions()) {
				$section->setConditions($conditions);
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
		$deletedStepIds = array_map(
			static fn (TemplateStep $step): int => $step->getId(),
			$this->steps->findBySection($id),
		);

		$this->sections->delete($section);
		$this->normalizeSectionPositions($templateId);
		$this->stripSectionReferences($templateId, $id, $deletedStepIds);
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

	/**
	 * Load a template the current user owns or administers.
	 */
	private function requireOwnerOrAdminTemplate(int $id): Template {
		$template = $this->loadTemplate($id);
		$uid = $this->currentUserId();
		if (!$this->permissionService->isOwner($template, $uid) && !$this->groupManager->isAdmin($uid)) {
			throw new ForbiddenException('not_owner');
		}

		return $template;
	}

	private function copyTitle(string $title): string {
		$base = trim($title);
		if ($base === '') {
			return 'Copy';
		}

		return mb_substr('Copy of ' . $base, 0, self::MAX_TITLE_LENGTH);
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

	/**
	 * Validate and normalize section dependencies.
	 *
	 * @param mixed $raw
	 * @return list<int>
	 */
	private function normalizeDependsOn(mixed $raw, int $templateId, ?int $sectionId): array {
		if ($raw === null) {
			return [];
		}
		if (!is_array($raw)) {
			throw new ValidationException('invalid_field');
		}

		$known = [];
		foreach ($this->sections->findByTemplate($templateId) as $section) {
			$known[$section->getId()] = true;
		}

		$ids = [];
		foreach ($raw as $value) {
			if (is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1)) {
				$ids[] = (int)$value;
			} else {
				throw new ValidationException('section_dependency_unknown');
			}
		}
		$ids = array_values(array_unique($ids));

		foreach ($ids as $id) {
			if ($sectionId !== null && $id === $sectionId) {
				throw new ValidationException('section_self_dependency');
			}
			if (!isset($known[$id])) {
				throw new ValidationException('section_dependency_unknown');
			}
		}

		return $ids;
	}

	/**
	 * Reject a dependency cycle in the template's section graph.
	 *
	 * @param array<int, list<int>> $override
	 */
	private function assertNoDependencyCycles(int $templateId, array $override): void {
		$graph = [];
		foreach ($this->sections->findByTemplate($templateId) as $section) {
			$graph[$section->getId()] = $section->getDependsOnIds();
		}
		foreach ($override as $id => $deps) {
			$graph[$id] = $deps;
		}

		$visiting = [];
		$done = [];
		foreach (array_keys($graph) as $id) {
			if ($this->hasCycle($id, $graph, $visiting, $done)) {
				throw new ValidationException('section_dependency_cycle');
			}
		}
	}

	/**
	 * @param array<int, list<int>> $graph
	 * @param array<int, bool> $visiting
	 * @param array<int, bool> $done
	 */
	private function hasCycle(int $id, array $graph, array &$visiting, array &$done): bool {
		if (isset($done[$id])) {
			return false;
		}
		if (isset($visiting[$id])) {
			return true;
		}
		$visiting[$id] = true;
		foreach ($graph[$id] ?? [] as $dep) {
			if (isset($graph[$dep]) && $this->hasCycle($dep, $graph, $visiting, $done)) {
				return true;
			}
		}
		unset($visiting[$id]);
		$done[$id] = true;

		return false;
	}

	/**
	 * Validate and normalize a section's condition gate.
	 *
	 * Accepts the canonical list of conditions (combined with AND semantics) or
	 * a legacy single-condition object, which is upgraded to a one-element list.
	 * Duplicate and contradictory conditions inside one gate are rejected.
	 *
	 * @param mixed $raw
	 * @return list<array<string, mixed>>
	 */
	private function normalizeConditions(mixed $raw, int $templateId, ?int $sectionId): array {
		if ($raw === null) {
			return [];
		}
		if (!is_array($raw)) {
			throw new ValidationException('invalid_condition');
		}

		// Legacy single-condition object (`{"stepId":...}`).
		if (!array_is_list($raw)) {
			$raw = [$raw];
		}

		$conditions = [];
		$signatures = [];
		foreach ($raw as $entry) {
			if (!is_array($entry) || array_is_list($entry)) {
				throw new ValidationException('invalid_condition');
			}
			$condition = $this->normalizeCondition($entry, $templateId, $sectionId);
			$signature = json_encode($condition, JSON_THROW_ON_ERROR);
			if (isset($signatures[$signature])) {
				throw new ValidationException('duplicate_section_condition');
			}
			$signatures[$signature] = true;
			$conditions[] = $condition;
		}

		$this->assertNoConflictingConditions($conditions);

		return $conditions;
	}

	/**
	 * Validate and normalize a single condition.
	 *
	 * The allowed operators come from {@see FlowService::operatorsForType()} so
	 * authoring validation and snapshot evaluation share one definition.
	 *
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>
	 */
	private function normalizeCondition(array $raw, int $templateId, ?int $sectionId): array {
		$stepId = $raw['stepId'] ?? null;
		$operator = $raw['operator'] ?? null;
		if ((!is_int($stepId) && !(is_string($stepId) && preg_match('/^[0-9]+$/', $stepId) === 1)) || !is_string($operator)) {
			throw new ValidationException('invalid_condition');
		}
		$stepId = (int)$stepId;

		$step = $this->findTemplateStep($templateId, $stepId);
		if ($step === null) {
			throw new ValidationException('condition_step_not_found');
		}

		// A condition must not reference a step of its own section: the section
		// could never become available because its gate would depend on a step
		// executed after it. Conditions on previous sections remain valid.
		if ($sectionId !== null && $step->getSectionId() === $sectionId) {
			throw new ValidationException('condition_references_own_section');
		}

		$type = StepType::tryFrom($step->getType());
		if ($type === null || $type === StepType::File) {
			throw new ValidationException('invalid_condition');
		}

		if (!FlowService::isOperatorAllowed($type, $operator)) {
			throw new ValidationException('invalid_condition_operator');
		}

		$condition = ['stepId' => $stepId, 'operator' => $operator];

		if ($type === StepType::Check || $type === StepType::Confirmation) {
			return $condition;
		}

		$value = $raw['value'] ?? null;
		if ($type === StepType::Number) {
			if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
				throw new ValidationException('invalid_condition_value');
			}
			$condition['value'] = (float)$value;
		} elseif ($type === StepType::Select) {
			$options = $step->getConfigArray()['options'] ?? null;
			$allowedValues = [];
			if (is_array($options)) {
				foreach ($options as $option) {
					if (is_string($option)) {
						$allowedValues[] = $option;
					}
				}
			}
			if (!is_string($value) || !in_array($value, $allowedValues, true)) {
				throw new ValidationException('invalid_condition_value');
			}
			$condition['value'] = $value;
		} else {
			if (!is_string($value) || trim($value) === '') {
				throw new ValidationException('invalid_condition_value');
			}
			$condition['value'] = $value;
		}

		return $condition;
	}

	/**
	 * Reject a gate whose conditions contradict each other, so a section can
	 * never become permanently unsatisfiable by accident.
	 *
	 * @param list<array<string, mixed>> $conditions
	 */
	private function assertNoConflictingConditions(array $conditions): void {
		$byStep = [];
		foreach ($conditions as $condition) {
			$stepId = (int)($condition['stepId'] ?? 0);
			foreach ($byStep[$stepId] ?? [] as $other) {
				if ($this->conditionsConflict($other, $condition)) {
					throw new ValidationException('conflicting_section_conditions');
				}
			}
			$byStep[$stepId][] = $condition;
		}
	}

	/**
	 * @param array<string, mixed> $left
	 * @param array<string, mixed> $right
	 */
	private function conditionsConflict(array $left, array $right): bool {
		$leftOperator = (string)($left['operator'] ?? '');
		$rightOperator = (string)($right['operator'] ?? '');

		if (in_array($leftOperator, ['is_true', 'is_false'], true)
			&& in_array($rightOperator, ['is_true', 'is_false'], true)) {
			return $leftOperator !== $rightOperator;
		}

		$isEquality = static fn (string $operator): bool => in_array($operator, ['equals', 'not_equals'], true);
		if (!$isEquality($leftOperator) || !$isEquality($rightOperator)) {
			return false;
		}

		$sameValue = $this->conditionValuesEqual($left['value'] ?? null, $right['value'] ?? null);
		if ($leftOperator === 'equals' && $rightOperator === 'equals') {
			return !$sameValue;
		}
		if ($leftOperator === 'not_equals' && $rightOperator === 'not_equals') {
			// Excluding two different values is satisfiable.
			return false;
		}

		// equals versus not_equals: conflicting only for the same value.
		return $sameValue;
	}

	/**
	 * @param mixed $left
	 * @param mixed $right
	 */
	private function conditionValuesEqual(mixed $left, mixed $right): bool {
		if (is_numeric($left) && is_numeric($right)) {
			return abs((float)$left - (float)$right) < 1e-9;
		}

		return $left === $right;
	}

	private function findTemplateStep(int $templateId, int $stepId): ?TemplateStep {
		foreach ($this->sections->findByTemplate($templateId) as $section) {
			foreach ($this->steps->findBySection($section->getId()) as $step) {
				if ($step->getId() === $stepId) {
					return $step;
				}
			}
		}

		return null;
	}

	/**
	 * Reject a second section carrying an identical condition gate (ambiguous
	 * alternative). Mutually exclusive operators are the author's
	 * responsibility; exact duplicates are always rejected.
	 *
	 * @param list<array<string, mixed>> $newConditions
	 */
	private function assertNoAmbiguousCondition(int $templateId, ?int $sectionId, array $newConditions): void {
		if ($newConditions === []) {
			return;
		}
		$signature = json_encode($newConditions, JSON_THROW_ON_ERROR);

		foreach ($this->sections->findByTemplate($templateId) as $section) {
			if ($sectionId !== null && $section->getId() === $sectionId) {
				continue;
			}
			$existing = $section->getConditions();
			if ($existing !== [] && json_encode($existing, JSON_THROW_ON_ERROR) === $signature) {
				throw new ValidationException('duplicate_section_condition');
			}
		}
	}

	/**
	 * Remove references to a deleted section from the remaining flow graph.
	 *
	 * @param list<int> $deletedStepIds
	 */
	private function stripSectionReferences(int $templateId, int $deletedSectionId, array $deletedStepIds): void {
		foreach ($this->sections->findByTemplate($templateId) as $section) {
			$changed = false;

			$deps = $section->getDependsOnIds();
			if (in_array($deletedSectionId, $deps, true)) {
				$section->setDependsOnIds(array_values(array_filter($deps, static fn (int $dep): bool => $dep !== $deletedSectionId)));
				$changed = true;
			}

			$conditions = $section->getConditions();
			if ($conditions !== []) {
				$kept = [];
				foreach ($conditions as $condition) {
					if (!in_array((int)($condition['stepId'] ?? 0), $deletedStepIds, true)) {
						$kept[] = $condition;
					}
				}
				if ($kept !== $conditions) {
					$section->setConditions($kept);
					$changed = true;
				}
			}

			if ($changed) {
				$this->sections->update($section);
			}
		}
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
