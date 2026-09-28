/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure helpers for the optional per-template destination folder (issue #48).
 *
 * The state machine is identical to the administration destination; the
 * translator is passed in so the helpers are unit-testable without a
 * component/DOM environment. They never handle storage/file ids.
 */

import type { translate } from '@nextcloud/l10n'
import type { TemplateDestinationState } from '../models/template.ts'

import { destinationPath, destinationStatus, selectedPathFromPicker } from './adminDestination.ts'

type Translate = typeof translate

export { selectedPathFromPicker }

export const EMPTY_TEMPLATE_DESTINATION: TemplateDestinationState = {
	configured: false,
	valid: false,
	path: null,
	configuredBy: null,
}

export type TemplateDestinationStatus = 'unset' | 'configured' | 'invalid'

/**
 * Classify the stored template destination state.
 *
 * @param state Stored template destination state.
 */
export function templateDestinationStatus(state: TemplateDestinationState): TemplateDestinationStatus {
	return destinationStatus(state)
}

/**
 * The advisory folder path to display, or null when there is none.
 *
 * @param state Stored template destination state.
 */
export function templateDestinationPath(state: TemplateDestinationState): string | null {
	return destinationPath(state)
}

/**
 * Human readable status text for the template destination, describing where
 * runs fall back to when the template destination is unset.
 *
 * @param t Translator.
 * @param state Stored template destination state.
 */
export function templateDestinationStatusText(t: Translate, state: TemplateDestinationState): string {
	switch (templateDestinationStatus(state)) {
		case 'configured':
			return t('runbook', 'Runs from this template store their files in this folder.')
		case 'invalid':
			return t('runbook', 'The stored folder reference is incomplete. Select the folder again.')
		default:
			return t('runbook', 'Not configured. Runs fall back to the global destination, or the default “Runbook” folder in the run owner’s Files.')
	}
}
