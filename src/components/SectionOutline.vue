<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { SectionOutlineItem } from '../utils/templateAuthoring.ts'

import { translate as t } from '@nextcloud/l10n'
import { nextTick, ref, watch } from 'vue'

const props = defineProps<{
	items: SectionOutlineItem[]
	selectedId: number | null
}>()

const emit = defineEmits<{
	select: [sectionId: number]
}>()

const outlineRoot = ref<HTMLElement | null>(null)
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
	outlineRoot.value?.querySelector<HTMLElement>('[aria-current="true"]')?.scrollIntoView({ block: 'nearest' })
}

watch(() => props.selectedId, () => {
	syncPicker()
	void nextTick().then(scrollSelectedIntoView)
})

/**
 * Step count or the explicit zero-step wording.
 *
 * @param item Outline item.
 */
function stepText(item: SectionOutlineItem): string {
	return item.hasSteps ? t('runbook', '{count} steps', { count: item.stepCount }) : t('runbook', 'No steps')
}

/**
 * @param item Outline item.
 */
function optionLabel(item: SectionOutlineItem): string {
	return `${item.position}. ${item.title} — ${stepText(item)}`
}

/**
 * @param event Change event of the narrow-screen selector.
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
	<div ref="outlineRoot" class="runbook-outline">
		<label class="runbook-outline__picker">
			<span class="runbook-outline__picker-label">{{ t('runbook', 'Select a section to edit') }}</span>
			<select
				ref="selectEl"
				class="runbook-outline__select"
				:value="props.selectedId ?? undefined"
				@change="onPick">
				<option v-for="item in items" :key="item.id" :value="item.id">
					{{ optionLabel(item) }}
				</option>
			</select>
		</label>

		<nav class="runbook-outline__list" :aria-label="t('runbook', 'Sections')">
			<ul class="runbook-outline__items">
				<li v-for="item in items" :key="item.id">
					<button
						type="button"
						class="runbook-outline__item"
						:class="{ 'runbook-outline__item--selected': item.id === props.selectedId }"
						:aria-current="item.id === props.selectedId ? 'true' : undefined"
						@click="emit('select', item.id)">
						<span class="runbook-outline__index">{{ item.position }}</span>
						<span class="runbook-outline__text">
							<span class="runbook-outline__title">{{ item.title }}</span>
							<span class="runbook-outline__meta">
								<span class="runbook-outline__count">{{ stepText(item) }}</span>
								<span v-if="item.id === props.selectedId" class="runbook-outline__editing">
									{{ t('runbook', 'Editing') }}
								</span>
							</span>
						</span>
					</button>
				</li>
			</ul>
		</nav>
	</div>
</template>

<style scoped>
.runbook-outline {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-2);
}

.runbook-outline__picker {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-1);
}

.runbook-outline__picker-label {
	font-size: var(--runbook-font-small);
	font-weight: 600;
}

.runbook-outline__select {
	width: 100%;
	min-height: var(--runbook-target-min);
	padding: 0 var(--runbook-space-2);
	border: 1px solid var(--runbook-border-strong);
	border-radius: var(--runbook-radius);
	background-color: var(--runbook-surface);
	color: var(--color-main-text, #222);
}

.runbook-outline__list {
	display: none;
}

.runbook-outline__items {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-1);
	margin: 0;
	padding: 0;
	list-style: none;
	max-height: min(72vh, 44rem);
	overflow-y: auto;
}

.runbook-outline__item {
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

.runbook-outline__item:hover,
.runbook-outline__item:focus-visible {
	background-color: var(--runbook-surface-hover);
}

.runbook-outline__item:focus-visible {
	outline: var(--runbook-focus-ring);
	outline-offset: 1px;
}

.runbook-outline__item--selected {
	background-color: var(--runbook-surface-subtle);
	border-color: var(--runbook-border);
	font-weight: 600;
}

.runbook-outline__index {
	min-width: 1.4em;
	color: var(--runbook-text-muted);
	font-variant-numeric: tabular-nums;
}

.runbook-outline__text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.runbook-outline__title {
	overflow-wrap: anywhere;
}

.runbook-outline__meta {
	display: flex;
	flex-wrap: wrap;
	gap: var(--runbook-space-2);
	color: var(--runbook-text-muted);
	font-size: var(--runbook-font-small);
	font-weight: 400;
}

.runbook-outline__editing {
	font-weight: 600;
}

.runbook-outline__count {
	font-variant-numeric: tabular-nums;
}

@media (min-width: 900px) {
	.runbook-outline__picker {
		display: none;
	}

	.runbook-outline__list {
		display: block;
	}
}
</style>
