<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunAcl;
use OCA\Runbook\Db\RunAclMapper;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Db\RunStepMapper;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunAclRole;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Resolves the effective access of a user on a run.
 *
 * Access is derived from ownership, run ACL entries and step assignments.
 * Group membership is always resolved through Nextcloud and never cached.
 */
class RunAccessService {
	public function __construct(
		private readonly RunAclMapper $aclMapper,
		private readonly RunStepMapper $stepMapper,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
	) {
	}

	public function getEffectiveRole(Run $run, string $uid): ?RunAclRole {
		if ($this->isOwner($run, $uid)) {
			return RunAclRole::Owner;
		}

		$entries = $this->aclMapper->findByRun($run->getId());
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

			$role = RunAclRole::tryFrom($entry->getRole());
			if ($role === null || $role === RunAclRole::Owner) {
				// OWNER rows are not allowed; ignore them defensively.
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
	private function entryAppliesTo(RunAcl $entry, string $uid, ?array &$groupIds): bool {
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
	 * @return list<string>
	 */
	public function getUserGroupIds(string $uid): array {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return [];
		}

		return $this->groupManager->getUserGroupIds($user);
	}

	public function isOwner(Run $run, string $uid): bool {
		return $run->getOwner() === $uid;
	}

	/**
	 * @param list<string>|null $groupIds
	 */
	public function isAssignedToStep(RunStep $step, string $uid, ?array &$groupIds = null): bool {
		$assigneeType = $step->getAssigneeType();
		$assigneeId = $step->getAssigneeId();
		if ($assigneeType === null || $assigneeId === null) {
			return false;
		}

		$type = PrincipalType::tryFrom($assigneeType);
		if ($type === null) {
			return false;
		}

		if ($type === PrincipalType::User) {
			return $assigneeId === $uid;
		}

		if ($groupIds === null) {
			$groupIds = $this->getUserGroupIds($uid);
		}

		return in_array($assigneeId, $groupIds, true);
	}

	/**
	 * Whether the user is assigned to any step of the run.
	 */
	public function isAssignedToRun(Run $run, string $uid): bool {
		$groupIds = $this->getUserGroupIds($uid);

		return $this->stepMapper->findAssignedInRun($run->getId(), $uid, $groupIds) !== [];
	}

	public function canView(Run $run, string $uid): bool {
		if ($this->isOwner($run, $uid)) {
			return true;
		}
		if ($this->getEffectiveRole($run, $uid) !== null) {
			return true;
		}

		return $this->isAssignedToRun($run, $uid);
	}

	public function canManage(Run $run, string $uid): bool {
		return $this->isOwner($run, $uid);
	}

	/**
	 * Owners, participants and step-assigned users may comment while the run
	 * is active. Viewers may read comments but may not add them.
	 */
	public function canComment(Run $run, string $uid): bool {
		if (!$this->canView($run, $uid)) {
			return false;
		}
		if ($this->isOwner($run, $uid)) {
			return true;
		}

		$role = $this->getEffectiveRole($run, $uid);
		if ($role === RunAclRole::Participant || $role === RunAclRole::Owner) {
			return true;
		}

		return $this->isAssignedToRun($run, $uid);
	}

	/**
	 * Owners may execute every step; participants may execute steps assigned
	 * to them or to one of their groups.
	 */
	public function canExecuteStep(Run $run, RunStep $step, string $uid): bool {
		if ($this->isOwner($run, $uid)) {
			return true;
		}

		$groupIds = $this->getUserGroupIds($uid);

		return $this->isAssignedToStep($step, $uid, $groupIds);
	}

	/**
	 * Ids of the run steps the user may execute.
	 *
	 * @param list<RunStep> $steps
	 * @return list<int>
	 */
	public function executableStepIds(Run $run, array $steps, string $uid): array {
		if ($this->isOwner($run, $uid)) {
			return array_map(static fn (RunStep $step): int => $step->getId(), $steps);
		}

		$groupIds = $this->getUserGroupIds($uid);
		$ids = [];
		foreach ($steps as $step) {
			if ($this->isAssignedToStep($step, $uid, $groupIds)) {
				$ids[] = $step->getId();
			}
		}

		return $ids;
	}
}
