<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ActivityType, RunActivityEvent } from '../models/run.ts'

import { translate as t } from '@nextcloud/l10n'
import { onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import * as api from '../services/runs.ts'
import { apiErrorMessage } from '../utils/apiError.ts'

const props = defineProps<{
	runId: number
	stepTitles?: Record<number, string>
}>()

const events = ref<RunActivityEvent[]>([])
const order = ref<'asc' | 'desc'>('desc')
const expanded = ref(true)
const loading = ref(false)
const error = ref<string | null>(null)

/**
 * Load the activity history of the run in the requested order.
 */
async function load(): Promise<void> {
	loading.value = true
	error.value = null
	try {
		events.value = await api.getActivity(props.runId, undefined, order.value)
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)
watch(() => props.runId, load)
watch(order, load)

/**
 * Human readable label for an activity event type.
 *
 * @param type Activity event type.
 */
function eventLabel(type: ActivityType): string {
	switch (type) {
		case 'run_started':
			return t('runbook', 'Run started')
		case 'run_completed':
			return t('runbook', 'Run completed')
		case 'run_cancelled':
			return t('runbook', 'Run cancelled')
		case 'run_reopened':
			return t('runbook', 'Run reopened')
		case 'run_acl_changed':
			return t('runbook', 'Participants changed')
		case 'run_assignment_changed':
			return t('runbook', 'Run assignment changed')
		case 'section_notes_updated':
			return t('runbook', 'Section notes updated')
		case 'step_assignment_changed':
			return t('runbook', 'Step assignment changed')
		case 'step_started':
			return t('runbook', 'Step started')
		case 'step_response_updated':
			return t('runbook', 'Step response updated')
		case 'step_completed':
			return t('runbook', 'Step completed')
		case 'step_skipped':
			return t('runbook', 'Step skipped')
		case 'step_reopened':
			return t('runbook', 'Step reopened')
		case 'step_returned':
			return t('runbook', 'Step returned')
		case 'section_returned':
			return t('runbook', 'Section returned')
		case 'comment_added':
			return t('runbook', 'Comment added')
		case 'comment_edited':
			return t('runbook', 'Comment edited')
		case 'comment_deleted':
			return t('runbook', 'Comment deleted')
		case 'attachment_uploaded':
			return t('runbook', 'Evidence uploaded')
		case 'attachment_deleted':
			return t('runbook', 'Evidence deleted')
	}
}

/**
 * Render a scalar metadata value, never exposing raw booleans.
 *
 * @param value Metadata value.
 */
function valueLabel(value: unknown): string | null {
	if (typeof value === 'boolean') {
		return value ? t('runbook', 'Yes') : t('runbook', 'No')
	}
	if (typeof value === 'string') {
		return value === '' ? null : value
	}
	if (typeof value === 'number') {
		return String(value)
	}

	return null
}

/**
 * Optional metadata detail for an event: affected section/step, filename and
 * previous/new values when they were recorded safely.
 *
 * @param event Activity event.
 */
function eventDetail(event: RunActivityEvent): string | null {
	const parts: string[] = []

	const sectionTitle = event.metadata.sectionTitle
	if (typeof sectionTitle === 'string' && sectionTitle !== '') {
		parts.push(sectionTitle)
	}

	if (event.stepId !== null) {
		const stepTitle = props.stepTitles?.[event.stepId]
		if (stepTitle !== undefined && stepTitle !== '') {
			parts.push(stepTitle)
		}
	}

	const filename = event.metadata.filename
	if (typeof filename === 'string' && filename !== '') {
		parts.push(filename)
	}

	const previous = valueLabel(event.metadata.previous)
	const next = valueLabel(event.metadata.new)
	if (previous !== null || next !== null) {
		parts.push(`${previous ?? '—'} → ${next ?? '—'}`)
	}

	const reason = event.metadata.reason
	if (typeof reason === 'string' && reason !== '') {
		parts.push(reason)
	}

	return parts.length > 0 ? parts.join(' · ') : null
}

/**
 * Format a Unix timestamp for display.
 *
 * @param timestamp Unix timestamp in seconds.
 */
function formatDate(timestamp: number): string {
	return new Date(timestamp * 1000).toLocaleString()
}
</script>

<template>
	<section class="runbook-activity">
		<div class="runbook-activity__header">
			<h3>{{ t('runbook', 'Activity') }}</h3>
			<div class="runbook-activity__controls">
				<div class="runbook-activity__order" role="group" :aria-label="t('runbook', 'Activity order')">
					<NcButton
						:variant="order === 'desc' ? 'primary' : 'tertiary'"
						:aria-pressed="order === 'desc'"
						@click="order = 'desc'">
						{{ t('runbook', 'Newest first') }}
					</NcButton>
					<NcButton
						:variant="order === 'asc' ? 'primary' : 'tertiary'"
						:aria-pressed="order === 'asc'"
						@click="order = 'asc'">
						{{ t('runbook', 'Oldest first') }}
					</NcButton>
				</div>
				<NcButton
					variant="tertiary"
					:aria-expanded="expanded"
					:aria-label="expanded ? t('runbook', 'Collapse activity') : t('runbook', 'Expand activity')"
					@click="expanded = !expanded">
					{{ expanded ? t('runbook', 'Hide activity') : t('runbook', 'Show activity') }}
				</NcButton>
			</div>
		</div>

		<template v-if="expanded">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<div v-if="loading" class="runbook-activity__center">
				<NcLoadingIcon :name="t('runbook', 'Loading')" />
			</div>

			<template v-else>
				<p v-if="events.length === 0" class="runbook-activity__hint">
					{{ t('runbook', 'No activity recorded yet.') }}
				</p>

				<ol class="runbook-activity__list">
					<li v-for="event in events" :key="event.id" class="runbook-activity__item">
						<span class="runbook-activity__label">{{ eventLabel(event.eventType) }}</span>
						<span v-if="eventDetail(event)" class="runbook-activity__detail">{{ eventDetail(event) }}</span>
						<span class="runbook-activity__meta">{{ event.actorUid }} · {{ formatDate(event.createdAt) }}</span>
					</li>
				</ol>
			</template>
		</template>
	</section>
</template>

<style scoped>
.runbook-activity {
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius, 4px);
	padding: 12px;
	margin-bottom: 24px;
}

.runbook-activity__header {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
}

.runbook-activity__controls {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.runbook-activity__order {
	display: flex;
	gap: 4px;
}

.runbook-activity__hint {
	color: var(--color-text-maxcontrast, #555);
}

.runbook-activity__center {
	display: flex;
	justify-content: center;
	padding: 16px;
}

.runbook-activity__list {
	list-style: none;
	margin: 8px 0 0;
	padding: 0;
}

.runbook-activity__item {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	border-bottom: 1px solid var(--color-border, #ededed);
	padding: 6px 0;
}

.runbook-activity__label {
	font-weight: bold;
}

.runbook-activity__detail {
	color: var(--color-text-maxcontrast, #555);
}

.runbook-activity__meta {
	margin-left: auto;
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
}
</style>
