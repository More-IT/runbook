/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Helpers for the browser tab title.
 *
 * Nextcloud's `NcAppContent` builds `document.title` itself. When only
 * `pageHeading` is supplied it appends its own localized app name and the
 * instance name, which can stringify a non-string value to `[object Object]`.
 * Runbook therefore supplies an explicit, localized `pageTitle` string and
 * composes it here, so the title is deterministic and never contains an object.
 */

/**
 * Join title parts into a single, deduplicated, human-readable string.
 *
 * Non-string parts (for example an accidentally passed component prop object)
 * are dropped, and the literal `[object Object]` is never emitted. Empty parts
 * and duplicates are removed, so no text is duplicated.
 *
 * @param parts Candidate title parts, most specific first.
 */
export function buildPageTitle(parts: Array<string | null | undefined>): string {
	const seen = new Set<string>()
	const clean: string[] = []

	for (const part of parts) {
		if (typeof part !== 'string') {
			continue
		}
		const value = part.trim()
		if (value === '' || value === '[object Object]' || seen.has(value)) {
			continue
		}
		seen.add(value)
		clean.push(value)
	}

	return clean.join(' - ')
}
