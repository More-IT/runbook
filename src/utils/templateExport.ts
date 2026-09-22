/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Helpers for downloading a template export as a `.json` file.
 */

const FALLBACK_NAME = 'runbook-template'
const MAX_SLUG_LENGTH = 80

/**
 * Safe, predictable filename for a template export.
 *
 * The title is normalised to lowercase ASCII alphanumerics joined by single
 * dashes, so path separators, control characters and reserved punctuation can
 * never appear. An empty result (e.g. a title with only non-latin characters)
 * falls back to `runbook-template.json`.
 *
 * @param title Template title.
 */
export function exportFilename(title: string): string {
	const slug = title
		.trim()
		.toLowerCase()
		.normalize('NFKD')
		.replace(/[\u0300-\u036f]/g, '')
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/-+/g, '-')
		.replace(/^-+|-+$/g, '')
		.slice(0, MAX_SLUG_LENGTH)
		.replace(/-+$/g, '')

	return `${slug === '' ? FALLBACK_NAME : slug}.json`
}

/**
 * Format an export document as the exact text written to the downloaded file.
 *
 * Kept as a small pure helper so the emitted bytes (and therefore the file the
 * user later re-imports) can be tested without a DOM harness. The 2-space
 * pretty-printing keeps exports human-readable.
 *
 * @param document Export document.
 */
export function formatTemplateExport(document: unknown): string {
	return JSON.stringify(document, null, 2)
}

/**
 * Trigger a browser download of the document as pretty-printed JSON.
 *
 * The object URL is always revoked, so no temporary URL is leaked.
 *
 * @param filename Download filename.
 * @param document Export document.
 */
export function downloadJson(filename: string, document: unknown): void {
	const blob = new Blob([formatTemplateExport(document)], { type: 'application/json' })
	const url = URL.createObjectURL(blob)
	try {
		const anchor = window.document.createElement('a')
		anchor.href = url
		anchor.download = filename
		anchor.rel = 'noopener'
		window.document.body.appendChild(anchor)
		anchor.click()
		anchor.remove()
	} finally {
		URL.revokeObjectURL(url)
	}
}
