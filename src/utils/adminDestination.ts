/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure helpers for the global administration destination setting (issue #47).
 *
 * They take the translator as a parameter so they can be unit tested without a
 * component/DOM test environment, and so the exact rendered output is
 * guaranteed for every locale. They never handle storage/file ids: the browser
 * only ever sees an advisory display path and a status.
 */

import type { translate } from '@nextcloud/l10n'
import type { AdminDestinationState } from '../models/adminSettings.ts'

type Translate = typeof translate

export const EMPTY_DESTINATION_STATE: AdminDestinationState = {
	configured: false,
	valid: false,
	path: null,
	configuredBy: null,
}

export type DestinationStatus = 'unset' | 'configured' | 'invalid'

/**
 * Classify the stored destination state.
 *
 * A configured-but-incomplete reference is `invalid`, never treated as unset.
 *
 * @param state Stored destination state.
 */
export function destinationStatus(state: AdminDestinationState): DestinationStatus {
	if (!state.configured) {
		return 'unset'
	}

	return state.valid ? 'configured' : 'invalid'
}

/**
 * The advisory folder path to display, or null when there is none.
 *
 * @param state Stored destination state.
 */
export function destinationPath(state: AdminDestinationState): string | null {
	return state.path !== null && state.path !== '' ? state.path : null
}

/**
 * Human readable status text for the destination setting.
 *
 * @param t Translator.
 * @param state Stored destination state.
 */
export function destinationStatusText(t: Translate, state: AdminDestinationState): string {
	switch (destinationStatus(state)) {
		case 'configured':
			return t('runbook', 'New runs store their files in this folder.')
		case 'invalid':
			return t('runbook', 'The stored folder reference is incomplete. Select the folder again.')
		default:
			return t('runbook', 'Not configured. New runs use the default “Runbook” folder in the run owner’s Files.')
	}
}

/**
 * Normalize a FilePicker result into a single path.
 *
 * The picker is used in single-select mode, so the result is normally a string;
 * arrays are handled defensively, and empty values become null (a no-op save).
 *
 * @param result Value returned by the picker.
 */
export function selectedPathFromPicker(result: unknown): string | null {
	if (typeof result === 'string') {
		return result.trim() === '' ? null : result
	}
	if (Array.isArray(result) && result.length > 0 && typeof result[0] === 'string') {
		return result[0].trim() === '' ? null : result[0]
	}

	return null
}
