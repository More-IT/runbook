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
 * @method string|null getDestinationViewUid()
 * @method void setDestinationViewUid(?string $destinationViewUid)
 * @method string|null getDestinationSource()
 * @method void setDestinationSource(?string $destinationSource)
 * @method string|null getDestinationStorageId()
 * @method void setDestinationStorageId(?string $destinationStorageId)
 * @method int|null getDestinationFileId()
 * @method void setDestinationFileId(?int $destinationFileId)
 * @method string|null getDestinationPath()
 * @method void setDestinationPath(?string $destinationPath)
 * @method string|null getDestinationConfiguredBy()
 * @method void setDestinationConfiguredBy(?string $destinationConfiguredBy)
 * @method int|null getDestinationStorageRootId()
 * @method void setDestinationStorageRootId(?int $destinationStorageRootId)
 * @method string|null getDestinationMountType()
 * @method void setDestinationMountType(?string $destinationMountType)
 * @method string|null getDestinationMountProvider()
 * @method void setDestinationMountProvider(?string $destinationMountProvider)
 * @method int|null getDestinationMountId()
 * @method void setDestinationMountId(?int $destinationMountId)
 * @method int|null getDestinationNumericStorageId()
 * @method void setDestinationNumericStorageId(?int $destinationNumericStorageId)
 * @method int|null getRunFolderFileId()
 * @method void setRunFolderFileId(?int $runFolderFileId)
 * @method string|null getRunFolderStorageId()
 * @method void setRunFolderStorageId(?string $runFolderStorageId)
 * @method string|null getRunFolderPath()
 * @method void setRunFolderPath(?string $runFolderPath)
 * @method int|null getRunFolderStorageRootId()
 * @method void setRunFolderStorageRootId(?int $runFolderStorageRootId)
 * @method string|null getRunFolderMountType()
 * @method void setRunFolderMountType(?string $runFolderMountType)
 * @method string|null getRunFolderMountProvider()
 * @method void setRunFolderMountProvider(?string $runFolderMountProvider)
 * @method int|null getRunFolderMountId()
 * @method void setRunFolderMountId(?int $runFolderMountId)
 * @method int|null getRunFolderNumericStorageId()
 * @method void setRunFolderNumericStorageId(?int $runFolderNumericStorageId)
 * @method int|null getDestinationMigratedAt()
 * @method void setDestinationMigratedAt(?int $destinationMigratedAt)
 * @method string|null getMigrationState()
 * @method void setMigrationState(?string $migrationState)
 * @method string|null getMigrationReason()
 * @method void setMigrationReason(?string $migrationReason)
 * @method int|null getMigrationAttemptedAt()
 * @method void setMigrationAttemptedAt(?int $migrationAttemptedAt)
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
	protected ?string $destinationViewUid = null;
	protected ?string $destinationSource = null;
	protected ?string $destinationStorageId = null;
	protected ?int $destinationFileId = null;
	protected ?string $destinationPath = null;
	protected ?string $destinationConfiguredBy = null;
	protected ?int $destinationStorageRootId = null;
	protected ?string $destinationMountType = null;
	protected ?string $destinationMountProvider = null;
	protected ?int $destinationMountId = null;
	protected ?int $destinationNumericStorageId = null;
	protected ?int $runFolderFileId = null;
	protected ?string $runFolderStorageId = null;
	protected ?string $runFolderPath = null;
	protected ?int $runFolderStorageRootId = null;
	protected ?string $runFolderMountType = null;
	protected ?string $runFolderMountProvider = null;
	protected ?int $runFolderMountId = null;
	protected ?int $runFolderNumericStorageId = null;
	protected ?int $destinationMigratedAt = null;
	protected ?string $migrationState = null;
	protected ?string $migrationReason = null;
	protected ?int $migrationAttemptedAt = null;

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
		$this->addType('destinationViewUid', Types::STRING);
		$this->addType('destinationSource', Types::STRING);
		$this->addType('destinationStorageId', Types::TEXT);
		$this->addType('destinationFileId', Types::BIGINT);
		$this->addType('destinationPath', Types::TEXT);
		$this->addType('destinationConfiguredBy', Types::STRING);
		$this->addType('destinationStorageRootId', Types::BIGINT);
		$this->addType('destinationMountType', Types::TEXT);
		$this->addType('destinationMountProvider', Types::TEXT);
		$this->addType('destinationMountId', Types::INTEGER);
		$this->addType('destinationNumericStorageId', Types::INTEGER);
		$this->addType('runFolderFileId', Types::BIGINT);
		$this->addType('runFolderStorageId', Types::TEXT);
		$this->addType('runFolderPath', Types::TEXT);
		$this->addType('runFolderStorageRootId', Types::BIGINT);
		$this->addType('runFolderMountType', Types::TEXT);
		$this->addType('runFolderMountProvider', Types::TEXT);
		$this->addType('runFolderMountId', Types::INTEGER);
		$this->addType('runFolderNumericStorageId', Types::INTEGER);
		$this->addType('destinationMigratedAt', Types::BIGINT);
		$this->addType('migrationState', Types::STRING);
		$this->addType('migrationReason', Types::STRING);
		$this->addType('migrationAttemptedAt', Types::BIGINT);
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
