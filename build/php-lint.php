<?php

/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Runs `php -l` over all application and test PHP files.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$directories = [$root . '/lib', $root . '/tests', $root . '/build'];
$failures = [];
$checked = 0;

foreach ($directories as $directory) {
	if (!is_dir($directory)) {
		continue;
	}

	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
	foreach ($iterator as $file) {
		if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
			continue;
		}

		$checked++;
		$command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1';
		$output = [];
		$exitCode = 0;
		exec($command, $output, $exitCode);
		if ($exitCode !== 0) {
			$failures[] = $file->getPathname() . ': ' . implode(' ', $output);
		}
	}
}

if ($failures !== []) {
	fwrite(STDERR, "PHP syntax check failed:\n - " . implode("\n - ", $failures) . "\n");
	exit(1);
}

echo sprintf("PHP syntax check OK (%d files)\n", $checked);
