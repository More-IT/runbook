/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig(
	{
		main: 'src/main.ts',
		'admin-settings': 'src/admin-settings.ts',
	},
	{
		// Runbook only targets the browser; Node core module polyfills are not
		// needed and would bloat the bundle.
		nodePolyfills: false,
		// Disabled: the REUSE license plugin is not needed for the foundation.
		extractLicenseInformation: false,
		// Force a stable stylesheet name so templates/main.php can reference it
		// with Util::addStyle('runbook', 'runbook-main').
		assetFileNames: (assetInfo) => {
			const [name] = assetInfo.names
			if (name !== undefined && name.endsWith('.css')) {
				return 'css/runbook-main.css'
			}
			return undefined
		},
		config: {
			build: {
				// Emit a single, stable CSS file (css/runbook-main.css) so the
				// Nextcloud template can reference it with Util::addStyle().
				cssCodeSplit: false,
				// Release builds do not ship source maps; the package only
				// contains the compiled assets referenced by the templates.
				sourcemap: false,
			},
		},
	},
)
