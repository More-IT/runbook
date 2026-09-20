<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\Template;
use OCA\Runbook\Db\TemplateAcl;
use OCA\Runbook\Db\TemplateAclMapper;
use OCA\Runbook\Db\TemplateMapper;
use OCA\Runbook\Enum\AclRole;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\TemplateStatus;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Access control list management for templates.
 *
 * Only the template owner may read or replace ACL entries. The whole request
 * is validated before anything is written and the replacement runs in a
 * transaction so a failed write never leaves a partial ACL behind.
 */
class AclService {
	private const MAX_PRINCIPAL_RESULTS = 50;
	private const MAX_PRINCIPAL_ID_LENGTH = 255;

	public function __construct(
		private readonly TemplateMapper $templates,
		private readonly TemplateAclMapper $aclMapper,
		private readonly PermissionService $permissionService,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $timeFactory,
		private readonly TransactionRunner $transactionRunner,
	) {
	}

	/**
	 * @return array{owner: string, entries: list<TemplateAcl>}
	 */
	public function getAcl(int $templateId): array {
		$template = $this->loadTemplate($templateId);
		$this->requireManageAcl($template);

		return [
			'owner' => $template->getOwner(),
			'entries' => $this->aclMapper->findByTemplate($templateId),
		];
	}

	/**
	 * Replace the complete ACL set of a template atomically.
	 *
	 * @param array<mixed> $entries
	 * @return array{owner: string, entries: list<TemplateAcl>}
	 */
	public function replaceAcl(int $templateId, array $entries): array {
		$template = $this->loadTemplate($templateId);
		$this->requireManageAcl($template);

		if ($template->getStatus() === TemplateStatus::Archived->value) {
			throw new ConflictException('template_archived');
		}

		// Validate everything before touching the database.
		$normalized = $this->validateEntries($entries);
		$owner = $template->getOwner();

		$this->transactionRunner->run(function () use ($templateId, $normalized): void {
			$this->aclMapper->deleteByTemplate($templateId);

			$now = $this->timeFactory->getTime();
			foreach ($normalized as $entry) {
				$acl = new TemplateAcl();
				$acl->setTemplateId($templateId);
				$acl->setPrincipalType($entry['principalType']);
				$acl->setPrincipalId($entry['principalId']);
				$acl->setRole($entry['role']);
				$acl->setCreatedAt($now);
				$acl->setUpdatedAt($now);
				$this->aclMapper->insert($acl);
			}
		});

		return [
			'owner' => $owner,
			'entries' => $this->aclMapper->findByTemplate($templateId),
		];
	}

	/**
	 * Search Nextcloud users and groups for the ACL editor.
	 *
	 * @return list<array{principalType: string, principalId: string, displayName: string}>
	 */
	public function searchPrincipals(string $search, int $limit): array {
		$search = trim($search);
		if ($search === '') {
			return [];
		}

		$limit = max(1, min($limit, self::MAX_PRINCIPAL_RESULTS));

		$principals = [];
		foreach ($this->userManager->searchDisplayName($search, $limit) as $user) {
			$principals[] = [
				'principalType' => PrincipalType::User->value,
				'principalId' => $user->getUID(),
				'displayName' => $user->getDisplayName(),
			];
		}

		foreach ($this->groupManager->search($search, $limit) as $group) {
			$principals[] = [
				'principalType' => PrincipalType::Group->value,
				'principalId' => $group->getGID(),
				'displayName' => $group->getDisplayName(),
			];
		}

		return $principals;
	}

	private function requireManageAcl(Template $template): void {
		if (!$this->permissionService->canManageAcl($template, $this->currentUserId())) {
			throw new ForbiddenException('not_owner');
		}
	}

	private function loadTemplate(int $id): Template {
		try {
			return $this->templates->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('template_not_found');
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
	 * @param array<mixed> $entries
	 * @return list<array{principalType: string, principalId: string, role: string}>
	 */
	private function validateEntries(array $entries): array {
		$normalized = [];
		$seen = [];

		foreach ($entries as $entry) {
			if (!is_array($entry)) {
				throw new ValidationException('invalid_acl_entry');
			}

			$principalType = $this->validatePrincipalType($entry['principalType'] ?? null);
			$principalId = $this->validatePrincipalId($principalType, $entry['principalId'] ?? null);
			$role = $this->validateRole($entry['role'] ?? null);

			$key = $principalType->value . ':' . $principalId;
			if (isset($seen[$key])) {
				throw new ValidationException('duplicate_principal');
			}
			$seen[$key] = true;

			$normalized[] = [
				'principalType' => $principalType->value,
				'principalId' => $principalId,
				'role' => $role->value,
			];
		}

		return $normalized;
	}

	private function validatePrincipalType(mixed $value): PrincipalType {
		if (!is_string($value)) {
			throw new ValidationException('invalid_principal_type');
		}

		$type = PrincipalType::tryFrom($value);
		if ($type === null) {
			throw new ValidationException('invalid_principal_type');
		}

		return $type;
	}

	private function validatePrincipalId(PrincipalType $type, mixed $value): string {
		if (!is_string($value)) {
			throw new ValidationException('invalid_principal_id');
		}

		$principalId = trim($value);
		if ($principalId === '') {
			throw new ValidationException('empty_principal_id');
		}
		if (mb_strlen($principalId) > self::MAX_PRINCIPAL_ID_LENGTH) {
			throw new ValidationException('invalid_principal_id');
		}

		if ($type === PrincipalType::User) {
			if (!$this->userManager->userExists($principalId)) {
				throw new ValidationException('unknown_user');
			}
		} elseif (!$this->groupManager->groupExists($principalId)) {
			throw new ValidationException('unknown_group');
		}

		return $principalId;
	}

	private function validateRole(mixed $value): AclRole {
		if (!is_string($value)) {
			throw new ValidationException('invalid_role');
		}

		$role = AclRole::tryFrom($value);
		if ($role === null) {
			throw new ValidationException('invalid_role');
		}
		if ($role === AclRole::Owner) {
			throw new ValidationException('owner_role_not_allowed');
		}

		return $role;
	}
}
