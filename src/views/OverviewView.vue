<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Overview } from '../models/run.ts'

import { translate as t } from '@nextcloud/l10n'
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import RunStatusBadge from '../components/RunStatusBadge.vue'
import * as api from '../services/runs.ts'
import { apiErrorMessage } from '../utils/apiError.ts'

const emit = defineEmits<{
	open: [id: number]
}>()

const overview = ref<Overview | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

/**
 *
 */
async function load(): Promise<void> {
	loading.value = true
	error.value = null
	try {
		overview.value = await api.getOverview()
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)

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
			<h2>{{ t('runbook', 'Overview') }}</h2>
		</header>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="runbook-view__center">
			<NcLoadingIcon :name="t('runbook', 'Loading')" />
		</div>

		<template v-else-if="overview !== null">
			<div class="runbook-overview__counters">
				<div class="runbook-overview__counter">
					<span class="runbook-overview__value">{{ overview.activeRuns }}</span>
					<span class="runbook-overview__label">{{ t('runbook', 'Active runs') }}</span>
				</div>
				<div class="runbook-overview__counter">
					<span class="runbook-overview__value">{{ overview.assignedActiveSteps }}</span>
					<span class="runbook-overview__label">{{ t('runbook', 'Assigned steps') }}</span>
				</div>
				<div class="runbook-overview__counter">
					<span class="runbook-overview__value">{{ overview.overdue }}</span>
					<span class="runbook-overview__label">{{ t('runbook', 'Overdue') }}</span>
				</div>
				<div class="runbook-overview__counter">
					<span class="runbook-overview__value">{{ overview.completedStepsThisMonth }}</span>
					<span class="runbook-overview__label">{{ t('runbook', 'Steps completed this month') }}</span>
				</div>
				<div class="runbook-overview__counter">
					<span class="runbook-overview__value">{{ overview.completedRunsThisMonth }}</span>
					<span class="runbook-overview__label">{{ t('runbook', 'Runs completed this month') }}</span>
				</div>
			</div>

			<h3>{{ t('runbook', 'Assigned work') }}</h3>
			<p v-if="overview.assignedWork.length === 0" class="runbook-view__hint">
				{{ t('runbook', 'You have no assigned work.') }}
			</p>
			<ul v-else class="runbook-list">
				<li v-for="item in overview.assignedWork" :key="item.stepId" class="runbook-list__item">
					<div class="runbook-list__main">
						<button type="button" class="runbook-list__title" @click="emit('open', item.runId)">
							{{ item.stepTitle }}
						</button>
						<div class="runbook-list__meta">
							<span>{{ item.runTitle }}</span>
							<span v-if="item.overdue" class="runbook-overview__overdue">{{ t('runbook', 'Overdue') }}</span>
							<span v-else-if="item.dueToday">{{ t('runbook', 'Due today') }}</span>
						</div>
					</div>
				</li>
			</ul>

			<h3>{{ t('runbook', 'Recent runs') }}</h3>
			<p v-if="overview.recentRuns.length === 0" class="runbook-view__hint">
				{{ t('runbook', 'No runs yet.') }}
			</p>
			<ul v-else class="runbook-list">
				<li v-for="run in overview.recentRuns" :key="run.id" class="runbook-list__item">
					<div class="runbook-list__main">
						<button type="button" class="runbook-list__title" @click="emit('open', run.id)">
							{{ run.title }}
						</button>
						<div class="runbook-list__meta">
							<RunStatusBadge :status="run.status" />
							<span>{{ formatDate(run.updatedAt) }}</span>
						</div>
					</div>
					<div class="runbook-list__actions">
						<NcButton @click="emit('open', run.id)">
							{{ t('runbook', 'Open') }}
						</NcButton>
					</div>
				</li>
			</ul>
		</template>
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

.runbook-view__center {
	display: flex;
	justify-content: center;
	padding: 32px;
}

.runbook-view__hint {
	color: var(--color-text-maxcontrast, #555);
}

.runbook-overview__counters {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	margin-bottom: 24px;
}

.runbook-overview__counter {
	display: flex;
	flex-direction: column;
	align-items: center;
	min-width: 120px;
	padding: 12px;
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius, 4px);
}

.runbook-overview__value {
	font-size: 1.6em;
	font-weight: bold;
}

.runbook-overview__label {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
	text-align: center;
}

.runbook-overview__overdue {
	color: var(--color-error, #d40000);
	font-weight: bold;
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
	text-align: start;
}

.runbook-list__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
}
</style>
