<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { SectionPayload, StepPayload, TemplateSection, TemplateStep } from '../models/template.ts'

import { translate as t } from '@nextcloud/l10n'
import { ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import StepEditor from './StepEditor.vue'

const props = defineProps<{
	section: TemplateSection
	steps: TemplateStep[]
	busy?: boolean
	canMoveUp: boolean
	canMoveDown: boolean
}>()

const emit = defineEmits<{
	save: [payload: SectionPayload]
	delete: []
	moveUp: []
	moveDown: []
	addStep: []
	stepSave: [event: { stepId: number, payload: StepPayload }]
	stepDelete: [stepId: number]
	stepMove: [event: { stepId: number, direction: 'up' | 'down' }]
}>()

const title = ref(props.section.title)
const description = ref(props.section.description)

watch(() => props.section, () => {
	title.value = props.section.title
	description.value = props.section.description
}, { deep: true })

/**
 * Emit the current section fields for saving.
 */
function submit(): void {
	emit('save', { title: title.value, description: description.value })
}
</script>

<template>
	<div class="runbook-section">
		<header class="runbook-section__header">
			<NcTextField v-model="title" :label="t('runbook', 'Section title')" />
			<div class="runbook-section__actions">
				<NcButton
					variant="tertiary"
					:disabled="!canMoveUp || busy"
					:ariaLabel="t('runbook', 'Move section up')"
					@click="emit('moveUp')">
					↑
				</NcButton>
				<NcButton
					variant="tertiary"
					:disabled="!canMoveDown || busy"
					:ariaLabel="t('runbook', 'Move section down')"
					@click="emit('moveDown')">
					↓
				</NcButton>
				<NcButton variant="error" @click="emit('delete')">
					{{ t('runbook', 'Delete section') }}
				</NcButton>
			</div>
		</header>

		<NcTextArea v-model="description" :label="t('runbook', 'Section description')" />

		<div class="runbook-section__save">
			<NcButton :disabled="busy" @click="submit">
				{{ t('runbook', 'Save section') }}
			</NcButton>
		</div>

		<div class="runbook-section__steps">
			<StepEditor
				v-for="(step, index) in steps"
				:key="step.id"
				:step="step"
				:busy="busy"
				:canMoveUp="index > 0"
				:canMoveDown="index < steps.length - 1"
				@save="(payload) => emit('stepSave', { stepId: step.id, payload })"
				@delete="emit('stepDelete', step.id)"
				@moveUp="emit('stepMove', { stepId: step.id, direction: 'up' })"
				@moveDown="emit('stepMove', { stepId: step.id, direction: 'down' })" />
			<NcButton :disabled="busy" @click="emit('addStep')">
				{{ t('runbook', 'Add step') }}
			</NcButton>
		</div>
	</div>
</template>

<style scoped>
.runbook-section {
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	padding: 12px;
	margin-bottom: 16px;
}

.runbook-section__header {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
}

.runbook-section__actions {
	display: flex;
	gap: 4px;
	margin-bottom: 4px;
}

.runbook-section__save {
	margin: 8px 0;
}

.runbook-section__steps {
	margin-top: 8px;
}
</style>
