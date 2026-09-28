<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;

/**
 * Production {@see FilesRootProvider} backed by the Nextcloud Files root.
 */
class NextcloudFilesRootProvider implements FilesRootProvider {
	public function __construct(
		private readonly IRootFolder $rootFolder,
	) {
	}

	public function getUserFolder(string $uid): Folder {
		return $this->rootFolder->getUserFolder($uid);
	}
}
