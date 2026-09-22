/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import AdminSettingsApp from './admin/AdminSettingsApp.vue'

import './styles/tokens.css'

const app = createApp(AdminSettingsApp)

app.mount('#runbook-admin-settings')
