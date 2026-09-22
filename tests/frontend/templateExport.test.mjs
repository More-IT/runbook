/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Frontend tests for the template export action (issue #30).
 *
 * The filename sanitisation and the translation/service wiring are exercised
 * directly. The actual download (`downloadJson`) relies on DOM APIs and is not
 * executed here — there is no DOM harness in this repository.
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import { exportFilename, formatTemplateExport } from '../../src/utils/templateExport.ts'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

/**
 * @param {string} file Relative path under l10n/.
 */
function translations(file) {
	return JSON.parse(readFileSync(join(root, 'l10n', file), 'utf8')).translations
}

test('export filenames are safe and predictable', () => {
	assert.equal(exportFilename('Deploy'), 'deploy.json')
	assert.equal(exportFilename('Release  2024 / Q1'), 'release-2024-q1.json')
	assert.equal(exportFilename('Café'), 'cafe.json')
	assert.equal(exportFilename('../../etc/passwd'), 'etc-passwd.json')
	assert.equal(exportFilename('   '), 'runbook-template.json')
	assert.equal(exportFilename('日本語'), 'runbook-template.json')
})

test('export filenames strip control characters and stay bounded', () => {
	assert.equal(exportFilename('a\u0000b\u001fc'), 'a-b-c.json')
	const long = exportFilename('x'.repeat(200))
	assert.ok(long.length <= 85, `unexpected length: ${long.length}`)
	assert.ok(long.endsWith('.json'))
	assert.equal(long, 'x'.repeat(80) + '.json')
})

test('the emitted export text is human-readable pretty JSON', () => {
	const doc = { format: 'runbook-template', schemaVersion: 1, sections: [], steps: [] }
	const text = formatTemplateExport(doc)
	assert.equal(text, JSON.stringify(doc, null, 2))
	assert.ok(text.includes('\n  "format": "runbook-template"'), 'exports are pretty-printed with a 2-space indent')
	assert.doesNotThrow(() => JSON.parse(text))
})

test('export labels exist in en and pt_PT and pt_BR mirrors pt_PT', () => {
	const en = translations('en.json')
	const pt = translations('pt_PT.json')
	const br = translations('pt_BR.json')

	for (const key of ['Export', 'Exporting…']) {
		assert.ok(en[key], `en missing ${key}`)
		assert.ok(pt[key], `pt_PT missing ${key}`)
		assert.ok(br[key], `pt_BR missing ${key}`)
	}
	assert.equal(pt.Export, 'Exportar')
	assert.equal(br.Export, pt.Export)
	assert.equal(br['Exporting…'], pt['Exporting…'])
})

test('the list view exposes an export action and the service calls the export endpoint', () => {
	const view = readFileSync(join(root, 'src', 'views', 'TemplatesView.vue'), 'utf8')
	assert.ok(view.includes('exportTemplateFile'), 'the view must define the export handler')
	assert.ok(view.includes("t('runbook', 'Export')"), 'the view must render an Export action')
	assert.ok(view.includes("t('runbook', 'Exporting…')"), 'the view must show an in-progress label')
	assert.ok(view.includes('exportError'), 'the view must surface export errors')

	const service = readFileSync(join(root, 'src', 'services', 'templates.ts'), 'utf8')
	assert.ok(service.includes('/templates/${id}/export'), 'the service must call the export endpoint')

	const utils = readFileSync(join(root, 'src', 'utils', 'templateExport.ts'), 'utf8')
	assert.ok(utils.includes('URL.revokeObjectURL'), 'the object URL must be revoked after download')
})

test('export_reference_unresolved maps to a clear message without internal ids', async () => {
	globalThis._oc_l10n_registry_translations ??= {}
	globalThis._oc_l10n_registry_plural_functions ??= {}
	globalThis._oc_l10n_registry_translations.runbook = translations('en.json')

	// Imported after the catalog is registered, so the reason map uses English.
	const { apiErrorMessage } = await import('../../src/utils/apiError.ts')
	const message = apiErrorMessage({ response: { data: { reason: 'export_reference_unresolved' } } })

	assert.equal(message, 'This template cannot be exported because a referenced section or step is missing or inconsistent.')
	assert.doesNotMatch(message, /[0-9]/, 'the message must not expose internal identifiers')

	const key = 'This template cannot be exported because a referenced section or step is missing or inconsistent.'
	const pt = translations('pt_PT.json')
	const br = translations('pt_BR.json')
	assert.ok(pt[key], 'pt_PT must translate the export error')
	assert.notEqual(pt[key], key, 'pt_PT must not fall back to English')
	assert.equal(br[key], pt[key], 'pt_BR must mirror pt_PT exactly')
})
