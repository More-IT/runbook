/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Client-side parsing and summary for a selected Runbook template export file.
 *
 * The server is authoritative for the document size and re-validates the whole
 * document; these helpers only decode the selected file, apply a local
 * source-file memory guard, reject obviously malformed input early and produce
 * the confirmation summary. The file is never uploaded or persisted anywhere
 * else.
 */

import type { TemplateExportDocument } from '../models/template.ts'

/**
 * Browser-side source-file safety cap, in bytes.
 *
 * This is **not** the import rule (that is the server's canonical-document cap,
 * `TemplateImportService::MAX_DOCUMENT_BYTES`, currently 2,000,000 bytes). It is
 * only a guard so an unreasonably large local file is rejected before
 * `file.text()` loads it into memory.
 *
 * Why 32,000,000: a maximal valid document is 2,000,000 canonical bytes, and the
 * exported file is pretty-printed (`formatTemplateExport`). Pretty-printing adds
 * whitespace bounded by the structure allowed by the schema (500 sections, 5,000
 * steps, 60,000-byte configurations); measured token-dense documents expand by
 * roughly 10x, so a realistic maximal export is well under this cap, while files
 * an order of magnitude larger than any supported document are still rejected.
 * Pathologically deep configuration nesting can expand super-linearly when
 * pretty-printed, so this cap is a memory guard, not a promise that every
 * canonical-valid document is readable; the server decides acceptance of the
 * compact body it receives.
 */
export const MAX_SOURCE_FILE_BYTES = 32000000

export type TemplateImportErrorCode = 'invalid_json' | 'invalid_document' | 'too_large'

/**
 * Whether a selected file is too large to read, based on `File.size`.
 *
 * The view uses this to reject the file before calling `file.text()`, so an
 * oversized file is never read into memory.
 *
 * @param size File size in bytes.
 */
export function isFileTooLarge(size: number): boolean {
	return size > MAX_SOURCE_FILE_BYTES
}

/**
 * Error raised while decoding a selected import file.
 */
export class TemplateImportError extends Error {
	readonly code: TemplateImportErrorCode

	/**
	 * @param code Machine-readable failure reason.
	 */
	constructor(code: TemplateImportErrorCode) {
		super(code)
		this.name = 'TemplateImportError'
		this.code = code
	}
}

/**
 * Reject a source file above the safety cap.
 *
 * The view calls this before `file.text()`, so the file is never read into
 * memory. This is the only size decision the browser makes.
 *
 * @param size File size in bytes.
 *
 * @throws {TemplateImportError} With code `too_large` when the file exceeds the cap.
 */
export function assertSourceFileSize(size: number): void {
	if (isFileTooLarge(size)) {
		throw new TemplateImportError('too_large')
	}
}

export interface ImportSummary {
	title: string
	description: string
	sections: number
	steps: number
}

export interface ParsedTemplateImport {
	document: TemplateExportDocument
	summary: ImportSummary
}

/**
 * Validate the document envelope and build the confirmation summary.
 *
 * @param input Decoded JSON value.
 */
export function summariseDocument(input: unknown): ImportSummary {
	if (typeof input !== 'object' || input === null || Array.isArray(input)) {
		throw new TemplateImportError('invalid_document')
	}

	const document = input as Record<string, unknown>
	if (document.format !== 'runbook-template') {
		throw new TemplateImportError('invalid_document')
	}
	if (document.schemaVersion !== 1) {
		throw new TemplateImportError('invalid_document')
	}

	const template = document.template
	if (typeof template !== 'object' || template === null || Array.isArray(template)) {
		throw new TemplateImportError('invalid_document')
	}
	const metadata = template as Record<string, unknown>
	if (typeof metadata.title !== 'string') {
		throw new TemplateImportError('invalid_document')
	}
	if (metadata.description !== undefined && typeof metadata.description !== 'string') {
		throw new TemplateImportError('invalid_document')
	}
	if (!Array.isArray(document.sections) || !Array.isArray(document.steps)) {
		throw new TemplateImportError('invalid_document')
	}

	return {
		title: metadata.title,
		description: typeof metadata.description === 'string' ? metadata.description : '',
		sections: document.sections.length,
		steps: document.steps.length,
	}
}

/**
 * Parse the text of a selected `.json` file.
 *
 * No size decision is made here beyond the caller's `File.size` guard: the
 * server's canonical-document cap is authoritative, and JavaScript's
 * `JSON.stringify` byte count can differ from PHP's `json_encode` (for example
 * `1e21` → `1e+21` vs `1.0e+21`, `1e-6` → `0.000001` vs `1.0e-6`, and U+2028
 * literal vs `\u2028`), so a client-side estimate must never reject a document
 * the server would accept.
 *
 * @param text UTF-8 file content.
 */
export function parseTemplateImport(text: string): ParsedTemplateImport {
	let parsed: unknown
	try {
		parsed = JSON.parse(text)
	} catch {
		throw new TemplateImportError('invalid_json')
	}

	return { document: parsed as TemplateExportDocument, summary: summariseDocument(parsed) }
}
