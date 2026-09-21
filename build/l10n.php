<?php

/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Localization pipeline for Runbook.
 *
 * For every `l10n/<locale>.json` this script:
 *  - repairs the file if translation keys leaked into the JSON root;
 *  - rewrites the canonical `{ "translations": {...}, "pluralForm": "..." }`
 *    structure with alphabetically sorted keys;
 *  - regenerates `l10n/<locale>.js` (the file Nextcloud actually loads at
 *    runtime) from the JSON source.
 *
 * Run it whenever translation keys change: `composer l10n:generate`.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$l10nDir = $root . '/l10n';
$appId = 'runbook';
$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
$exitCode = 0;

$files = glob($l10nDir . '/*.json') ?: [];
sort($files, SORT_STRING);
foreach ($files as $jsonPath) {
	$locale = basename($jsonPath, '.json');
	try {
		$data = json_decode((string)file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
	} catch (\JsonException $exception) {
		fwrite(STDERR, sprintf("%s is not valid JSON: %s\n", $locale, $exception->getMessage()));
		$exitCode = 1;
		continue;
	}
	if (!is_array($data)) {
		fwrite(STDERR, sprintf("%s is not a JSON object\n", $locale));
		$exitCode = 1;
		continue;
	}

	$translations = [];
	if (isset($data['translations']) && is_array($data['translations'])) {
		foreach ($data['translations'] as $key => $value) {
			if (is_string($value)) {
				$translations[(string)$key] = $value;
			}
		}
	}

	$repaired = [];
	foreach ($data as $key => $value) {
		if ($key === 'translations' || $key === 'pluralForm') {
			continue;
		}
		if (is_string($value) && !array_key_exists((string)$key, $translations)) {
			$translations[(string)$key] = $value;
			$repaired[] = (string)$key;
		}
	}

	$pluralForm = isset($data['pluralForm']) && is_string($data['pluralForm'])
		? $data['pluralForm']
		: 'nplurals=2; plural=(n != 1);';

	ksort($translations, SORT_STRING);

	writeJson($jsonPath, $translations, $pluralForm, $flags);
	writeJs($l10nDir . '/' . $locale . '.js', $appId, $translations, $pluralForm, $flags);

	$note = $repaired === [] ? '' : sprintf(' (repaired %d stray root key(s))', count($repaired));
	echo sprintf("Regenerated %s.json/.js with %d keys%s\n", $locale, count($translations), $note);
}

exit($exitCode);

/**
 * @param array<string, string> $translations
 */
function writeJson(string $path, array $translations, string $pluralForm, int $flags): void {
	$lines = ['{', '  "translations": {'];
	$entries = [];
	foreach ($translations as $key => $value) {
		$entries[] = '    ' . json_encode($key, $flags) . ': ' . json_encode($value, $flags);
	}
	$lines[] = implode(",\n", $entries);
	$lines[] = '  },';
	$lines[] = '  "pluralForm": ' . json_encode($pluralForm, $flags);
	$lines[] = '}';

	file_put_contents($path, implode("\n", $lines) . "\n");
}

/**
 * @param array<string, string> $translations
 */
function writeJs(string $path, string $appId, array $translations, string $pluralForm, int $flags): void {
	$lines = ['OC.L10N.register(', '    ' . json_encode($appId, $flags) . ',', '    {'];
	$entries = [];
	foreach ($translations as $key => $value) {
		$entries[] = '        ' . json_encode($key, $flags) . ': ' . json_encode($value, $flags);
	}
	$lines[] = implode(",\n", $entries);
	$lines[] = '    },';
	$lines[] = '    ' . json_encode($pluralForm, $flags);
	$lines[] = ');';

	file_put_contents($path, implode("\n", $lines) . "\n");
}
