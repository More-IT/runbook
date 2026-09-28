/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure helpers for the run-time destination override in the Start run dialog
 * (issue #49). The translator is passed in so the status text is unit-testable
 * without a component/DOM environment. They never handle storage/file ids.
 */

import type { translate } from '@nextcloud/l10n'
import type { StartRunPayload } from '../models/run.ts'

type Translate = typeof translate

export interface StartRunForm {
	title: string
	description: string
	/** Raw value from the date input (`YYYY-MM-DD` or empty). */
	dueDate: string
	/** Selected run-time folder path, or null for the inherited destination. */
	destinationPath: string | null
}

/**
 * Normalize a selected run-time folder path. An empty/whitespace path means
 * "no explicit choice" (inherit the template/administration/default choice).
 *
 * @param path Selected path or null.
 */
export function normalizeRunDestinationPath(path: string | null): string | null {
	if (path === null) {
		return null
	}
	const trimmed = path.trim()

	return trimmed === '' ? null : trimmed
}

/**
 * Convert the date input into a Unix timestamp (seconds), or null when unset.
 *
 * @param value Raw `YYYY-MM-DD` input value.
 */
export function convertDueDateInput(value: string): number | null {
	if (value === '') {
		return null
	}
	const timestamp = Math.floor(new Date(`${value}T00:00:00Z`).getTime() / 1000)

	return Number.isNaN(timestamp) ? null : timestamp
}

/**
 * Build the run-start API payload. `destinationPath` is included only when the
 * user made an explicit run-time choice, so an inherited destination is never
 * silently overridden.
 *
 * @param form Current dialog form values.
 */
export function buildStartRunPayload(form: StartRunForm): StartRunPayload {
	const payload: StartRunPayload = {
		title: form.title,
		description: form.description,
		dueAt: convertDueDateInput(form.dueDate),
	}

	const path = normalizeRunDestinationPath(form.destinationPath)
	if (path !== null) {
		payload.destinationPath = path
	}

	return payload
}

/**
 * Status text distinguishing an explicit run-time choice from the inherited
 * destination (which is the template setting, the administration setting, or the
 * default `Runbook` folder).
 *
 * @param t Translator.
 * @param destinationPath Selected run-time folder path, or null when inherited.
 */
export function startRunDestinationStatusText(t: Translate, destinationPath: string | null): string {
	if (normalizeRunDestinationPath(destinationPath) === null) {
		return t('runbook', 'No folder selected; the template or administrator setting will be used.')
	}

	return t('runbook', 'This run will be stored in the selected folder.')
}
