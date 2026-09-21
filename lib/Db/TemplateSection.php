<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getTemplateId()
 * @method void setTemplateId(int $templateId)
 * @method string getTitle()
 * @method string getDescription()
 * @method string getNotes()
 * @method void setNotes(?string $notes)
 * @method int getPosition()
 * @method void setPosition(int $position)
 *
 * @phpstan-type SectionData array{
 *     id: int,
 *     templateId: int,
 *     title: string,
 *     description: string,
 *     notes: string,
 *     position: int,
 *     dependsOn: list<int>,
 *     condition: array<string, mixed>|null,
 *     conditions: list<array<string, mixed>>
 * }
 */
class TemplateSection extends Entity {
	protected int $templateId = 0;
	protected string $title = '';
	protected string $description = '';
	protected ?string $notes = null;
	protected int $position = 0;
	protected ?string $dependsOn = null;
	protected ?string $conditionConfig = null;

	public function __construct() {
		$this->addType('templateId', Types::BIGINT);
		$this->addType('title', Types::STRING);
		$this->addType('description', Types::TEXT);
		$this->addType('notes', Types::TEXT);
		$this->addType('position', Types::INTEGER);
		$this->addType('dependsOn', Types::TEXT);
		$this->addType('conditionConfig', Types::TEXT);
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
	 * Notes are optional free text. A null database value reads as an empty
	 * string so the API is always stable.
	 */
	public function getNotes(): string {
		return $this->notes ?? '';
	}

	public function setNotes(?string $notes): void {
		$this->notes = $notes;
		$this->markFieldUpdated('notes');
	}

	/**
	 * Prerequisite section ids.
	 *
	 * @return list<int>
	 */
	public function getDependsOnIds(): array {
		return self::decodeIntList($this->dependsOn);
	}

	/**
	 * @param list<int> $ids
	 */
	public function setDependsOnIds(array $ids): void {
		$normalized = [];
		foreach ($ids as $id) {
			$normalized[] = (int)$id;
		}
		$normalized = array_values(array_unique($normalized));
		$this->dependsOn = $normalized === [] ? null : json_encode($normalized, JSON_THROW_ON_ERROR);
		$this->markFieldUpdated('dependsOn');
	}

	/**
	 * Stored conditions as a list. A legacy single-condition object is upgraded
	 * to a one-element list, so the in-memory model is always a list.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getConditions(): array {
		if ($this->conditionConfig === null || $this->conditionConfig === '') {
			return [];
		}
		$decoded = json_decode($this->conditionConfig, true);
		if (!is_array($decoded)) {
			return [];
		}

		// Legacy single-condition object (`{"stepId":...}`).
		if (!array_is_list($decoded)) {
			return [self::stringKeyed($decoded)];
		}

		$conditions = [];
		foreach ($decoded as $entry) {
			if (is_array($entry) && !array_is_list($entry)) {
				$conditions[] = self::stringKeyed($entry);
			}
		}

		return $conditions;
	}

	/**
	 * @param list<array<string, mixed>> $conditions
	 */
	public function setConditions(array $conditions): void {
		$normalized = [];
		foreach ($conditions as $condition) {
			if (is_array($condition)) {
				$normalized[] = self::stringKeyed($condition);
			}
		}
		$this->conditionConfig = $normalized === [] ? null : json_encode($normalized, JSON_THROW_ON_ERROR);
		$this->markFieldUpdated('conditionConfig');
	}

	/**
	 * First stored condition, if any (backwards-compatible accessor).
	 *
	 * @return array<string, mixed>|null
	 */
	public function getCondition(): ?array {
		return $this->getConditions()[0] ?? null;
	}

	/**
	 * @param array<string, mixed>|null $condition
	 */
	public function setCondition(?array $condition): void {
		$this->setConditions($condition === null ? [] : [$condition]);
	}

	/**
	 * @return SectionData
	 */
	public function toArray(): array {
		return [
			'id' => (int)$this->id,
			'templateId' => $this->templateId,
			'title' => $this->title,
			'description' => $this->description,
			'notes' => $this->getNotes(),
			'position' => $this->position,
			'dependsOn' => $this->getDependsOnIds(),
			'condition' => $this->getCondition(),
			'conditions' => $this->getConditions(),
		];
	}

	/**
	 * @param array<mixed> $value
	 * @return array<string, mixed>
	 */
	private static function stringKeyed(array $value): array {
		$normalized = [];
		foreach ($value as $key => $item) {
			$normalized[(string)$key] = $item;
		}

		return $normalized;
	}

	/**
	 * @return list<int>
	 */
	private static function decodeIntList(?string $encoded): array {
		if ($encoded === null || $encoded === '') {
			return [];
		}
		$decoded = json_decode($encoded, true);
		if (!is_array($decoded)) {
			return [];
		}
		$ids = [];
		foreach ($decoded as $id) {
			if (is_int($id) || (is_string($id) && preg_match('/^[0-9]+$/', $id) === 1)) {
				$ids[] = (int)$id;
			}
		}

		return $ids;
	}
}
