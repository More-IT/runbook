<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { TemplateStatus } from '../models/template.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'

const props = defineProps<{
	status: TemplateStatus
}>()

const label = computed<string>(() => {
	switch (props.status) {
		case 'PUBLISHED':
			return t('runbook', 'Published')
		case 'ARCHIVED':
			return t('runbook', 'Archived')
		default:
			return t('runbook', 'Draft')
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

.runbook-badge--draft {
	background-color: var(--color-background-dark, #ededed);
	color: var(--color-text-maxcontrast, #555);
}

.runbook-badge--published {
	background-color: var(--color-success, #46ba61);
	color: #fff;
}

.runbook-badge--archived {
	background-color: var(--color-background-dark, #ededed);
	color: var(--color-text-lighter, #777);
}
</style>
