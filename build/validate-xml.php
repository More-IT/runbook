<?php

/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Validates that the app XML metadata is well-formed and consistent.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

$infoPath = $root . '/appinfo/info.xml';
if (!is_file($infoPath)) {
	$errors[] = 'appinfo/info.xml is missing';
} else {
	$previous = libxml_use_internal_errors(true);
	$document = simplexml_load_file($infoPath);
	$libxmlErrors = libxml_get_errors();
	libxml_clear_errors();
	libxml_use_internal_errors($previous);

	if ($document === false) {
		foreach ($libxmlErrors as $error) {
			$errors[] = sprintf('info.xml: %s', trim($error->message));
		}
	} else {
		$id = (string)($document->id ?? '');
		$namespace = (string)($document->namespace ?? '');
		$version = (string)($document->version ?? '');

		if ($id !== 'runbook') {
			$errors[] = 'info.xml app id must be "runbook"';
		}
		if ($namespace !== 'Runbook') {
			$errors[] = 'info.xml namespace must be "Runbook"';
		}
		if ($version === '') {
			$errors[] = 'info.xml version is missing';
		}
		if (!isset($document->dependencies->nextcloud)) {
			$errors[] = 'info.xml is missing the Nextcloud dependency';
		}
	}
}

if ($errors !== []) {
	fwrite(STDERR, "XML validation failed:\n - " . implode("\n - ", $errors) . "\n");
	exit(1);
}

echo "XML validation OK\n";
