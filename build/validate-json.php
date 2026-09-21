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
		continue;
	}

	// Translation keys must live inside "translations": stray root keys are
	// silently ignored by Nextcloud and hide a broken localization build.
	$rootKeys = array_keys($decoded);
	sort($rootKeys);
	if ($rootKeys !== ['pluralForm', 'translations']) {
		$errors[] = sprintf(
			'%s has unexpected root keys (%s); expected only "translations" and "pluralForm"',
			basename($file),
			implode(', ', $rootKeys),
		);
	}

	/** @var array<string, string> $translations */
	$translations = $decoded['translations'];

	// The generated .js is what Nextcloud loads at runtime and must mirror the
	// JSON source exactly.
	$jsPath = preg_replace('/\.json$/', '.js', $file) ?? '';
	if (!is_file($jsPath)) {
		$errors[] = basename($file) . ' has no generated .js counterpart';
	} else {
		$js = (string)file_get_contents($jsPath);
		if (!str_starts_with($js, 'OC.L10N.register(')) {
			$errors[] = basename($jsPath) . ' does not start with OC.L10N.register(';
		}
		foreach (array_keys($translations) as $key) {
			$encoded = json_encode((string)$key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if (!is_string($encoded) || !str_contains($js, $encoded)) {
				$errors[] = sprintf('%s is missing the key: %s', basename($jsPath), $key);
			}
		}
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

// pt_BR intentionally mirrors pt_PT until a dedicated translation update
// diverges them; the two catalogs must stay byte-for-value identical.
$ptPtPath = $root . '/l10n/pt_PT.json';
$ptBrPath = $root . '/l10n/pt_BR.json';
if (is_file($ptPtPath) && is_file($ptBrPath)) {
	try {
		$ptPt = json_decode((string)file_get_contents($ptPtPath), true, 512, JSON_THROW_ON_ERROR);
		$ptBr = json_decode((string)file_get_contents($ptBrPath), true, 512, JSON_THROW_ON_ERROR);
		$ptPtTranslations = [];
		if (is_array($ptPt) && isset($ptPt['translations']) && is_array($ptPt['translations'])) {
			foreach ($ptPt['translations'] as $key => $value) {
				if (is_string($value)) {
					$ptPtTranslations[(string)$key] = $value;
				}
			}
		}
		$ptBrTranslations = [];
		if (is_array($ptBr) && isset($ptBr['translations']) && is_array($ptBr['translations'])) {
			foreach ($ptBr['translations'] as $key => $value) {
				if (is_string($value)) {
					$ptBrTranslations[(string)$key] = $value;
				}
			}
		}
		if (array_keys($ptPtTranslations) !== array_keys($ptBrTranslations)) {
			$errors[] = 'pt_BR.json must have the same keys as pt_PT.json';
		}
		foreach ($ptBrTranslations as $key => $value) {
			if (!array_key_exists($key, $ptPtTranslations) || $ptPtTranslations[$key] !== $value) {
				$errors[] = 'pt_BR.json must currently mirror pt_PT.json for: ' . $key;
				break;
			}
		}
	} catch (\JsonException $exception) {
		$errors[] = 'pt_PT.json / pt_BR.json are not valid JSON: ' . $exception->getMessage();
	}
}

if ($errors !== []) {
	fwrite(STDERR, "JSON validation failed:\n - " . implode("\n - ", $errors) . "\n");
	exit(1);
}

echo "JSON validation OK\n";
