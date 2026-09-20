<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'

defineProps<{
	name: string
	message: string
	confirmLabel?: string
	busy?: boolean
}>()

const emit = defineEmits<{
	confirm: []
	cancel: []
}>()
</script>

<template>
	<NcDialog :name="name" size="small" @closing="emit('cancel')">
		<p>{{ message }}</p>
		<template #actions>
			<NcButton :disabled="busy" @click="emit('cancel')">
				{{ t('runbook', 'Cancel') }}
			</NcButton>
			<NcButton variant="error" :disabled="busy" @click="emit('confirm')">
				{{ confirmLabel ?? t('runbook', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>
