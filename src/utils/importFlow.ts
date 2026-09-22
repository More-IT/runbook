/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Submission state machine for the template import dialog (issue #31).
 *
 * Keeping the asynchronous orchestration here (independent of Vue and the DOM)
 * makes the subtle states testable: a created template must never be reported as
 * a failed import, and a refresh or navigation problem after a successful
 * creation must not invite a retry that could create a duplicate.
 */

import type { Template, TemplateExportDocument } from '../models/template.ts'

export interface TemplateImportFlowDeps {
	/** Create the template on the server. */
	create: (document: TemplateExportDocument) => Promise<Template>
	/** Refresh the template list; may fail without affecting the creation. */
	refresh: () => Promise<void>
	/** Open the created template in the editor. */
	open: (template: Template) => void
}

export type TemplateImportFlowResult
	= | { status: 'failed', error: unknown }
		| { status: 'created', template: Template, refreshed: boolean, opened: boolean }

/**
 * Whether the import confirmation dialog may currently be closed.
 *
 * While a request is in flight the dialog must stay open, so Cancel is disabled
 * and NcDialog's `noClose`/`closeOnClickOutside` props suppress the supported
 * user close paths (Escape, backdrop, close control). `@closing` is only a
 * notification that closing has begun and is not cancellable, so this predicate
 * guards application state (cleanup and the Cancel handler) rather than
 * attempting to veto closure.
 *
 * @param importing Whether an import request is pending.
 */
export function canCloseImportDialog(importing: boolean): boolean {
	return !importing
}

/**
 * Whether a new import submission may start.
 *
 * Used to make the Import action idempotent: a double click or a re-entrant
 * call while a request is pending is ignored.
 *
 * @param importing Whether an import request is already pending.
 */
export function canStartImport(importing: boolean): boolean {
	return !importing
}

/**
 * Run the import flow once.
 *
 * The server creation is attempted first. On failure the error is returned for
 * display and nothing else runs. On success the list refresh and opening the
 * editor are best-effort: their failures are reported as flags while the
 * created template (and its id) is always preserved so the user can recover it
 * instead of retrying and creating a duplicate.
 *
 * @param document Parsed export document.
 * @param deps Side-effecting collaborators.
 */
export async function runTemplateImport(
	document: TemplateExportDocument,
	deps: TemplateImportFlowDeps,
): Promise<TemplateImportFlowResult> {
	let template: Template
	try {
		template = await deps.create(document)
	} catch (error) {
		return { status: 'failed', error }
	}

	let refreshed = true
	try {
		await deps.refresh()
	} catch {
		refreshed = false
	}

	let opened = false
	if (refreshed) {
		try {
			deps.open(template)
			opened = true
		} catch {
			opened = false
		}
	}

	return { status: 'created', template, refreshed, opened }
}
