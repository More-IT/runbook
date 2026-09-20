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
 * @method int getPosition()
 * @method void setPosition(int $position)
 *
 * @phpstan-type SectionData array{
 *     id: int,
 *     templateId: int,
 *     title: string,
 *     description: string,
 *     position: int
 * }
 */
class TemplateSection extends Entity {
	protected int $templateId = 0;
	protected string $title = '';
	protected string $description = '';
	protected int $position = 0;

	public function __construct() {
		$this->addType('templateId', Types::BIGINT);
		$this->addType('title', Types::STRING);
		$this->addType('description', Types::TEXT);
		$this->addType('position', Types::INTEGER);
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
	 * @return SectionData
	 */
	public function toArray(): array {
		return [
			'id' => (int)$this->id,
			'templateId' => $this->templateId,
			'title' => $this->title,
			'description' => $this->description,
			'position' => $this->position,
		];
	}
}
