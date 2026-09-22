<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { SectionStatusTone } from '../utils/sectionFlow.ts'

defineProps<{
	label: string
	tone: SectionStatusTone
}>()
</script>

<template>
	<span class="runbook-flow-badge" :class="`runbook-flow-badge--${tone}`">{{ label }}</span>
</template>

<style scoped>
.runbook-flow-badge {
	display: inline-flex;
	align-items: center;
	min-height: 24px;
	padding: 0 var(--runbook-space-3);
	border: 1px solid var(--runbook-border);
	border-radius: var(--runbook-radius-pill);
	background-color: var(--runbook-surface-subtle);
	color: var(--color-main-text, #222);
	font-size: 0.8em;
	font-weight: 600;
	line-height: 1.4;
	white-space: nowrap;
}

.runbook-flow-badge--info {
	border-color: var(--runbook-accent-info);
}

.runbook-flow-badge--success {
	border-color: var(--runbook-accent-success);
}

.runbook-flow-badge--warning {
	border-color: var(--runbook-accent-warning);
}

.runbook-flow-badge--muted {
	color: var(--runbook-text-muted);
}
</style>
