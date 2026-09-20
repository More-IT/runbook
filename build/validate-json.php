<?php

/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Validates that all JSON files are well-formed and that the translation sets
 * keep the same keys and placeholders as the English source.
 */

declare(strict_types=1);

/**
 * @return list<string>
 */
function placeholders(string $value): array {
	preg_match_all('/\{[a-zA-Z0-9_]+\}/', $value, $matches);
	$placeholders = $matches[0];
	sort($placeholders);

	return $placeholders;
}

$root = dirname(__DIR__);
$errors = [];

foreach (['composer.json', 'package.json'] as $file) {
	$path = $root . '/' . $file;
	if (!is_file($path)) {
		$errors[] = $file . ' is missing';
		continue;
	}
	try {
		json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	} catch (\JsonException $exception) {
		$errors[] = $file . ' is not valid JSON: ' . $exception->getMessage();
	}
}

$jsonFiles = glob($root . '/l10n/*.json');
if ($jsonFiles === false) {
	$jsonFiles = [];
}
foreach ($jsonFiles as $file) {
	try {
		$decoded = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	} catch (\JsonException $exception) {
		$errors[] = basename($file) . ' is not valid JSON: ' . $exception->getMessage();
		continue;
	}
	if (!is_array($decoded) || !isset($decoded['translations']) || !is_array($decoded['translations'])) {
		$errors[] = basename($file) . ' is not a valid translation file';
	}
}

$enPath = $root . '/l10n/en.json';
if (is_file($enPath)) {
	/** @var array{translations: array<string, string>} $en */
	$en = json_decode((string)file_get_contents($enPath), true, 512, JSON_THROW_ON_ERROR);
	foreach ($jsonFiles as $file) {
		if (basename($file) === 'en.json') {
			continue;
		}
		/** @var array{translations: array<string, string>} $translated */
		$translated = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
		$name = basename($file);
		foreach ($en['translations'] as $key => $value) {
			if (!array_key_exists($key, $translated['translations'])) {
				$errors[] = sprintf('%s is missing the key: %s', $name, $key);
				continue;
			}
			if (placeholders($value) !== placeholders($translated['translations'][$key])) {
				$errors[] = sprintf('%s has a placeholder mismatch for: %s', $name, $key);
			}
		}
		if (count($translated['translations']) !== count($en['translations'])) {
			$errors[] = sprintf('%s has a different number of keys than en.json', $name);
		}
	}
}

if ($errors !== []) {
	fwrite(STDERR, "JSON validation failed:\n - " . implode("\n - ", $errors) . "\n");
	exit(1);
}

echo "JSON validation OK\n";
