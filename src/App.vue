<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import MyWorkView from './views/MyWorkView.vue'
import OverviewView from './views/OverviewView.vue'
import PlaceholderView from './views/PlaceholderView.vue'
import RunDetailView from './views/RunDetailView.vue'
import RunsView from './views/RunsView.vue'
import TemplateEditorView from './views/TemplateEditorView.vue'
import TemplatesView from './views/TemplatesView.vue'
import { useBackendStatus } from './composables/useBackendStatus.ts'

interface NavigationEntry {
	id: string
	label: string
}

const navigation: NavigationEntry[] = [
	{ id: 'overview', label: t('runbook', 'Overview') },
	{ id: 'my-work', label: t('runbook', 'My Work') },
	{ id: 'runs', label: t('runbook', 'Runs') },
	{ id: 'templates', label: t('runbook', 'Templates') },
	{ id: 'archived', label: t('runbook', 'Archived') },
]

const activeSection = ref<string>('templates')
const editingTemplateId = ref<number | null>(null)
const activeRunId = ref<number | null>(null)

const { status, checkBackendStatus } = useBackendStatus()

const backendStatusText = computed<string>(() => {
	switch (status.value) {
		case 'ok':
			return t('runbook', 'Backend connection verified.')
		case 'error':
			return t('runbook', 'Backend connection could not be verified.')
		default:
			return t('runbook', 'Verifying backend connection')
	}
})

const activeLabel = computed<string>(() => navigation.find((entry) => entry.id === activeSection.value)?.label ?? t('runbook', 'Runbook'))

const pageHeading = computed<string>(() => {
	if (activeRunId.value !== null) {
		return t('runbook', 'Run')
	}
	if (editingTemplateId.value !== null) {
		return t('runbook', 'Template editor')
	}

	return activeLabel.value
})

const placeholderNote = computed<string | undefined>(() => activeSection.value === 'overview' ? backendStatusText.value : undefined)

/**
 * Update the URL hash without triggering a navigation loop.
 *
 * @param hash New hash including the leading `#`.
 */
function setHash(hash: string): void {
	if (window.location.hash !== hash) {
		window.location.hash = hash
	}
}

/**
 * Switch the active navigation section.
 *
 * @param id Navigation entry identifier.
 */
function selectSection(id: string): void {
	activeSection.value = id
	editingTemplateId.value = null
	activeRunId.value = null
	setHash(`#${id}`)
}

/**
 * Open the editor for a template.
 *
 * @param id Template identifier.
 */
function openTemplate(id: number): void {
	activeRunId.value = null
	editingTemplateId.value = id
	setHash(`#/template/${id}`)
}

/**
 * Open a run detail view.
 *
 * @param id Run identifier.
 */
function openRun(id: number): void {
	activeRunId.value = id
	setHash(`#/run/${id}`)
}

/**
 * Handle a run started from the template editor.
 *
 * @param id Newly created run identifier.
 */
function onRunStarted(id: number): void {
	editingTemplateId.value = null
	activeSection.value = 'runs'
	activeRunId.value = id
	setHash(`#/run/${id}`)
}

/**
 * Close the run detail view and return to the run list.
 */
function closeRun(): void {
	activeRunId.value = null
	setHash('#runs')
}

/**
 * Close the template editor and return to the template list.
 */
function closeTemplate(): void {
	editingTemplateId.value = null
	setHash('#templates')
}

/**
 * Apply a deep link from the URL hash (notifications, dashboard, search).
 */
function applyHash(): void {
	const parts = window.location.hash
		.replace(/^#\/?/, '')
		.split('/')
		.filter((part) => part !== '')

	if (parts.length === 0) {
		return
	}

	if (parts[0] === 'run' && parts.length > 1) {
		const runId = Number(parts[1])
		if (Number.isInteger(runId) && runId > 0) {
			activeSection.value = 'runs'
			editingTemplateId.value = null
			activeRunId.value = runId
			return
		}
	}

	if (parts[0] === 'template' && parts.length > 1) {
		const templateId = Number(parts[1])
		if (Number.isInteger(templateId) && templateId > 0) {
			activeRunId.value = null
			editingTemplateId.value = templateId
			return
		}
	}

	if (navigation.some((entry) => entry.id === parts[0])) {
		selectSection(parts[0])
	}
}

onMounted(() => {
	checkBackendStatus()
	window.addEventListener('hashchange', applyHash)
	applyHash()
})
</script>

<template>
	<NcContent appName="runbook">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem
					v-for="entry in navigation"
					:key="entry.id"
					:name="entry.label"
					:active="activeSection === entry.id && editingTemplateId === null && activeRunId === null"
					:href="`#${entry.id}`"
					@click.prevent="selectSection(entry.id)" />
			</template>
		</NcAppNavigation>
		<NcAppContent :pageHeading="pageHeading">
			<RunDetailView
				v-if="activeRunId !== null"
				:runId="activeRunId"
				@close="closeRun" />
			<TemplateEditorView
				v-else-if="editingTemplateId !== null"
				:templateId="editingTemplateId"
				@close="closeTemplate"
				@runStarted="onRunStarted" />
			<RunsView
				v-else-if="activeSection === 'runs'"
				@open="openRun" />
			<MyWorkView
				v-else-if="activeSection === 'my-work'"
				@open="openRun" />
			<OverviewView
				v-else-if="activeSection === 'overview'"
				@open="openRun" />
			<TemplatesView
				v-else-if="activeSection === 'templates' || activeSection === 'archived'"
				:archivedOnly="activeSection === 'archived'"
				@open="openTemplate" />
			<PlaceholderView
				v-else
				:title="activeLabel"
				:note="placeholderNote"
				:noteType="status === 'error' ? 'warning' : 'info'" />
		</NcAppContent>
	</NcContent>
</template>
