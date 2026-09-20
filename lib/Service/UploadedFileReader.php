<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

/**
 * Reads an uploaded file from PHP's upload handling.
 *
 * Isolated behind a small service so attachment logic stays unit testable.
 */
class UploadedFileReader {
	/**
	 * @param array<array-key, mixed> $file Raw entry from the request upload array.
	 * @return array{name: string, content: string}
	 */
	public function read(array $file): array {
		if (($file['error'] ?? null) !== UPLOAD_ERR_OK) {
			throw new ValidationException('attachment_upload_failed');
		}

		$tmpName = $file['tmp_name'] ?? null;
		if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) {
			throw new ValidationException('attachment_upload_failed');
		}

		$name = $file['name'] ?? null;
		if (!is_string($name)) {
			throw new ValidationException('attachment_invalid_filename');
		}

		$content = file_get_contents($tmpName);
		if ($content === false) {
			throw new ValidationException('attachment_upload_failed');
		}

		return ['name' => $name, 'content' => $content];
	}
}
