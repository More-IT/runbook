<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Enum\TemplateCreationPolicy;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Evaluates the configured template creation policy.
 *
 * The policy is always resolved server-side and never relies on the frontend.
 * Groups are read through Nextcloud and never created or modified here.
 */
class TemplateCreationPolicyService {
	public function __construct(
		private readonly AdminSettings $settings,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
	) {
	}

	public function canCreate(string $uid): bool {
		if ($uid === '') {
			return false;
		}

		return match ($this->settings->getTemplateCreationPolicy()) {
			TemplateCreationPolicy::Everyone => true,
			TemplateCreationPolicy::Admins => $this->groupManager->isAdmin($uid),
			TemplateCreationPolicy::SelectedGroups => $this->isInSelectedGroup($uid),
		};
	}

	private function isInSelectedGroup(string $uid): bool {
		$groups = $this->settings->getTemplateCreatorGroups();
		if ($groups === []) {
			return false;
		}

		$user = $this->userManager->get($uid);
		if ($user === null) {
			return false;
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);

		return array_intersect($groups, $userGroups) !== [];
	}
}
