<?php

/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Release/packaging validation.
 *
 * Two modes:
 *  - validate the development repository (default);
 *  - validate a production staging/release tree with --package=<dir>.
 *
 * It never creates the final archive itself; that is done by
 * build/create-package.php, which validates its staging tree with this script.
 */

declare(strict_types=1);

$options = parseOptions($argv);
$isPackage = isset($options['package']);
$root = $isPackage ? rtrim($options['package'], "/\\") : dirname(__DIR__);
$expectedVersion = $options['version'] ?? '0.2.0';

$errors = [];
$notes = [];

if ($isPackage) {
	// A production package must not contain development-only entries.
	$forbiddenPaths = [
		'node_modules',
		'vendor',
		'tests',
		'.github',
		'.git',
		'build',
		'src',
		'package.json',
		'package-lock.json',
		'composer.json',
		'composer.lock',
		'.env',
		'.editorconfig',
		'.php-cs-fixer.dist.php',
		'.php-cs-fixer.cache',
		'.phpunit.cache',
	];
	foreach ($forbiddenPaths as $forbidden) {
		if (file_exists($root . '/' . $forbidden)) {
			$errors[] = 'Development-only entry must not be packaged: ' . $forbidden;
		}
	}
	foreach (findFiles($root) as $relative) {
		$basename = basename($relative);
		if (str_ends_with($relative, '.map')) {
			$errors[] = 'Source map must not be packaged: ' . $relative;
		}
		if (str_ends_with($relative, '.log') || $basename === '.env') {
			$errors[] = 'Secret or log file must not be packaged: ' . $relative;
		}
	}
}

$requiredFiles = [
	'appinfo/info.xml',
	'appinfo/routes.php',
	'lib/AppInfo/Application.php',
	'templates/main.php',
	'templates/admin-settings.php',
	'img/app.svg',
	'img/app-dark.svg',
	'l10n/en.json',
	'l10n/en.js',
	'l10n/pt_PT.json',
	'l10n/pt_PT.js',
	'README.md',
	'LICENSE',
	'SECURITY.md',
];

foreach ($requiredFiles as $file) {
	if (!is_file($root . '/' . $file)) {
		$errors[] = 'Missing required file: ' . $file;
	}
}

// Generated frontend assets are only required once a build has run.
$requiredAssets = ['js/runbook-main.mjs', 'js/runbook-admin-settings.mjs', 'css/runbook-main.css'];
foreach ($requiredAssets as $asset) {
	if (!is_file($root . '/' . $asset)) {
		$errors[] = 'Missing generated asset (run "npm run build" first): ' . $asset;
	}
}

$infoXmlPath = $root . '/appinfo/info.xml';
if (is_file($infoXmlPath)) {
	$previous = libxml_use_internal_errors(true);
	$document = simplexml_load_file($infoXmlPath);
	libxml_clear_errors();
	libxml_use_internal_errors($previous);

	if ($document === false) {
		$errors[] = 'appinfo/info.xml is not well-formed XML';
	} else {
		$id = (string)($document->id ?? '');
		$namespace = (string)($document->namespace ?? '');
		$infoVersion = (string)($document->version ?? '');
		if ($id !== 'runbook') {
			$errors[] = 'info.xml app id must be "runbook"';
		}
		if ($namespace !== 'Runbook') {
			$errors[] = 'info.xml namespace must be "Runbook"';
		}
		if ($infoVersion !== $expectedVersion) {
			$errors[] = sprintf('Version mismatch: info.xml=%s, expected=%s', $infoVersion, $expectedVersion);
		}
		if (!$isPackage && is_file($root . '/package.json')) {
			$packageJson = json_decode((string)file_get_contents($root . '/package.json'), true);
			$packageVersion = is_array($packageJson) ? (string)($packageJson['version'] ?? '') : '';
			if ($packageVersion !== '' && $packageVersion !== $infoVersion) {
				$errors[] = sprintf('Version mismatch: info.xml=%s, package.json=%s', $infoVersion, $packageVersion);
			}
		}
		$databases = [];
		if (isset($document->dependencies->database)) {
			foreach ($document->dependencies->database as $database) {
				$databases[] = (string)$database;
			}
		}
		foreach (['mysql', 'pgsql', 'sqlite'] as $expected) {
			if (!in_array($expected, $databases, true)) {
				$errors[] = 'info.xml must declare the ' . $expected . ' database';
			}
		}
	}
}

// Translation files must be valid and keep English key/placeholder parity.
$enPath = $root . '/l10n/en.json';
if (is_file($enPath)) {
	try {
		/** @var array{translations: array<string, string>} $en */
		$en = json_decode((string)file_get_contents($enPath), true, 512, JSON_THROW_ON_ERROR);
		foreach (glob($root . '/l10n/*.json') ?: [] as $file) {
			$name = basename($file);
			try {
				/** @var array{translations: array<string, string>} $translated */
				$translated = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
			} catch (\JsonException $exception) {
				$errors[] = $name . ' is not valid JSON: ' . $exception->getMessage();
				continue;
			}
			if (count($translated['translations']) !== count($en['translations'])) {
				$errors[] = $name . ' has a different number of keys than en.json';
			}
			if ($name !== 'en.json') {
				foreach ($en['translations'] as $key => $value) {
					if (!array_key_exists($key, $translated['translations'])) {
						$errors[] = sprintf('%s is missing the key: %s', $name, $key);
						continue;
					}
					if (placeholders($value) !== placeholders($translated['translations'][$key])) {
						$errors[] = sprintf('%s has a placeholder mismatch for: %s', $name, $key);
					}
				}
			}
		}
	} catch (\JsonException $exception) {
		$errors[] = 'l10n/en.json is not valid JSON: ' . $exception->getMessage();
	}
}

if (!$isPackage) {
	foreach (['node_modules', 'vendor', '.git', 'tests', '.github', 'build', 'src'] as $devOnly) {
		if (is_dir($root . '/' . $devOnly)) {
			$notes[] = sprintf('Exclude from the release archive: %s/', $devOnly);
		}
	}
}

if ($errors !== []) {
	fwrite(STDERR, "Packaging validation failed:\n - " . implode("\n - ", $errors) . "\n");
	if ($notes !== []) {
		fwrite(STDERR, "Notes:\n - " . implode("\n - ", $notes) . "\n");
	}
	exit(1);
}

echo $isPackage ? "Package validation OK\n" : "Packaging validation OK\n";
if ($notes !== []) {
	echo "Packaging notes:\n - " . implode("\n - ", $notes) . "\n";
}

/**
 * @param list<string> $argv
 * @return array{package?: string, version?: string}
 */
function parseOptions(array $argv): array {
	$options = [];
	foreach (array_slice($argv, 1) as $argument) {
		if (str_starts_with($argument, '--package=')) {
			$options['package'] = substr($argument, strlen('--package='));
		} elseif (str_starts_with($argument, '--version=')) {
			$options['version'] = substr($argument, strlen('--version='));
		}
	}

	return $options;
}

/**
 * @return list<string>
 */
function findFiles(string $root): array {
	$files = [];
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST,
	);
	foreach ($iterator as $file) {
		if ($file instanceof SplFileInfo && $file->isFile()) {
			$files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
		}
	}

	return $files;
}

/**
 * @return list<string>
 */
function placeholders(string $value): array {
	preg_match_all('/\{[a-zA-Z0-9_]+\}/', $value, $matches);
	$placeholders = $matches[0];
	sort($placeholders);

	return $placeholders;
}
