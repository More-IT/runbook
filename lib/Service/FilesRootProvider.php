<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCP\Files\Folder;

/**
 * Minimal Files root access used by Runbook.
 *
 * Kept as a narrow interface over `OCP\Files\IRootFolder` so the run destination
 * resolver can be exercised without loading the full Nextcloud Files stack.
 * Implementations use only public OCP APIs.
 */
interface FilesRootProvider {
	/**
	 * The user's Files view root (own files plus the shares/mounts available to
	 * them). Never another user's view.
	 */
	public function getUserFolder(string $uid): Folder;
}
