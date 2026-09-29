<?php

/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Creates a production release package for the Runbook app.
 *
 * The staging tree contains only runtime files. Development-only directories
 * and the TypeScript/Vue sources are excluded because the compiled assets are
 * shipped. The archive root is the `runbook/` directory expected by Nextcloud.
 *
 * Usage:
 *   php build/create-package.php [--out=<dir>]
 */

declare(strict_types=1);

ini_set('phar.readonly', '0');

$root = dirname(__DIR__);
$options = parseOptions($argv);
$outDir = rtrim($options['out'] ?? (sys_get_temp_dir() . '/runbook-release'), "/\\");

$version = readVersion($root . '/appinfo/info.xml');
$stagingParent = $outDir;
$staging = $stagingParent . '/runbook';
$archive = $outDir . '/runbook-' . $version . '.tar.gz';
$fixedMtime = 1704067200; // 2024-01-01T00:00:00Z for reproducible archives.

// Runtime directories and files only.
$includeDirs = ['appinfo', 'lib', 'templates', 'img', 'l10n', 'css', 'js'];
$includeFiles = [
	'README.md',
	'LICENSE',
	'SECURITY.md',
	'CHANGELOG.md',
	'openapi.json',
	// Documentation linked from the packaged README; keep this list in sync with
	// the relative links in README.md (validate-package.php checks them).
	'docs/ux-architecture.md',
	'docs/manual-acceptance-checklist.md',
	'docs/folder-model.md',
	'docs/official-1.0.0-readiness.md',
	'docs/release-validation-2026-09-29.md',
];

removeDirectory($stagingParent);
if (!mkdir($staging, 0777, true) && !is_dir($staging)) {
	fwrite(STDERR, 'Could not create staging directory: ' . $staging . "\n");
	exit(1);
}

foreach ($includeDirs as $dir) {
	$source = $root . '/' . $dir;
	if (!is_dir($source)) {
		fwrite(STDERR, 'Missing required directory: ' . $dir . "\n");
		exit(1);
	}
	copyDirectory($source, $staging . '/' . $dir, $fixedMtime);
}
foreach ($includeFiles as $file) {
	$source = $root . '/' . $file;
	if (!is_file($source)) {
		fwrite(STDERR, 'Missing required file: ' . $file . "\n");
		exit(1);
	}
	$destination = $staging . '/' . $file;
	$destinationDir = dirname($destination);
	if (!is_dir($destinationDir) && !mkdir($destinationDir, 0777, true) && !is_dir($destinationDir)) {
		fwrite(STDERR, 'Could not create directory: ' . $destinationDir . "\n");
		exit(1);
	}
	if (!copy($source, $destination)) {
		fwrite(STDERR, 'Could not copy file: ' . $file . "\n");
		exit(1);
	}
	touch($destination, $fixedMtime);
}

// Validate the staging tree before archiving it.
$validate = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/build/validate-package.php')
	. ' ' . escapeshellarg('--package=' . $staging) . ' ' . escapeshellarg('--version=' . $version);
$exitCode = 0;
passthru($validate, $exitCode);
if ($exitCode !== 0) {
	fwrite(STDERR, "Staging tree failed validation; archive not created.\n");
	exit($exitCode);
}

buildArchive($stagingParent, $archive);

echo 'Created ' . $archive . ' (' . filesize($archive) . " bytes)\n";

/**
 * @return array{out?: string}
 */
function parseOptions(array $argv): array {
	$options = [];
	foreach (array_slice($argv, 1) as $argument) {
		if (str_starts_with($argument, '--out=')) {
			$options['out'] = substr($argument, strlen('--out='));
		}
	}

	return $options;
}

function readVersion(string $infoXml): string {
	$document = simplexml_load_file($infoXml);
	if ($document === false) {
		fwrite(STDERR, "Could not read app version from info.xml\n");
		exit(1);
	}
	$version = (string)($document->version ?? '');
	if ($version === '') {
		fwrite(STDERR, "info.xml has no version\n");
		exit(1);
	}

	return $version;
}

function removeDirectory(string $path): void {
	if (!is_dir($path)) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST,
	);
	foreach ($iterator as $item) {
		if ($item instanceof SplFileInfo) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
	}
	rmdir($path);
}

function copyDirectory(string $source, string $target, int $mtime): void {
	if (!mkdir($target, 0777, true) && !is_dir($target)) {
		fwrite(STDERR, 'Could not create directory: ' . $target . "\n");
		exit(1);
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST,
	);
	foreach ($iterator as $item) {
		if (!$item instanceof SplFileInfo) {
			continue;
		}
		$relative = substr($item->getPathname(), strlen($source) + 1);
		$destination = $target . '/' . $relative;
		if ($item->isDir()) {
			if (!is_dir($destination) && !mkdir($destination, 0777, true)) {
				fwrite(STDERR, 'Could not create directory: ' . $destination . "\n");
				exit(1);
			}
		} elseif (!str_ends_with($item->getFilename(), '.map')) {
			if (!copy($item->getPathname(), $destination)) {
				fwrite(STDERR, 'Could not copy: ' . $relative . "\n");
				exit(1);
			}
			touch($destination, $mtime);
		}
	}
	touch($target, $mtime);
}

/**
 * Build a deterministic tar.gz whose root entry is the `runbook/` directory.
 *
 * Uses GNU tar with sorted entries, a fixed modification time and numeric
 * ownership, and gzip with the timestamp header disabled (`-n`), so repeated
 * builds of the same content produce a byte-identical archive.
 */
function buildArchive(string $stagingParent, string $archive): void {
	if (is_file($archive)) {
		unlink($archive);
	}
	$tarPath = preg_replace('/\.gz$/', '', $archive) ?? ($archive . '.tar');
	if (is_file($tarPath)) {
		unlink($tarPath);
	}

	$tar = is_file('C:\\Program Files\\Git\\usr\\bin\\tar.exe') ? 'C:\\Program Files\\Git\\usr\\bin\\tar.exe' : 'tar';
	$gzip = is_file('C:\\Program Files\\Git\\usr\\bin\\gzip.exe') ? 'C:\\Program Files\\Git\\usr\\bin\\gzip.exe' : 'gzip';

	$tarCommand = escapeshellarg($tar)
		. ' --force-local --sort=name --mtime=@1704067200 --owner=0 --group=0 --numeric-owner'
		. ' -cf ' . escapeshellarg($tarPath)
		. ' -C ' . escapeshellarg($stagingParent)
		. ' runbook';
	runCommand($tarCommand, 'tar');

	$gzipCommand = escapeshellarg($gzip) . ' -n -9 -c ' . escapeshellarg($tarPath) . ' > ' . escapeshellarg($archive);
	runCommand($gzipCommand, 'gzip');

	if (is_file($tarPath)) {
		unlink($tarPath);
	}
}

function runCommand(string $command, string $name): void {
	$output = [];
	$exitCode = 0;
	exec($command, $output, $exitCode);
	if ($exitCode !== 0) {
		fwrite(STDERR, sprintf("%s command failed (exit %d): %s\n", $name, $exitCode, implode(' ', $output)));
		exit(1);
	}
}
