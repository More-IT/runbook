<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A step snapshot inside a run with its execution state and response.
 *
 * @method int getRunSectionId()
 * @method void setRunSectionId(int $runSectionId)
 * @method int|null getSourceStepId()
 * @method void setSourceStepId(?int $sourceStepId)
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string getTitle()
 * @method string getDescription()
 * @method string getType()
 * @method void setType(string $type)
 * @method bool getRequired()
 * @method bool isRequired()
 * @method void setRequired(bool $required)
 * @method int getPosition()
 * @method void setPosition(int $position)
 * @method string getConfig()
 * @method void setConfig(string $config)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getResponse()
 * @method void setResponse(?string $response)
 * @method string|null getSkipReason()
 * @method void setSkipReason(?string $skipReason)
 * @method string|null getAssigneeType()
 * @method void setAssigneeType(?string $assigneeType)
 * @method string|null getAssigneeId()
 * @method void setAssigneeId(?string $assigneeId)
 * @method int|null getDueAt()
 * @method void setDueAt(?int $dueAt)
 * @method int|null getStartedAt()
 * @method void setStartedAt(?int $startedAt)
 * @method int|null getCompletedAt()
 * @method void setCompletedAt(?int $completedAt)
 * @method int|null getSkippedAt()
 * @method void setSkippedAt(?int $skippedAt)
 * @method int|null getReopenedAt()
 * @method void setReopenedAt(?int $reopenedAt)
 *
 * @phpstan-type RunStepData array{
 *     id: int,
 *     runSectionId: int,
 *     sourceStepId: int|null,
 *     uuid: string,
 *     title: string,
 *     description: string,
 *     type: string,
 *     required: bool,
 *     position: int,
 *     config: array<string, mixed>,
 *     status: string,
 *     response: bool|int|float|string|null,
 *     skipReason: string|null,
 *     assigneeType: string|null,
 *     assigneeId: string|null,
 *     dueAt: int|null,
 *     startedAt: int|null,
 *     completedAt: int|null,
 *     skippedAt: int|null,
 *     reopenedAt: int|null
 * }
 */
class RunStep extends Entity {
	protected int $runSectionId = 0;
	protected ?int $sourceStepId = null;
	protected string $uuid = '';
	protected string $title = '';
	protected string $description = '';
	protected string $type = '';
	protected bool $required = false;
	protected int $position = 0;
	protected string $config = '{}';
	protected string $status = '';
	protected ?string $response = null;
	protected ?string $skipReason = null;
	protected ?string $assigneeType = null;
	protected ?string $assigneeId = null;
	protected ?int $dueAt = null;
	protected ?int $startedAt = null;
	protected ?int $completedAt = null;
	protected ?int $skippedAt = null;
	protected ?int $reopenedAt = null;

	public function __construct() {
		$this->addType('runSectionId', Types::BIGINT);
		$this->addType('sourceStepId', Types::BIGINT);
		$this->addType('uuid', Types::STRING);
		$this->addType('title', Types::STRING);
		$this->addType('description', Types::TEXT);
		$this->addType('type', Types::STRING);
		$this->addType('required', Types::BOOLEAN);
		$this->addType('position', Types::INTEGER);
		$this->addType('config', Types::TEXT);
		$this->addType('status', Types::STRING);
		$this->addType('response', Types::TEXT);
		$this->addType('skipReason', Types::TEXT);
		$this->addType('assigneeType', Types::STRING);
		$this->addType('assigneeId', Types::STRING);
		$this->addType('dueAt', Types::BIGINT);
		$this->addType('startedAt', Types::BIGINT);
		$this->addType('completedAt', Types::BIGINT);
		$this->addType('skippedAt', Types::BIGINT);
		$this->addType('reopenedAt', Types::BIGINT);
	}

	/**
	 * Explicit setter so that an empty title is always persisted, even though
	 * it equals the entity's initial value.
	 */
	public function setTitle(string $title): void {
		$this->title = $title;
		$this->markFieldUpdated('title');
	}

	/**
	 * Explicit setter so that an empty description is always persisted, even
	 * though it equals the entity's initial value.
	 */
	public function setDescription(string $description): void {
		$this->description = $description;
		$this->markFieldUpdated('description');
	}

	/**
	 * Decode the stored JSON configuration into an associative array.
	 *
	 * @return array<string, mixed>
	 */
	public function getConfigArray(): array {
		if ($this->config === '') {
			return [];
		}

		$decoded = json_decode($this->config, true);
		if (!is_array($decoded)) {
			return [];
		}

		$config = [];
		foreach ($decoded as $key => $value) {
			$config[(string)$key] = $value;
		}

		return $config;
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public function setConfigArray(array $config): void {
		$this->setConfig(json_encode($config, JSON_THROW_ON_ERROR));
	}

	/**
	 * Decode the stored scalar response.
	 */
	public function getResponseValue(): bool|int|float|string|null {
		if ($this->response === null || $this->response === '') {
			return null;
		}

		$decoded = json_decode($this->response, true);
		if (is_bool($decoded) || is_int($decoded) || is_float($decoded) || is_string($decoded)) {
			return $decoded;
		}

		return null;
	}

	/**
	 * Store a scalar response or clear it.
	 */
	public function setResponseValue(bool|int|float|string|null $value): void {
		$this->setResponse($value === null ? null : json_encode($value, JSON_THROW_ON_ERROR));
	}

	/**
	 * @return RunStepData
	 */
	public function toArray(): array {
		return [
			'id' => (int)$this->id,
			'runSectionId' => $this->runSectionId,
			'sourceStepId' => $this->sourceStepId,
			'uuid' => $this->uuid,
			'title' => $this->title,
			'description' => $this->description,
			'type' => $this->type,
			'required' => $this->required,
			'position' => $this->position,
			'config' => $this->getConfigArray(),
			'status' => $this->status,
			'response' => $this->getResponseValue(),
			'skipReason' => $this->skipReason,
			'assigneeType' => $this->assigneeType,
			'assigneeId' => $this->assigneeId,
			'dueAt' => $this->dueAt,
			'startedAt' => $this->startedAt,
			'completedAt' => $this->completedAt,
			'skippedAt' => $this->skippedAt,
			'reopenedAt' => $this->reopenedAt,
		];
	}
}
