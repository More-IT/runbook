<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * Evidence file storage backed by Nextcloud AppData.
 *
 * Files are stored under runs/{runId}/steps/{stepId}/evidence/{storageKey}.
 * All path segments except the storage key are application-controlled
 * integers; the storage key is a random identifier that is never derived from
 * user input. Physical paths are never exposed through the API.
 */
class EvidenceStorage {
	private const APP_ID = 'runbook';
	private const BASE_FOLDER = 'runs';

	public function __construct(
		private readonly IAppDataFactory $appDataFactory,
	) {
	}

	private function appData(): IAppData {
		return $this->appDataFactory->get(self::APP_ID);
	}

	public function write(int $runId, int $stepId, string $storageKey, string $content): void {
		$this->folder($runId, $stepId, true)->newFile($storageKey, $content);
	}

	public function read(int $runId, int $stepId, string $storageKey): string {
		return $this->folder($runId, $stepId, false)->getFile($storageKey)->getContent();
	}

	public function delete(int $runId, int $stepId, string $storageKey): void {
		try {
			$this->folder($runId, $stepId, false)->getFile($storageKey)->delete();
		} catch (NotFoundException) {
			// The file is already gone; deletion stays idempotent.
		}
	}

	private function folder(int $runId, int $stepId, bool $create): ISimpleFolder {
		$runs = $this->root($create);
		$run = $this->child($runs, (string)$runId, $create);
		$steps = $this->child($run, 'steps', $create);
		$step = $this->child($steps, (string)$stepId, $create);

		return $this->child($step, 'evidence', $create);
	}

	private function root(bool $create): ISimpleFolder {
		$appData = $this->appData();
		if ($create) {
			try {
				return $appData->getFolder(self::BASE_FOLDER);
			} catch (NotFoundException) {
				return $appData->newFolder(self::BASE_FOLDER);
			}
		}

		return $appData->getFolder(self::BASE_FOLDER);
	}

	private function child(ISimpleFolder $parent, string $name, bool $create): ISimpleFolder {
		if ($create) {
			try {
				return $parent->getFolder($name);
			} catch (NotFoundException) {
				return $parent->newFolder($name);
			}
		}

		return $parent->getFolder($name);
	}
}
