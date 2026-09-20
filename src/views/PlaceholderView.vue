<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import RunbookIcon from '../components/RunbookIcon.vue'

defineProps<{
	title: string
	note?: string
	noteType?: 'info' | 'warning'
}>()
</script>

<template>
	<NcEmptyContent
		:name="title"
		:description="t('runbook', 'This area will be implemented in a later milestone.')">
		<template #icon>
			<RunbookIcon />
		</template>
		<template v-if="note" #action>
			<NcNoteCard :type="noteType ?? 'info'">
				{{ note }}
			</NcNoteCard>
		</template>
	</NcEmptyContent>
</template>
