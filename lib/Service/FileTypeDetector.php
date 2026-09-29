<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

/**
 * Detects the MIME type of uploaded evidence from its actual content.
 *
 * Client-provided MIME types are never trusted.
 */
class FileTypeDetector {
	public function detect(string $content, string $_filename): string {
		$finfo = new \finfo(FILEINFO_MIME_TYPE);
		$detected = $finfo->buffer($content);
		if (!is_string($detected) || $detected === '') {
			return 'application/octet-stream';
		}

		return $detected;
	}
}
