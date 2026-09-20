<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\Template;
use OCA\Runbook\Db\TemplateAcl;
use OCA\Runbook\Db\TemplateAclMapper;
use OCA\Runbook\Enum\AclRole;
use OCA\Runbook\Enum\PrincipalType;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Resolves the effective Runbook access role of a user on a template.
 *
 * Effective permissions are derived from ownership, direct user ACL entries
 * and membership in groups that hold ACL entries. When several entries apply
 * the strongest role wins (OWNER > EDITOR > EXECUTOR > VIEWER).
 *
 * Group membership is always resolved through Nextcloud; it is never cached in
 * the Runbook database.
 */
class PermissionService {
	public function __construct(
		private readonly TemplateAclMapper $aclMapper,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
	) {
	}

	public function getEffectiveRole(Template $template, string $uid): ?AclRole {
		if ($this->isOwner($template, $uid)) {
			return AclRole::Owner;
		}

		$entries = $this->aclMapper->findByTemplate($template->getId());
		if ($entries === []) {
			return null;
		}

		/** @var list<string>|null $groupIds */
		$groupIds = null;
		$best = null;

		foreach ($entries as $entry) {
			if (!$this->entryAppliesTo($entry, $uid, $groupIds)) {
				continue;
			}

			$role = AclRole::tryFrom($entry->getRole());
			if ($role === null || $role === AclRole::Owner) {
				// OWNER rows are not allowed; ignore them defensively instead of
				// letting a persisted anomaly grant ownership.
				continue;
			}

			if ($best === null || $role->rank() > $best->rank()) {
				$best = $role;
			}
		}

		return $best;
	}

	/**
	 * @param list<string>|null $groupIds
	 */
	private function entryAppliesTo(TemplateAcl $entry, string $uid, ?array &$groupIds): bool {
		$type = PrincipalType::tryFrom($entry->getPrincipalType());
		if ($type === null) {
			return false;
		}

		if ($type === PrincipalType::User) {
			return $entry->getPrincipalId() === $uid;
		}

		if ($groupIds === null) {
			$groupIds = $this->getUserGroupIds($uid);
		}

		return in_array($entry->getPrincipalId(), $groupIds, true);
	}

	/**
	 * Group ids of a user resolved through Nextcloud.
	 *
	 * @return list<string>
	 */
	public function getUserGroupIds(string $uid): array {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return [];
		}

		return $this->groupManager->getUserGroupIds($user);
	}

	public function isOwner(Template $template, string $uid): bool {
		return $template->getOwner() === $uid;
	}

	public function canView(Template $template, string $uid): bool {
		return $this->getEffectiveRole($template, $uid) !== null;
	}

	public function canExecute(Template $template, string $uid): bool {
		return $this->getEffectiveRole($template, $uid)?->canExecute() ?? false;
	}

	public function canEdit(Template $template, string $uid): bool {
		return $this->getEffectiveRole($template, $uid)?->canEdit() ?? false;
	}

	public function canManageAcl(Template $template, string $uid): bool {
		return $this->getEffectiveRole($template, $uid)?->canManageAcl() ?? false;
	}

	public function canDelete(Template $template, string $uid): bool {
		return $this->getEffectiveRole($template, $uid)?->canDelete() ?? false;
	}
}
