<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A run is an independent snapshot of a template at the time it was started.
 *
 * The snapshot data in {@see RunSection} and {@see RunStep} is authoritative
 * for execution; the optional template id is provenance only and is cleared
 * (SET NULL) when the source template is deleted.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method int|null getTemplateId()
 * @method void setTemplateId(?int $templateId)
 * @method int getTemplateVersion()
 * @method void setTemplateVersion(int $templateVersion)
 * @method string getTitle()
 * @method string getDescription()
 * @method string getOwner()
 * @method void setOwner(string $owner)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getStartedAt()
 * @method void setStartedAt(int $startedAt)
 * @method int|null getCompletedAt()
 * @method void setCompletedAt(?int $completedAt)
 * @method int|null getCancelledAt()
 * @method void setCancelledAt(?int $cancelledAt)
 * @method int|null getReopenedAt()
 * @method void setReopenedAt(?int $reopenedAt)
 * @method int|null getDueAt()
 * @method void setDueAt(?int $dueAt)
 * @method string|null getCompletedBy()
 * @method void setCompletedBy(?string $completedBy)
 * @method string|null getCancelledBy()
 * @method void setCancelledBy(?string $cancelledBy)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 *
 * @phpstan-type RunData array{
 *     id: int,
 *     uuid: string,
 *     templateId: int|null,
 *     templateVersion: int,
 *     title: string,
 *     description: string,
 *     owner: string,
 *     status: string,
 *     createdAt: int,
 *     startedAt: int,
 *     completedAt: int|null,
 *     cancelledAt: int|null,
 *     reopenedAt: int|null,
 *     dueAt: int|null,
 *     completedBy: string|null,
 *     cancelledBy: string|null,
 *     updatedAt: int
 * }
 */
class Run extends Entity {
	protected string $uuid = '';
	protected ?int $templateId = null;
	protected int $templateVersion = 1;
	protected string $title = '';
	protected string $description = '';
	protected string $owner = '';
	protected string $status = '';
	protected int $createdAt = 0;
	protected int $startedAt = 0;
	protected ?int $completedAt = null;
	protected ?int $cancelledAt = null;
	protected ?int $reopenedAt = null;
	protected ?int $dueAt = null;
	protected ?string $completedBy = null;
	protected ?string $cancelledBy = null;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('uuid', Types::STRING);
		$this->addType('templateId', Types::BIGINT);
		$this->addType('templateVersion', Types::INTEGER);
		$this->addType('title', Types::STRING);
		$this->addType('description', Types::TEXT);
		$this->addType('owner', Types::STRING);
		$this->addType('status', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('startedAt', Types::BIGINT);
		$this->addType('completedAt', Types::BIGINT);
		$this->addType('cancelledAt', Types::BIGINT);
		$this->addType('reopenedAt', Types::BIGINT);
		$this->addType('dueAt', Types::BIGINT);
		$this->addType('completedBy', Types::STRING);
		$this->addType('cancelledBy', Types::STRING);
		$this->addType('updatedAt', Types::BIGINT);
	}

	/**
	 * Explicit setter so that the snapshot version is always persisted.
	 *
	 * The default is 1, which equals the first published template version, so a
	 * magic setter would skip the field when the value is unchanged and the
	 * NOT NULL column would be omitted from the INSERT.
	 */
	public function setTemplateVersion(int $templateVersion): void {
		$this->templateVersion = $templateVersion;
		$this->markFieldUpdated('templateVersion');
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
	 * @return RunData
	 */
	public function toArray(): array {
		return [
			'id' => (int)$this->id,
			'uuid' => $this->uuid,
			'templateId' => $this->templateId,
			'templateVersion' => $this->templateVersion,
			'title' => $this->title,
			'description' => $this->description,
			'owner' => $this->owner,
			'status' => $this->status,
			'createdAt' => $this->createdAt,
			'startedAt' => $this->startedAt,
			'completedAt' => $this->completedAt,
			'cancelledAt' => $this->cancelledAt,
			'reopenedAt' => $this->reopenedAt,
			'dueAt' => $this->dueAt,
			'completedBy' => $this->completedBy,
			'cancelledBy' => $this->cancelledBy,
			'updatedAt' => $this->updatedAt,
		];
	}
}
