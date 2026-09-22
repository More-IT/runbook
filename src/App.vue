<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { NavTarget } from './utils/navigationGuard.ts'
import type { EditorDraftInput, NavHistoryState } from './utils/navigationHistory.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, onMounted, ref, watch } from 'vue'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import ConfirmDialog from './components/ConfirmDialog.vue'
import MyWorkView from './views/MyWorkView.vue'
import OverviewView from './views/OverviewView.vue'
import PlaceholderView from './views/PlaceholderView.vue'
import RunDetailView from './views/RunDetailView.vue'
import RunsView from './views/RunsView.vue'
import TemplateEditorView from './views/TemplateEditorView.vue'
import TemplatesView from './views/TemplatesView.vue'
import { useBackendStatus } from './composables/useBackendStatus.ts'
import { mergeAppPosition, readAppPosition, withoutAppPosition } from './utils/historyStack.ts'
import {
	hashForTarget,
	parseHashTarget,
} from './utils/navigationGuard.ts'
import {
	cancelHistory,
	confirmHistory,
	createNavHistory,
	handlePop,
	markCurrentEntry,
	requestHistory,
	setCurrentPosition,
} from './utils/navigationHistory.ts'
import { buildPageTitle } from './utils/pageTitle.ts'

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
const focusStepId = ref<number | null>(null)

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

// Explicit, localized tab title. Passing `pageTitle` to NcAppContent avoids its
// internal app-name lookup (which can stringify a non-string value to
// `[object Object]`) while keeping the visible heading above unchanged.
const pageTitle = computed<string>(() => buildPageTitle([pageHeading.value, t('runbook', 'Runbook')]))

const placeholderNote = computed<string | undefined>(() => activeSection.value === 'overview' ? backendStatusText.value : undefined)

/** Template editor ref: drafts are read synchronously and discarded on confirm. */
const editorRef = ref<{ hasDrafts: () => boolean, discardAllDrafts: () => void } | null>(null)
/** Reactive mirror of the editor draft state (fallback when the ref is absent). */
const editorDirty = ref(false)
/** Navigation/history state: physical entry positions plus any deferred target. */
const navHistory = ref<NavHistoryState>(createNavHistory())

const navState = computed(() => ({
	section: activeSection.value,
	templateId: editingTemplateId.value,
	runId: activeRunId.value,
	stepId: focusStepId.value,
}))

/**
 * Default app target.
 */
function defaultTarget(): NavTarget {
	return { section: 'templates', templateId: null, runId: null, stepId: null }
}

/**
 * Read the editor drafts synchronously, so a decide-then-emit sequence cannot
 * show a second confirmation dialog.
 */
function editorHasDrafts(): boolean {
	return editorRef.value?.hasDrafts() ?? editorDirty.value
}

/**
 * Editor draft input for the history controller.
 */
function editorInput(): EditorDraftInput {
	return { templateId: editingTemplateId.value, dirty: editorHasDrafts() }
}

/**
 * Render a target without touching the URL or the history stack.
 *
 * @param target Navigation target.
 */
function renderTarget(target: NavTarget): void {
	activeSection.value = target.section
	editingTemplateId.value = target.templateId
	activeRunId.value = target.runId
	focusStepId.value = target.stepId
}

/**
 * Physical position of the current entry, or null when it is unknown. It is
 * never inferred from `history.length` (that is the last entry, not the current
 * one).
 */
function basePosition(): number | null {
	return navHistory.value.currentPosition
}

/**
 * Push a new app entry, preserving any history state owned by the shell. Returns
 * whether the app metadata could be stored; a null/unknown position (or a
 * non-plain state) pushes without metadata and is treated as a foreign entry.
 *
 * @param plan Hash and physical position to write.
 * @param plan.hash Location hash for the new entry.
 * @param plan.position Physical position, or null when unknown.
 */
function pushEntryPlan(plan: { hash: string, position: number | null }): boolean {
	if (plan.position === null) {
		window.history.pushState(withoutAppPosition(window.history.state), '', plan.hash)

		return false
	}
	const merged = mergeAppPosition(window.history.state, plan.position)
	window.history.pushState(merged.state, '', plan.hash)

	return merged.stored
}

/**
 * Programmatic navigation (sidebar, template switch, run links, close). When the
 * editor is dirty and the target leaves it, the target is deferred.
 *
 * @param target Intended navigation target.
 */
function navigate(target: NavTarget): void {
	const transition = requestHistory(navHistory.value, target, editorInput(), hashForTarget(target), basePosition())
	navHistory.value = transition.state
	if (transition.push !== null && !pushEntryPlan(transition.push)) {
		navHistory.value = { ...navHistory.value, currentPosition: null, editorPosition: null }
	}
	if (transition.render !== null) {
		renderTarget(transition.render)
	}
}

/**
 * Browser Back/Forward. App positions give the real destination and physical
 * distance; foreign entries are not guessed. Returning to the entry we still
 * render (Back → Forward while the dialog is open) clears the pending decision.
 *
 * @param event Popstate event.
 */
