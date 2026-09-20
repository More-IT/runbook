<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunAcl;
use OCA\Runbook\Db\RunAclMapper;
use OCA\Runbook\Db\RunMapper;
use OCA\Runbook\Enum\ActivityType;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunAclRole;
use OCA\Runbook\Enum\RunStatus;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;

/**
 * Participant and viewer list management for runs.
 *
 * Only the run owner may read or replace run ACL entries. The whole request is
 * validated before anything is written and the replacement runs in a
 * transaction so a failed write never leaves a partial ACL behind.
 */
class RunAclService {
	public function __construct(
		private readonly RunMapper $runs,
		private readonly RunAclMapper $aclMapper,
		private readonly RunAccessService $access,
		private readonly PrincipalValidator $principalValidator,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $timeFactory,
		private readonly TransactionRunner $transactionRunner,
		private readonly ActivityService $activity,
		private readonly NotificationService $notifications,
	) {
	}

	/**
	 * @return array{owner: string, entries: list<RunAcl>}
	 */
	public function getAcl(int $runId): array {
		$run = $this->loadRun($runId);
		$this->requireOwner($run);

		return [
			'owner' => $run->getOwner(),
			'entries' => $this->aclMapper->findByRun($runId),
		];
	}

	/**
	 * Replace the complete ACL set of a run atomically.
	 *
	 * @param array<mixed> $entries
	 * @return array{owner: string, entries: list<RunAcl>}
	 */
	public function replaceAcl(int $runId, array $entries): array {
		$run = $this->loadRun($runId);
		$this->requireOwner($run);

		if ($run->getStatus() !== RunStatus::Active->value) {
			throw new ConflictException('run_not_active');
		}

		$normalized = $this->validateEntries($entries);
		$owner = $run->getOwner();

		$this->transactionRunner->run(function () use ($runId, $normalized): void {
			$this->aclMapper->deleteByRun($runId);

			$now = $this->timeFactory->getTime();
			foreach ($normalized as $entry) {
				$acl = new RunAcl();
				$acl->setRunId($runId);
				$acl->setPrincipalType($entry['principalType']);
				$acl->setPrincipalId($entry['principalId']);
				$acl->setRole($entry['role']);
				$acl->setCreatedAt($now);
				$acl->setUpdatedAt($now);
				$this->aclMapper->insert($acl);
			}
		});

		$actor = $this->currentUserId();
		$this->activity->record($run->getId(), null, ActivityType::RunAclChanged, [
			'entries' => count($normalized),
		], $actor);

		foreach ($normalized as $entry) {
			$this->notifications->notifyRunAssigned(
				$run,
				PrincipalType::from($entry['principalType']),
				$entry['principalId'],
				$actor,
			);
		}

		return [
			'owner' => $owner,
			'entries' => $this->aclMapper->findByRun($runId),
		];
	}

	private function requireOwner(Run $run): void {
		$uid = $this->currentUserId();
		if ($this->access->canManage($run, $uid)) {
			return;
		}
		if (!$this->access->canView($run, $uid)) {
			throw new NotFoundException('run_not_found');
		}

		throw new ForbiddenException('not_owner');
	}

	private function loadRun(int $id): Run {
		try {
			return $this->runs->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('run_not_found');
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

			$principal = $this->principalValidator->validate(
				$entry['principalType'] ?? null,
				$entry['principalId'] ?? null,
			);
			$role = $this->validateRole($entry['role'] ?? null);

			$key = $principal['type']->value . ':' . $principal['id'];
			if (isset($seen[$key])) {
				throw new ValidationException('duplicate_principal');
			}
			$seen[$key] = true;

			$normalized[] = [
				'principalType' => $principal['type']->value,
				'principalId' => $principal['id'],
				'role' => $role->value,
			];
		}

		return $normalized;
	}

	private function validateRole(mixed $value): RunAclRole {
		if (!is_string($value)) {
			throw new ValidationException('invalid_role');
		}

		$role = RunAclRole::tryFrom($value);
		if ($role === null) {
			throw new ValidationException('invalid_role');
		}
		if ($role === RunAclRole::Owner) {
			throw new ValidationException('owner_role_not_allowed');
		}

		return $role;
	}
}
