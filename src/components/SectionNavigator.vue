<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { RunSectionWithSteps } from '../models/run.ts'

import { translate as t } from '@nextcloud/l10n'
import { nextTick, ref, watch } from 'vue'
import { sectionProgressLabel } from '../utils/runExecution.ts'
import { sectionStatusLabel, sectionStatusTone } from '../utils/sectionFlow.ts'

const props = defineProps<{
	sections: RunSectionWithSteps[]
	selectedId: number | null
}>()

const emit = defineEmits<{
	select: [sectionId: number]
}>()

const navRoot = ref<HTMLElement | null>(null)
const selectEl = ref<HTMLSelectElement | null>(null)

/**
 * Keep the native picker in sync with the actual selected section, so a
 * cancelled selection visibly returns to the original section.
 */
function syncPicker(): void {
	const value = props.selectedId === null ? '' : String(props.selectedId)
	if (selectEl.value !== null && selectEl.value.value !== value) {
		selectEl.value.value = value
	}
}

/**
 * Keep the selected entry visible inside the internally scrollable list.
 */
function scrollSelectedIntoView(): void {
	navRoot.value?.querySelector<HTMLElement>('[aria-current="true"]')?.scrollIntoView({ block: 'nearest' })
}

watch(() => props.selectedId, () => {
	syncPicker()
	void nextTick().then(scrollSelectedIntoView)
})

/**
 * Section status label.
 *
 * @param entry Section with its steps.
 */
function stateLabel(entry: RunSectionWithSteps): string {
	return sectionStatusLabel(t, entry.state)
}

/**
 * Section progress text: `done/total`, “Outside the current path” for
 * inapplicable sections, or “No steps”.
 *
 * @param entry Section with its steps.
 */
function progressText(entry: RunSectionWithSteps): string {
	return sectionProgressLabel(t, entry)
}

/**
 * Native select option label.
 *
 * @param entry Section with its steps.
 * @param index Section index.
 */
function optionLabel(entry: RunSectionWithSteps, index: number): string {
	return `${index + 1}. ${entry.section.title} — ${stateLabel(entry)} (${progressText(entry)})`
}

/**
 * @param event Change event of the mobile picker.
 */
async function onPick(event: Event): Promise<void> {
	const value = Number((event.target as HTMLSelectElement).value)
	if (Number.isInteger(value)) {
		emit('select', value)
	}
	await nextTick()
	syncPicker()
}
</script>

<template>
	<div ref="navRoot" class="runbook-nav" :aria-label="t('runbook', 'Sections')">
		<label class="runbook-nav__picker">
			<span class="runbook-nav__picker-label">{{ t('runbook', 'Select a section') }}</span>
			<select
				ref="selectEl"
				class="runbook-nav__select"
				:value="selectedId ?? undefined"
				@change="onPick">
				<option v-for="(entry, index) in sections" :key="entry.section.id" :value="entry.section.id">
					{{ optionLabel(entry, index) }}
				</option>
			</select>
		</label>

		<nav class="runbook-nav__list" :aria-label="t('runbook', 'Sections')">
			<ul class="runbook-nav__items">
				<li v-for="(entry, index) in sections" :key="entry.section.id">
					<button
						type="button"
						class="runbook-nav__item"
						:class="[`runbook-nav__item--${sectionStatusTone(entry.state)}`, { 'runbook-nav__item--selected': entry.section.id === props.selectedId }]"
						:aria-current="entry.section.id === props.selectedId ? 'true' : undefined"
						@click="emit('select', entry.section.id)">
						<span class="runbook-nav__index">{{ index + 1 }}</span>
						<span class="runbook-nav__text">
							<span class="runbook-nav__title">{{ entry.section.title }}</span>
							<span class="runbook-nav__meta">
								<span class="runbook-nav__state">{{ stateLabel(entry) }}</span>
								<span class="runbook-nav__count">{{ progressText(entry) }}</span>
							</span>
						</span>
					</button>
				</li>
			</ul>
		</nav>
	</div>
</template>

<style scoped>
.runbook-nav {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-2);
}

.runbook-nav__picker {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-1);
}

.runbook-nav__picker-label {
	font-size: var(--runbook-font-small);
	font-weight: 600;
}

.runbook-nav__select {
	width: 100%;
	min-height: var(--runbook-target-min);
	padding: 0 var(--runbook-space-2);
	border: 1px solid var(--runbook-border-strong);
	border-radius: var(--runbook-radius);
	background-color: var(--runbook-surface);
	color: var(--color-main-text, #222);
}

.runbook-nav__list {
	display: none;
}

.runbook-nav__items {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-1);
	margin: 0;
	padding: 0;
	list-style: none;
	max-height: min(70vh, 42rem);
	overflow-y: auto;
}

.runbook-nav__item {
	display: flex;
	align-items: baseline;
	gap: var(--runbook-space-2);
	width: 100%;
	min-height: var(--runbook-target-min);
	padding: var(--runbook-space-1) var(--runbook-space-2);
	border: 1px solid transparent;
	border-inline-start: 3px solid var(--runbook-border-strong);
	border-radius: var(--runbook-radius);
	background-color: transparent;
	color: var(--color-main-text, #222);
	text-align: start;
	cursor: pointer;
}

.runbook-nav__item:hover,
.runbook-nav__item:focus-visible {
	background-color: var(--runbook-surface-hover);
}

.runbook-nav__item:focus-visible {
	outline: var(--runbook-focus-ring);
	outline-offset: 1px;
}

.runbook-nav__item--selected {
	background-color: var(--runbook-surface-subtle);
	border-color: var(--runbook-border);
	font-weight: 600;
}

.runbook-nav__item--info {
	border-inline-start-color: var(--runbook-accent-info);
}

.runbook-nav__item--success {
	border-inline-start-color: var(--runbook-accent-success);
}

.runbook-nav__item--warning {
	border-inline-start-color: var(--runbook-accent-warning);
}

.runbook-nav__item--muted {
	border-inline-start-color: var(--runbook-border-strong);
}

.runbook-nav__index {
	min-width: 1.4em;
	color: var(--runbook-text-muted);
	font-variant-numeric: tabular-nums;
}

.runbook-nav__text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.runbook-nav__title {
	overflow-wrap: anywhere;
}

.runbook-nav__meta {
	display: flex;
	gap: var(--runbook-space-2);
	color: var(--runbook-text-muted);
	font-size: var(--runbook-font-small);
	font-weight: 400;
}

.runbook-nav__count {
	font-variant-numeric: tabular-nums;
}

@media (min-width: 900px) {
	.runbook-nav__picker {
		display: none;
	}

	.runbook-nav__list {
		display: block;
	}
}
</style>
