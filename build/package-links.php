<?php

/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure helpers for validating the relative Markdown links of a packaged
 * README. Kept side-effect free so they can be unit tested directly; the
 * packaging validator requires this file.
 */

declare(strict_types=1);

/**
 * Extract relative Markdown link targets from a document.
 *
 * Absolute URLs, any other scheme (`mailto:`, …) and pure fragments are
 * ignored; a trailing anchor is stripped. Duplicate targets are returned once.
 *
 * @return list<string>
 */
function relativeMarkdownLinks(string $markdown): array {
	$links = [];
	preg_match_all('/\]\(([^)]+)\)/', $markdown, $matches);
	foreach ($matches[1] as $target) {
		$target = trim($target);
		if ($target === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|#)~i', $target) === 1) {
			continue;
		}
		$path = strtok($target, '#');
		if ($path === false || $path === '') {
			continue;
		}
		$links[] = $path;
	}

	/** @var list<string> $unique */
	$unique = array_values(array_unique($links));

	return $unique;
}

/**
 * Whether a relative link target could resolve outside a package tree.
 *
 * A packaged README may only reference files inside the staged package, so
 * absolute paths, drive-letter paths, UNC paths and any `..` path segment are
 * rejected before the file-existence check.
 */
function isUnsafeRelativeLink(string $link): bool {
	if ($link === '' || str_starts_with($link, '/') || str_starts_with($link, '\\')) {
		return true;
	}
	if (preg_match('~^[A-Za-z]:[\\\\/]~', $link) === 1 || str_starts_with($link, '//')) {
		return true;
	}

	return preg_match('~(^|[\\\\/])\.\.([\\\\/]|$)~', $link) === 1;
}
