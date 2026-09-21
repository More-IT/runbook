/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure helpers that turn a section's flow state and structured reason metadata
 * into display strings. They take the translator as a parameter so they can be
 * unit tested without a component/DOM test environment, and so the exact
 * rendered output is guaranteed for every locale.
 */

import type { translate } from '@nextcloud/l10n'
import type { RunSectionWithSteps, SectionReason } from '../models/run.ts'

type Translate = typeof translate

/** Separator between the state label and its explanation. */
export const STATUS_REASON_SEPARATOR = ' — '
/** Separator between multiple reason fragments. */
export const REASON_FRAGMENT_SEPARATOR = '; '

/**
 * Human readable label for a section flow state.
 *
 * @param t Translator.
 * @param state Section state.
 */
export function sectionStateLabel(t: Translate, state: string): string {
	switch (state) {
		case 'blocked':
			return t('runbook', 'Blocked')
		case 'inapplicable':
			return t('runbook', 'Not applicable')
		case 'active':
			return t('runbook', 'In progress')
		case 'resolved':
			return t('runbook', 'Resolved')
		default:
			return t('runbook', 'Available')
	}
}

/**
 * Human readable text for a single structured section reason.
 *
 * Step titles are authoring metadata and are inserted verbatim (`escape: false`,
 * `sanitize: false`); XSS safety comes from Vue's text interpolation, never from
 * HTML escaping here, so titles containing quotes, apostrophes, ampersands or
 * HTML-like text render exactly as authored without visible entities.
 *
 * @param t Translator.
 * @param reason Section reason.
 */
export function reasonFragment(t: Translate, reason: SectionReason): string {
	switch (reason.type) {
		case 'condition_false':
			return t('runbook', 'The condition on “{step}” is not satisfied', { step: reason.title }, { escape: false, sanitize: false })
		case 'condition_pending':
			return t('runbook', 'Waiting for “{step}”', { step: reason.title }, { escape: false, sanitize: false })
		case 'dependency':
			return t('runbook', 'Waiting for section “{section}”', { section: reason.title }, { escape: false, sanitize: false })
		default:
			return reason.title
	}
}

/**
 * Explanation for an inapplicable section (every condition that evaluated to
 * false), joined with a readable separator.
 *
 * @param t Translator.
 * @param entry Section with its structured reasons.
 */
export function inapplicableReasonText(t: Translate, entry: Pick<RunSectionWithSteps, 'reason'>): string {
	return entry.reason
		.filter((reason) => reason.type === 'condition_false')
		.map((reason) => reasonFragment(t, reason))
		.join(REASON_FRAGMENT_SEPARATOR)
}
