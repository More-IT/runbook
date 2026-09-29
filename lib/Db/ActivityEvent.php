<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * An append-only activity event for a run.
 *
 * @method int getRunId()
 * @method void setRunId(int $runId)
 * @method int|null getStepId()
 * @method void setStepId(?int $stepId)
 * @method string getActorUid()
 * @method void setActorUid(string $actorUid)
 * @method string getEventType()
 * @method void setEventType(string $eventType)
 * @method string getMetadata()
 * @method void setMetadata(string $metadata)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 *
 * @psalm-type ActivityData array{
 *     id: int,
 *     runId: int,
 *     stepId: int|null,
 *     actorUid: string,
 *     eventType: string,
 *     metadata: array<string, mixed>,
 *     createdAt: int
 * }
 */
class ActivityEvent extends Entity {
	protected int $runId = 0;
	protected ?int $stepId = null;
	protected string $actorUid = '';
	protected string $eventType = '';
	protected string $metadata = '{}';
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('runId', Types::BIGINT);
		$this->addType('stepId', Types::BIGINT);
		$this->addType('actorUid', Types::STRING);
		$this->addType('eventType', Types::STRING);
		$this->addType('metadata', Types::TEXT);
		$this->addType('createdAt', Types::BIGINT);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getMetadataArray(): array {
		if ($this->metadata === '') {
			return [];
		}

		$decoded = json_decode($this->metadata, true);
		if (!is_array($decoded)) {
			return [];
		}

		$metadata = [];
		/** @psalm-suppress MixedAssignment JSON metadata is intentionally untyped. */
		foreach ($decoded as $key => $value) {
			$metadata[(string)$key] = $value;
		}

		return $metadata;
	}

	/**
	 * @param array<string, mixed> $metadata
	 */
	public function setMetadataArray(array $metadata): void {
		$this->setMetadata(json_encode($metadata, JSON_THROW_ON_ERROR));
	}

	/**
	 * @return ActivityData
	 */
	public function toArray(): array {
		return [
			'id' => (int)$this->id,
			'runId' => $this->runId,
			'stepId' => $this->stepId,
			'actorUid' => $this->actorUid,
			'eventType' => $this->eventType,
			'metadata' => $this->getMetadataArray(),
			'createdAt' => $this->createdAt,
		];
	}
}
