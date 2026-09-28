<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

/**
 * The Files destination resolved for a run start (issue #46).
 *
 * Identity (authoritative): `viewUid` + `storageId` + `fileId`. All other
 * fields are non-authoritative descriptor metadata recorded for audit/display
 * only and must never be used as an identity, deduplication or tie-break key
 * (see docs/folder-model.md §2.1.1).
 */
final class ResolvedRunDestination {
	public const SOURCE_DEFAULT = 'default';
	public const SOURCE_ADMIN = 'admin';
	public const SOURCE_TEMPLATE = 'template';
	public const SOURCE_RUNTIME = 'runtime';

	public function __construct(
		public readonly string $runUuid,
		public readonly string $viewUid,
		public readonly string $source,
		public readonly ?string $configuredBy,
		public readonly string $storageId,
		public readonly int $fileId,
		public readonly ?string $path,
		public readonly int $storageRootId,
		public readonly string $mountType,
		public readonly string $mountProvider,
		public readonly ?int $mountId,
		public readonly ?int $numericStorageId,
		public readonly int $folderFileId,
		public readonly string $folderStorageId,
		public readonly ?string $folderPath,
		public readonly int $folderStorageRootId,
		public readonly string $folderMountType,
		public readonly string $folderMountProvider,
		public readonly ?int $folderMountId,
		public readonly ?int $folderNumericStorageId,
		public readonly bool $folderCreated,
	) {
	}
}