function onPopState(event: PopStateEvent): void {
	const hash = window.location.hash !== '' ? window.location.hash : '#templates'
	const target = parseHashTarget(hash, navigation.map((entry) => entry.id)) ?? defaultTarget()
	const transition = handlePop(
		navHistory.value,
		{ hash, position: readAppPosition(event.state), target, renderedTarget: navState.value },
		editorInput(),
	)
	navHistory.value = transition.state
	if (transition.render !== null) {
		renderTarget(transition.render)
	}
}

/**
 * Confirm discarding the editor drafts and perform the pending navigation once.
 */
function confirmDiscardNavigation(): void {
	const transition = confirmHistory(navHistory.value, editorInput(), basePosition())
	navHistory.value = transition.state
	if (transition.discard) {
		editorRef.value?.discardAllDrafts()
		editorDirty.value = false
	}
	if (transition.push !== null && !pushEntryPlan(transition.push)) {
		navHistory.value = { ...navHistory.value, currentPosition: null, editorPosition: null }
	}
	if (transition.render !== null) {
		renderTarget(transition.render)
	}
}

/**
 * Cancel a guarded navigation: keep the editor and drafts. A tracked destination
 * moves the browser by the exact physical distance; a foreign entry re-anchors
 * the editor URL instead of a guessed movement.
 */
function cancelDiscardNavigation(): void {
	const transition = cancelHistory(navHistory.value, editorInput(), hashForTarget(navState.value), basePosition())
	navHistory.value = transition.state
	if (transition.anchor !== null && !pushEntryPlan(transition.anchor)) {
		navHistory.value = { ...navHistory.value, currentPosition: null, editorPosition: null }
	}
	if (transition.jump !== 0) {
		window.history.go(transition.jump)
	}
}

/**
 * Switch the active navigation section.
 *
 * @param id Navigation entry identifier.
 */
function selectSection(id: string): void {
	navigate({ section: id, templateId: null, runId: null, stepId: null })
}

/**
 * Open the editor for a template.
 *
 * @param id Template identifier.
 */
function openTemplate(id: number): void {
	navigate({ section: 'templates', templateId: id, runId: null, stepId: null })
}

/**
 * Open a run detail view, optionally focusing a specific step.
 *
 * @param id Run identifier.
 * @param stepId Run step identifier to focus, if any.
 */
function openRun(id: number, stepId: number | null = null): void {
	navigate({ section: 'runs', templateId: null, runId: id, stepId })
}

/**
 * Handle a run started from the template editor.
 *
 * @param id Newly created run identifier.
 */
function onRunStarted(id: number): void {
	navigate({ section: 'runs', templateId: null, runId: id, stepId: null })
}

/**
 * Close the run detail view and return to the run list.
 */
function closeRun(): void {
	navigate({ section: 'runs', templateId: null, runId: null, stepId: null })
}

/**
 * Close the template editor and return to the template list.
 */
function closeTemplate(): void {
	navigate({ section: 'templates', templateId: null, runId: null, stepId: null })
}

// The editor dirty flag never survives closing or switching the edited template.
watch(editingTemplateId, (value) => {
	if (value === null) {
		editorDirty.value = false
	}
})

onMounted(() => {
	checkBackendStatus()
	const initialHash = window.location.hash !== '' ? window.location.hash : '#templates'
	const target = parseHashTarget(initialHash, navigation.map((entry) => entry.id)) ?? defaultTarget()
	// Retain a stored physical position; never infer it from history.length.
	const storedPosition = readAppPosition(window.history.state)
	const merged = storedPosition === null
		? { state: window.history.state, stored: false }
		: mergeAppPosition(window.history.state, storedPosition)
	window.history.replaceState(merged.state, '', initialHash)
	navHistory.value = markCurrentEntry(
		setCurrentPosition(createNavHistory(), merged.stored ? storedPosition : null),
		target,
	)
	window.addEventListener('popstate', onPopState)
	renderTarget(target)
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
					:active="activeSection === entry.id"
					:href="`#${entry.id}`"
					@click.prevent="selectSection(entry.id)" />
			</template>
		</NcAppNavigation>
		<NcAppContent :pageHeading="pageHeading" :pageTitle="pageTitle">
			<RunDetailView
				v-if="activeRunId !== null"
				:runId="activeRunId"
				:focusStepId="focusStepId"
				@close="closeRun" />
			<TemplateEditorView
				v-else-if="editingTemplateId !== null"
				:key="editingTemplateId"
				ref="editorRef"
				:templateId="editingTemplateId"
				@update:dirty="editorDirty = $event"
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

		<ConfirmDialog
			v-if="navHistory.pending !== null"
			:name="t('runbook', 'Discard unsaved changes')"
			:message="t('runbook', 'You have unsaved changes. Discard them and leave the editor?')"
			:confirmLabel="t('runbook', 'Discard')"
			:busy="false"
			@confirm="confirmDiscardNavigation"
			@cancel="cancelDiscardNavigation" />
	</NcContent>
</template>
