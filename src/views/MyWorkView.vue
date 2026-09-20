<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { MyWorkFilter, WorkItem } from '../models/run.ts'

import { translate as t } from '@nextcloud/l10n'
import { onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import RunStatusBadge from '../components/RunStatusBadge.vue'
import { MY_WORK_FILTERS } from '../models/run.ts'
import * as api from '../services/runs.ts'
import { apiErrorMessage } from '../utils/apiError.ts'

const emit = defineEmits<{
	open: [id: number, stepId: number | null]
}>()

const filter = ref<MyWorkFilter>('all')
const work = ref<WorkItem[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

/**
 * Human readable label for a My Work filter.
 *
 * @param value My Work filter.
 */
function filterLabel(value: MyWorkFilter): string {
	switch (value) {
		case 'today':
			return t('runbook', 'Today')
		case 'upcoming':
			return t('runbook', 'Upcoming')
		case 'overdue':
			return t('runbook', 'Overdue')
		case 'completed':
			return t('runbook', 'Completed')
		default:
			return t('runbook', 'All')
	}
}

/**
 *
 */
async function load(): Promise<void> {
	loading.value = true
	error.value = null
	try {
		work.value = await api.getMyWork(filter.value)
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)
watch(filter, load)

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
	<section class="runbook-view">
		<header class="runbook-view__header">
			<h2>{{ t('runbook', 'My Work') }}</h2>
		</header>

		<div class="runbook-view__filters">
			<NcButton
				v-for="value in MY_WORK_FILTERS"
				:key="value"
				:variant="filter === value ? 'primary' : 'tertiary'"
				@click="filter = value">
				{{ filterLabel(value) }}
			</NcButton>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="runbook-view__center">
			<NcLoadingIcon :name="t('runbook', 'Loading')" />
		</div>

		<NcEmptyContent
			v-else-if="work.length === 0"
			:name="t('runbook', 'Nothing to do here')"
			:description="t('runbook', 'You have no assigned work for this filter.')" />

		<ul v-else class="runbook-list">
			<li v-for="item in work" :key="item.stepId" class="runbook-list__item">
				<div class="runbook-list__main">
					<button type="button" class="runbook-list__title" @click="emit('open', item.runId, item.stepId)">
						{{ item.stepTitle }}
					</button>
					<div class="runbook-list__meta">
						<RunStatusBadge :status="item.runStatus" />
						<span>{{ item.runTitle }}</span>
						<span>{{ item.sectionTitle }}</span>
						<span v-if="item.required" class="runbook-work__required">{{ t('runbook', 'Required') }}</span>
						<span v-if="item.overdue" class="runbook-work__overdue">{{ t('runbook', 'Overdue') }}</span>
						<span v-else-if="item.dueToday" class="runbook-work__today">{{ t('runbook', 'Due today') }}</span>
						<span v-if="item.dueAt !== null">{{ formatDate(item.dueAt) }}</span>
						<span v-if="item.assigneeType !== null">{{ item.assigneeType }}: {{ item.assigneeId }}</span>
					</div>
				</div>
				<div class="runbook-list__actions">
					<NcButton @click="emit('open', item.runId, item.stepId)">
						{{ t('runbook', 'Open') }}
					</NcButton>
				</div>
			</li>
		</ul>
	</section>
</template>

<style scoped>
.runbook-view {
	padding: 16px;
}

.runbook-view__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	margin-bottom: 16px;
}

.runbook-view__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 16px;
}

.runbook-view__center {
	display: flex;
	justify-content: center;
	padding: 32px;
}

.runbook-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.runbook-list__item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius, 4px);
	margin-bottom: 8px;
}

.runbook-list__main {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.runbook-list__title {
	background: none;
	border: none;
	padding: 0;
	font-size: 1.1em;
	font-weight: bold;
	cursor: pointer;
	text-align: left;
}

.runbook-list__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
}

.runbook-work__overdue {
	color: var(--color-error, #d40000);
	font-weight: bold;
}

.runbook-work__today {
	color: var(--color-warning, #d68000);
	font-weight: bold;
}

.runbook-work__required {
	font-style: italic;
}
</style>
