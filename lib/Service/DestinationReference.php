<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

/**
 * A configured Files folder reference (global administration setting, #47).
 *
 * Only the identity `(storageId, fileId)` is authoritative when the reference is
 * re-resolved in a user's view at run start. `path` is an advisory display value
 * (never identity) and `configuredBy` records the user who selected the folder
 * for audit only; neither is used for resolution.
 */
final class DestinationReference {
	public function __construct(
		public readonly string $storageId,
		public readonly int $fileId,
		public readonly ?string $path,
		public readonly string $configuredBy,
	) {
	}
}
