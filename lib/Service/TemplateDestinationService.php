<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\Template;

/**
 * Reads, validates and captures the optional per-template destination reference
 * (issue #48).
 *
 * The reference follows docs/folder-model.md §4.2: the authoritative identity is
 * the exact `(storageId, fileId)` pair, `path` is advisory display text and
 * `configuredBy` records the chooser for audit only. A template with none of the
 * destination columns set has no destination and falls through to the next
 * precedence level; a partially populated reference is invalid and fails closed.
 */
class TemplateDestinationService {
	public function __construct(
		private readonly RunDestinationResolver $resolver,
	) {
	}

	/**
	 * Capture a folder reference from a user-visible path in the chooser's own
	 * Files view (configuration time). Identity is always derived server-side.
	 *
	 * @throws ValidationException|ConflictException
	 */
	public function capture(string $chooserUid, string $path): DestinationReference {
		return $this->resolver->captureReference($chooserUid, $path);
	}

	/**
	 * @return DestinationReference|null null when the template has no
	 *                                   destination configured.
	 *
	 * @throws ValidationException When the stored reference is partial/corrupt.
	 */
	public function readReference(Template $template): ?DestinationReference {
		$storageId = $template->getDestinationStorageId();
		$fileId = $template->getDestinationFileId();
		$path = $template->getDestinationPath();
		$configuredBy = $template->getDestinationConfiguredBy();

		if ($storageId === null && $fileId === null && $path === null && $configuredBy === null) {
			return null;
		}

		if ($storageId === null || $storageId === ''
			|| $fileId === null || $fileId <= 0
			|| $configuredBy === null || $configuredBy === '') {
			throw new ValidationException('destination_invalid_config');
		}

		return new DestinationReference($storageId, $fileId, $path, $configuredBy);
	}

	/**
	 * UI/API description of the template destination. Never exposes internal
	 * identity (storage id / file id).
	 *
	 * @return array{configured: bool, valid: bool, path: string|null, configuredBy: string|null}
	 */
	public function describe(Template $template): array {
		try {
			$reference = $this->readReference($template);
		} catch (ValidationException) {
			return [
				'configured' => true,
				'valid' => false,
				'path' => null,
				'configuredBy' => null,
			];
		}

		if ($reference === null) {
			return [
				'configured' => false,
				'valid' => false,
				'path' => null,
				'configuredBy' => null,
			];
		}

		return [
			'configured' => true,
			'valid' => true,
			'path' => $reference->path,
			'configuredBy' => $reference->configuredBy,
		];
	}
}
