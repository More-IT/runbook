<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { AclEntryPayload, AssignableAclRole } from '../models/template.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { ASSIGNABLE_ACL_ROLES } from '../models/template.ts'

interface RoleOption {
	id: AssignableAclRole
	label: string
}

const props = defineProps<{
	entry: AclEntryPayload
	readOnly: boolean
}>()

const emit = defineEmits<{
	update: [role: AssignableAclRole]
	remove: []
}>()

/**
 * Human readable label for an assignable role.
 *
 * @param role Assignable ACL role.
 */
function roleLabel(role: AssignableAclRole): string {
	switch (role) {
		case 'EXECUTOR':
			return t('runbook', 'Executor')
		case 'EDITOR':
			return t('runbook', 'Editor')
		default:
			return t('runbook', 'Viewer')
	}
}

const roleOptions: RoleOption[] = ASSIGNABLE_ACL_ROLES.map((role) => ({ id: role, label: roleLabel(role) }))

const selectedRole = computed<RoleOption | null>({
	get: () => roleOptions.find((option) => option.id === props.entry.role) ?? null,
	set: (option) => {
		if (option !== null) {
			emit('update', option.id)
		}
	},
})

const typeLabel = computed<string>(() => props.entry.principalType === 'GROUP'
	? t('runbook', 'Group')
	: t('runbook', 'User'))
</script>

<template>
	<div class="runbook-acl-row">
		<span class="runbook-acl-row__principal">{{ entry.principalId }}</span>
		<span class="runbook-acl-row__type">{{ typeLabel }}</span>
		<div class="runbook-acl-row__role">
			<NcSelect
				v-if="!readOnly"
				v-model="selectedRole"
				:options="roleOptions"
				label="label"
				:clearable="false" />
			<span v-else>{{ selectedRole?.label }}</span>
		</div>
		<NcButton v-if="!readOnly" variant="error" @click="emit('remove')">
			{{ t('runbook', 'Remove') }}
		</NcButton>
	</div>
</template>

<style scoped>
.runbook-acl-row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
}

.runbook-acl-row__principal {
	font-weight: bold;
	flex: 1 1 auto;
	overflow-wrap: anywhere;
}

.runbook-acl-row__type {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
}

.runbook-acl-row__role {
	min-width: 160px;
}
</style>
