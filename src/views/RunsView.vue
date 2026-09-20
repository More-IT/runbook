<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { RunListItem } from '../models/run.ts'

import { translate as t } from '@nextcloud/l10n'
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import RunStatusBadge from '../components/RunStatusBadge.vue'
import * as api from '../services/runs.ts'
import { apiErrorMessage } from '../utils/apiError.ts'

const emit = defineEmits<{
	open: [id: number]
}>()

const runs = ref<RunListItem[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

/**
 *
 */
async function load(): Promise<void> {
	loading.value = true
	error.value = null
	try {
		runs.value = await api.listRuns()
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
			<h2>{{ t('runbook', 'Runs') }}</h2>
		</header>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="runbook-view__center">
			<NcLoadingIcon :name="t('runbook', 'Loading')" />
		</div>

		<NcEmptyContent
			v-else-if="runs.length === 0"
			:name="t('runbook', 'No runs yet')"
			:description="t('runbook', 'Start a run from a published template to see it here.')" />

		<ul v-else class="runbook-list">
			<li v-for="entry in runs" :key="entry.run.id" class="runbook-list__item">
				<div class="runbook-list__main">
					<button type="button" class="runbook-list__title" @click="emit('open', entry.run.id)">
						{{ entry.run.title }}
					</button>
					<div class="runbook-list__meta">
						<RunStatusBadge :status="entry.run.status" />
						<span>{{ t('runbook', 'Progress: {percent}%', { percent: entry.progress.percentage }) }}</span>
						<span>{{ t('runbook', '{resolved} of {total} steps resolved', { resolved: entry.progress.completed + entry.progress.skipped, total: entry.progress.total }) }}</span>
						<span>{{ t('runbook', 'Template v{version}', { version: entry.run.templateVersion }) }}</span>
						<span>{{ formatDate(entry.run.updatedAt) }}</span>
					</div>
				</div>
				<div class="runbook-list__actions">
					<NcButton @click="emit('open', entry.run.id)">
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
</style>
