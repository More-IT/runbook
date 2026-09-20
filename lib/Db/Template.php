<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string getTitle()
 * @method string getDescription()
 * @method int getVersion()
 * @method void setVersion(int $version)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getOwner()
 * @method void setOwner(string $owner)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method int|null getPublishedAt()
 * @method void setPublishedAt(?int $publishedAt)
 * @method int|null getArchivedAt()
 * @method void setArchivedAt(?int $archivedAt)
 *
 * @phpstan-type TemplateData array{
 *     id: int,
 *     uuid: string,
 *     title: string,
 *     description: string,
 *     version: int,
 *     status: string,
 *     owner: string,
 *     createdAt: int,
 *     updatedAt: int,
 *     publishedAt: int|null,
 *     archivedAt: int|null
 * }
 */
class Template extends Entity {
	protected string $uuid = '';
	protected string $title = '';
	protected string $description = '';
	protected int $version = 0;
	protected string $status = '';
	protected string $owner = '';
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected ?int $publishedAt = null;
	protected ?int $archivedAt = null;

	public function __construct() {
		$this->addType('uuid', Types::STRING);
		$this->addType('title', Types::STRING);
		$this->addType('description', Types::TEXT);
		$this->addType('version', Types::INTEGER);
		$this->addType('status', Types::STRING);
		$this->addType('owner', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->addType('publishedAt', Types::BIGINT);
		$this->addType('archivedAt', Types::BIGINT);
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
	 * @return TemplateData
	 */
	public function toArray(): array {
		return [
			'id' => (int)$this->id,
			'uuid' => $this->uuid,
			'title' => $this->title,
			'description' => $this->description,
			'version' => $this->version,
			'status' => $this->status,
			'owner' => $this->owner,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'publishedAt' => $this->publishedAt,
			'archivedAt' => $this->archivedAt,
		];
	}
}
