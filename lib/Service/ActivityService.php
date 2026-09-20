<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\ActivityEvent;
use OCA\Runbook\Db\ActivityMapper;
use OCA\Runbook\Enum\ActivityType;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;

/**
 * Append-only activity history.
 *
 * There is deliberately no update or delete method. Metadata is sanitized so
 * that storage paths, file contents and comment bodies are never persisted in
 * activity rows.
 */
class ActivityService {
	private const DEFAULT_LIMIT = 100;
	private const MAX_LIMIT = 200;

	/** Substrings that must never appear in metadata keys. */
	private const FORBIDDEN_METADATA_KEYS = ['storagekey', 'storage_key', 'path', 'body', 'content'];

	public function __construct(
		private readonly ActivityMapper $activity,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @param array<array-key, mixed> $metadata
	 */
	public function record(
		int $runId,
		?int $stepId,
		ActivityType $type,
		array $metadata = [],
		?string $actorUid = null,
	): ActivityEvent {
		$event = new ActivityEvent();
		$event->setRunId($runId);
		$event->setStepId($stepId);
		$event->setActorUid($actorUid ?? $this->currentUserId());
		$event->setEventType($type->value);
		$event->setMetadataArray($this->sanitizeMetadata($metadata));
		$event->setCreatedAt($this->timeFactory->getTime());

		return $this->activity->insert($event);
	}

	/**
	 * @return list<ActivityEvent>
	 */
	public function listForRun(int $runId, ?int $limit = null, bool $descending = true): array {
		$limit = max(1, min($limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT));

		return $this->activity->findByRun($runId, $limit, $descending);
	}

	/**
	 * @param array<array-key, mixed> $metadata
	 * @return array<string, mixed>
	 */
	private function sanitizeMetadata(array $metadata): array {
		$clean = [];
		foreach ($metadata as $key => $value) {
			$key = (string)$key;
			$lower = strtolower($key);
			$forbidden = false;
			foreach (self::FORBIDDEN_METADATA_KEYS as $needle) {
				if (str_contains($lower, $needle)) {
					$forbidden = true;
					break;
				}
			}
			if ($forbidden) {
				continue;
			}

			if (is_array($value)) {
				$clean[$key] = $this->sanitizeMetadata($value);
			} elseif (is_scalar($value) || $value === null) {
				$clean[$key] = $value;
			}
		}

		return $clean;
	}

	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new ForbiddenException('not_authenticated');
		}

		return $user->getUID();
	}
}
