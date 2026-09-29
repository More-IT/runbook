<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getSectionId()
 * @method void setSectionId(int $sectionId)
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
 * @method string|null getDefaultAssignee()
 * @method void setDefaultAssignee(?string $defaultAssignee)
 * @method string|null getDueOffset()
 * @method void setDueOffset(?string $dueOffset)
 *
 * @psalm-type StepData array{
 *     id: int,
 *     sectionId: int,
 *     uuid: string,
 *     title: string,
 *     description: string,
 *     type: string,
 *     required: bool,
 *     position: int,
 *     config: array<string, mixed>,
 *     defaultAssignee: string|null,
 *     dueOffset: string|null
 * }
 */
class TemplateStep extends Entity {
	protected int $sectionId = 0;
	protected string $uuid = '';
	protected string $title = '';
	protected string $description = '';
	protected string $type = '';
	protected bool $required = false;
	protected int $position = 0;
	protected string $config = '{}';
	protected ?string $defaultAssignee = null;
	protected ?string $dueOffset = null;

	public function __construct() {
		$this->addType('sectionId', Types::BIGINT);
		$this->addType('uuid', Types::STRING);
		$this->addType('title', Types::STRING);
		$this->addType('description', Types::TEXT);
		$this->addType('type', Types::STRING);
		$this->addType('required', Types::BOOLEAN);
		$this->addType('position', Types::INTEGER);
		$this->addType('config', Types::TEXT);
		$this->addType('defaultAssignee', Types::STRING);
		$this->addType('dueOffset', Types::STRING);
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
		/** @psalm-suppress MixedAssignment JSON configuration values are intentionally untyped. */
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
	 * @return StepData
	 */
	public function toArray(): array {
		return [
			'id' => (int)$this->id,
			'sectionId' => $this->sectionId,
			'uuid' => $this->uuid,
			'title' => $this->title,
			'description' => $this->description,
			'type' => $this->type,
			'required' => $this->required,
			'position' => $this->position,
			'config' => $this->getConfigArray(),
			'defaultAssignee' => $this->defaultAssignee,
			'dueOffset' => $this->dueOffset,
		];
	}
}
