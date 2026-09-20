<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { RunStatus } from '../models/run.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'

const props = defineProps<{
	status: RunStatus
}>()

const label = computed<string>(() => {
	switch (props.status) {
		case 'COMPLETED':
			return t('runbook', 'Completed')
		case 'CANCELLED':
			return t('runbook', 'Cancelled')
		default:
			return t('runbook', 'Active')
	}
})
</script>

<template>
	<span class="runbook-badge" :class="`runbook-badge--${status.toLowerCase()}`">
		{{ label }}
	</span>
</template>

<style scoped>
.runbook-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius, 4px);
	font-size: 0.85em;
	font-weight: bold;
	line-height: 1.6;
}

.runbook-badge--active {
	background-color: var(--color-primary-element, #0082c9);
	color: #fff;
}

.runbook-badge--completed {
	background-color: var(--color-success, #46ba61);
	color: #fff;
}

.runbook-badge--cancelled {
	background-color: var(--color-background-dark, #ededed);
	color: var(--color-text-maxcontrast, #555);
}
</style>
