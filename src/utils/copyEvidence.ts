/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure helpers for attaching a copy of an existing Files item as evidence
 * (issue #53).
 *
 * The browser only ever sends an advisory, user-visible source path; the server
 * resolves and authorises the source in the acting user's own Files and copies
 * the bytes into the run-managed folder. The original file is never changed.
 * These helpers take the translator as a parameter so the exact rendered copy is
 * unit tested without a DOM/component harness.
 */

import type { translate } from '@nextcloud/l10n'

type Translate = typeof translate

/**
 * Normalize a picker result or raw value into a source path.
 *
 * The picker is used in single-select mode, so the value is normally a string;
 * arrays are handled defensively, and empty values become null (a no-op).
 *
 * @param value Value returned by the picker or supplied by a caller.
 */
export function normalizeSourcePath(value: unknown): string | null {
	if (typeof value === 'string') {
		const trimmed = value.trim()
		return trimmed === '' ? null : trimmed
	}
	if (Array.isArray(value) && value.length > 0 && typeof value[0] === 'string') {
		const trimmed = value[0].trim()
		return trimmed === '' ? null : trimmed
	}

	return null
}

/**
 * Build the JSON body of the copy request.
 *
 * @param path The selected, non-empty source path.
 */
export function buildCopyEvidencePayload(path: string): { sourcePath: string } {
	const normalized = normalizeSourcePath(path)
	if (normalized === null) {
		throw new Error('copy_source_path_required')
	}

	return { sourcePath: normalized }
}

/**
 * Concise, localized explanation that Runbook copies the item.
 *
 * @param t Translator.
 */
export function copyEvidenceHint(t: Translate): string {
	return t('runbook', 'A copy is stored in the run folder; the original stays where it is.')
}
