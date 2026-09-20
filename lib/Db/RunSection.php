<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A section snapshot inside a run.
 *
 * @method int getRunId()
 * @method void setRunId(int $runId)
 * @method int|null getSourceSectionId()
 * @method void setSourceSectionId(?int $sourceSectionId)
 * @method string getTitle()
 * @method string getDescription()
 * @method int getPosition()
 * @method void setPosition(int $position)
 *
 * @phpstan-type RunSectionData array{
 *     id: int,
 *     runId: int,
 *     sourceSectionId: int|null,
 *     title: string,
 *     description: string,
 *     position: int
 * }
 */
class RunSection extends Entity {
	protected int $runId = 0;
	protected ?int $sourceSectionId = null;
	protected string $title = '';
	protected string $description = '';
	protected int $position = 0;

	public function __construct() {
		$this->addType('runId', Types::BIGINT);
		$this->addType('sourceSectionId', Types::BIGINT);
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
	 * @return RunSectionData
	 */
	public function toArray(): array {
		return [
			'id' => (int)$this->id,
			'runId' => $this->runId,
			'sourceSectionId' => $this->sourceSectionId,
			'title' => $this->title,
			'description' => $this->description,
			'position' => $this->position,
		];
	}
}
