<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { RunStep, SectionReason } from '../models/run.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'
import FlowStatusBadge from './FlowStatusBadge.vue'
import {
	sectionStatusExplanation,
	sectionStatusLabel,
	sectionStatusTone,
	stepCounts,
} from '../utils/sectionFlow.ts'

const props = defineProps<{
	state: string
	steps: RunStep[]
	reasons: SectionReason[]
	blockedBy: string[]
}>()

const hasSteps = computed<boolean>(() => props.steps.length > 0)
const counts = computed(() => stepCounts(props.steps))
const label = computed<string>(() => sectionStatusLabel(t, props.state))
const tone = computed(() => sectionStatusTone(props.state))
const lines = computed<string[]>(() => sectionStatusExplanation(t, {
	state: props.state,
	hasSteps: hasSteps.value,
	completed: counts.value.completed,
	skipped: counts.value.skipped,
	reasons: props.reasons,
	blockedBy: props.blockedBy,
}))
</script>

<template>
	<div class="runbook-section-status">
		<FlowStatusBadge :label="label" :tone="tone" />
		<ul v-if="lines.length > 0" class="runbook-section-status__lines">
			<li v-for="(line, index) in lines" :key="index" class="runbook-section-status__line">
				{{ line }}
			</li>
		</ul>
	</div>
</template>

<style scoped>
.runbook-section-status {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: var(--runbook-space-2) var(--runbook-space-3);
	margin: var(--runbook-space-1) 0 var(--runbook-space-2);
}

.runbook-section-status__lines {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin: 0;
	padding: 0;
	list-style: none;
	color: var(--runbook-text-muted);
	font-size: var(--runbook-font-small);
}

.runbook-section-status__line {
	overflow-wrap: anywhere;
}
</style>
