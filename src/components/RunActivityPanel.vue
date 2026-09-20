<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ActivityType, RunActivityEvent } from '../models/run.ts'

import { translate as t } from '@nextcloud/l10n'
import { onMounted, ref, watch } from 'vue'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import * as api from '../services/runs.ts'
import { apiErrorMessage } from '../utils/apiError.ts'

const props = defineProps<{
	runId: number
}>()

const events = ref<RunActivityEvent[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

/**
 * Load the activity history of the run.
 */
async function load(): Promise<void> {
	loading.value = true
	error.value = null
	try {
		events.value = await api.getActivity(props.runId)
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)
watch(() => props.runId, load)

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
 * Optional metadata detail for an event.
 *
 * @param event Activity event.
 */
function eventDetail(event: RunActivityEvent): string | null {
	const filename = event.metadata.filename
	if (typeof filename === 'string' && filename !== '') {
		return filename
	}

	return null
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
		<h3>{{ t('runbook', 'Activity') }}</h3>

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
	</section>
</template>

<style scoped>
.runbook-activity {
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius, 4px);
	padding: 12px;
	margin-bottom: 24px;
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
	margin: 0;
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
