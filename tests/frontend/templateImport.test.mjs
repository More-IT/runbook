/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Frontend tests for the template import action (issue #31).
 *
 * The parsing/summary helper and the translation/service/view wiring are
 * exercised directly. The real file picker, file reading and navigation rely on
 * DOM APIs and are not executed here — there is no DOM harness in this
 * repository.
 */

import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import {
	assertSourceFileSize,
	isFileTooLarge,
	MAX_SOURCE_FILE_BYTES,
	parseTemplateImport,
	summariseDocument,
	TemplateImportError,
} from '../../src/utils/templateImport.ts'
import { formatTemplateExport } from '../../src/utils/templateExport.ts'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

/**
 * @param {string} file Relative path under l10n/.
 */
function translations(file) {
	return JSON.parse(readFileSync(join(root, 'l10n', file), 'utf8')).translations
}

/**
 * @param {string} title Template title.
 */
function document(title = 'Deploy') {
	return {
		format: 'runbook-template',
		schemaVersion: 1,
		template: { title, description: 'Notes' },
		sections: [
			{ ref: 's1', title: 'First', description: '', notes: '', dependsOn: [], conditions: [] },
			{ ref: 's2', title: 'Zero', description: '', notes: '', dependsOn: [], conditions: [] },
		],
		steps: [
			{ ref: 't1', sectionRef: 's1', title: 'Step', description: '', type: 'TEXT', required: true, position: 0, config: {}, defaultAssignee: null, dueOffset: null },
		],
	}
}

test('a valid export file is decoded and summarised', () => {
	const parsed = parseTemplateImport(JSON.stringify(document()))
	assert.equal(parsed.summary.title, 'Deploy')
	assert.equal(parsed.summary.description, 'Notes')
	assert.equal(parsed.summary.sections, 2)
	assert.equal(parsed.summary.steps, 1)
	assert.equal(parsed.document.format, 'runbook-template')
})

test('invalid JSON is reported as invalid_json', () => {
	assert.throws(() => parseTemplateImport('{ not json'), (error) => {
		assert.ok(error instanceof TemplateImportError)
		assert.equal(error.code, 'invalid_json')
		return true
	})
})

test('malformed or unsupported documents are reported as invalid_document', () => {
	const cases = [
		[],
		{ format: 'other', schemaVersion: 1, template: { title: 'x' }, sections: [], steps: [] },
		{ format: 'runbook-template', schemaVersion: 2, template: { title: 'x' }, sections: [], steps: [] },
		{ format: 'runbook-template', schemaVersion: 1, template: {}, sections: [], steps: [] },
		{ format: 'runbook-template', schemaVersion: 1, template: { title: 'x' }, sections: [] },
	]
	for (const input of cases) {
		assert.throws(() => summariseDocument(input), (error) => error instanceof TemplateImportError && error.code === 'invalid_document')
	}
})

test('empty configuration objects and zero-step sections are accepted', () => {
	const input = document()
	input.steps[0].config = {}
	assert.doesNotThrow(() => summariseDocument(input))
	const parsed = parseTemplateImport(JSON.stringify(input))
	assert.equal(parsed.summary.sections, 2)
})

test('the source-file safety cap rejects only files above it', () => {
	assert.equal(isFileTooLarge(0), false)
	assert.equal(isFileTooLarge(MAX_SOURCE_FILE_BYTES), false)
	assert.equal(isFileTooLarge(MAX_SOURCE_FILE_BYTES + 1), true)

	assert.doesNotThrow(() => assertSourceFileSize(MAX_SOURCE_FILE_BYTES))
	assert.throws(() => assertSourceFileSize(MAX_SOURCE_FILE_BYTES + 1), (error) => {
		assert.ok(error instanceof TemplateImportError)
		assert.equal(error.code, 'too_large')
		return true
	})
})

/**
 * Build a structurally valid document whose compact JSON is exactly 2,000,000
 * bytes using only field values within the authoring limits (titles,
 * descriptions, notes, configuration).
 */
function nearServerLimitDocument() {
	const sections = []
	for (let index = 0; index < 98; index++) {
		sections.push({
			ref: `s${index}`,
			title: `Section ${index}`,
			description: 'a'.repeat(10000),
			notes: 'b'.repeat(10000),
			dependsOn: [],
			conditions: [],
		})
	}
	const doc = {
		format: 'runbook-template',
		schemaVersion: 1,
		template: { title: 'Boundary', description: '' },
		sections,
		steps: [{
			ref: 't1', sectionRef: 's0', title: 'Pad', description: '', type: 'TEXT',
			required: false, position: 0, config: { pad: '' }, defaultAssignee: null, dueOffset: null,
		}],
	}
	const remaining = 2000000 - Buffer.byteLength(JSON.stringify(doc), 'utf8')
	doc.steps[0].config.pad = 'a'.repeat(remaining)
	return doc
}

test('the app can import its own pretty-printed export even when the file is over 2 MB', () => {
	const doc = nearServerLimitDocument()
	const compactBytes = Buffer.byteLength(JSON.stringify(doc), 'utf8')
	assert.equal(compactBytes, 2000000, 'the canonical document is exactly at the server limit')

	// The exact text downloadJson() writes (2-space pretty printing).
	const fileText = formatTemplateExport(doc)
	const fileBytes = Buffer.byteLength(fileText, 'utf8')
	assert.ok(fileBytes > 2000000, `the emitted file is over 2 MB (got ${fileBytes})`)
	assert.equal(isFileTooLarge(fileBytes), false, 'the file is under the source-file safety cap')

	// The browser path accepts it; the server's canonical cap stays authoritative.
	const parsed = parseTemplateImport(fileText)
	assert.equal(parsed.summary.title, 'Boundary')
	assert.equal(parsed.summary.sections, 98)

	// The parsed payload the browser would submit is byte-for-byte the canonical
	// document (ASCII), so it is exactly what the server measures.
	const submitted = Buffer.from(JSON.stringify(parsed.document), 'utf8')
	assert.equal(submitted.length, 2000000, 'the submitted body is the canonical 2,000,000 bytes')
	assert.ok(Buffer.compare(submitted, Buffer.from(JSON.stringify(doc), 'utf8')) === 0, 'the round trip is lossless')
})

test('the browser never rejects a document because of a differing JavaScript size estimate', () => {
	// JS serialises 1e-6 as "0.000001" (8 bytes) while PHP json_encode emits
	// "1.0e-6" (6 bytes), so a document can be over 2 MB as measured by
	// JSON.stringify yet well within the server's canonical cap. The client must
	// not block it.
	const values = Array.from({ length: 8569 }, () => 1e-6)
	const steps = []
	for (let index = 0; index < 26; index++) {
		steps.push({
			ref: `t${index}`, sectionRef: 's0', title: 'Numbers', description: '', type: 'NUMBER',
			required: false, position: index, config: { v: values }, defaultAssignee: null, dueOffset: null,
		})
	}
	const doc = {
		format: 'runbook-template',
		schemaVersion: 1,
		template: { title: 'Numbers', description: '' },
		sections: [{ ref: 's0', title: 'S', description: '', notes: '', dependsOn: [], conditions: [] }],
		steps,
	}

	assert.ok(Buffer.byteLength(JSON.stringify(doc), 'utf8') > 2000000, 'the JS estimate is over 2 MB')
	assert.doesNotThrow(() => parseTemplateImport(JSON.stringify(doc)), 'the client must not reject on the JS estimate')
})

test('JavaScript and PHP serialise some values differently (documented divergence)', () => {
	assert.equal(JSON.stringify(1e21), '1e+21', 'JS writes 1e+21')
	assert.equal(JSON.stringify(1e-6), '0.000001', 'JS writes 0.000001')
	const lineSeparator = JSON.stringify('a\u2028b')
	assert.ok(lineSeparator.includes('\u2028'), 'JS keeps U+2028 literal')
	assert.ok(!lineSeparator.includes('\\u2028'), 'JS does not escape U+2028')
})

test('accented unicode is measured as UTF-8 bytes, not escape sequences', () => {
	// Each "é" is 2 UTF-8 bytes. JSON.stringify (and therefore Axios) keeps the
	// literal character, so a Portuguese payload that is valid at the limit is
	// not over-counted the way PHP's default json_encode would.
	const value = { text: 'Configuração é ação'.repeat(1000) }
	assert.equal(
		Buffer.byteLength(JSON.stringify({ x: 'é' }), 'utf8') - Buffer.byteLength(JSON.stringify({ x: '' }), 'utf8'),
		2,
		'each accented character counts as 2 UTF-8 bytes',
	)
	assert.ok(JSON.stringify(value).includes('é'), 'JSON.stringify must not escape accented characters')
	assert.ok(!JSON.stringify(value).includes('\\u00e9'), 'accented characters must stay as UTF-8')
})

test('accented Portuguese text survives the client parse round trip', () => {
	const input = document('Configuração de ação')
	input.template.description = 'Descrição'
	const parsed = parseTemplateImport(JSON.stringify(input))
	assert.equal(parsed.summary.title, 'Configuração de ação')
	assert.equal(parsed.summary.description, 'Descrição')
	assert.equal(parsed.document.template.title, 'Configuração de ação')
})

test('malicious-looking text is preserved verbatim and never interpreted', () => {
	const payloads = {
		script: '<script>alert(1)</script>',
		img: '"><img src=x onerror=alert(1)>',
		entities: '&lt;b&gt;&amp;&#39;',
		unixPath: '../../etc/passwd',
		winPath: 'C:\\Windows\\System32',
		separator: 'a\u2028b',
		emoji: '😀 emoji',
	}
	const input = document(payloads.script)
	input.template.description = payloads.img
	input.sections[0].notes = payloads.entities
	input.steps[0].config = {
		unix: payloads.unixPath,
		win: payloads.winPath,
		separator: payloads.separator,
		emoji: payloads.emoji,
	}

	const parsed = parseTemplateImport(JSON.stringify(input))

	assert.equal(parsed.document.template.title, payloads.script)
	assert.equal(parsed.document.template.description, payloads.img)
	assert.equal(parsed.document.sections[0].notes, payloads.entities)
	assert.equal(parsed.document.steps[0].config.unix, payloads.unixPath)
	assert.equal(parsed.document.steps[0].config.win, payloads.winPath)
	assert.equal(parsed.document.steps[0].config.separator, payloads.separator)
	assert.equal(parsed.document.steps[0].config.emoji, payloads.emoji)
	assert.equal(parsed.summary.title, payloads.script, 'the confirmation summary keeps the raw title text')
})

test('the frontend never renders template text as raw HTML', () => {
	const files = []
	const walk = (directory) => {
		for (const entry of readdirSync(directory, { withFileTypes: true })) {
			const path = join(directory, entry.name)
			if (entry.isDirectory()) {
				walk(path)
			} else if (/\.(vue|ts)$/.test(entry.name)) {
				files.push(path)
			}
		}
	}
	walk(join(root, 'src'))
	assert.ok(files.length > 0, 'the source tree must contain files to scan')

	for (const file of files) {
		const text = readFileSync(file, 'utf8')
		assert.ok(!/\bv-html\b/.test(text), `${file} must not use v-html`)
		assert.ok(!/\.innerHTML\b/.test(text), `${file} must not assign innerHTML`)
		assert.ok(!/\.outerHTML\b/.test(text), `${file} must not assign outerHTML`)
	}
})

test('import labels exist in en and pt_PT and pt_BR mirrors pt_PT', () => {
	const en = translations('en.json')
	const pt = translations('pt_PT.json')
	const br = translations('pt_BR.json')

	for (const key of [
		'Import template',
		'Import',
		'Importing…',
		'Steps',
		'This file is not valid JSON.',
		'This file is too large to import.',
		'The template was created, but the list could not be refreshed.',
		'The template was created, but it could not be opened automatically.',
		'The template was created as a new draft.',
		'Open imported template',
		'Dismiss',
	]) {
		assert.ok(en[key], `en missing ${key}`)
		assert.ok(pt[key], `pt_PT missing ${key}`)
		assert.ok(br[key], `pt_BR missing ${key}`)
		assert.notEqual(pt[key], en[key], `pt_PT must not fall back to English for ${key}`)
		assert.equal(br[key], pt[key], `pt_BR must mirror pt_PT for ${key}`)
	}
	assert.equal(pt['Import template'], 'Importar modelo')
	assert.equal(pt['Open imported template'], 'Abrir modelo importado')
})

test('the list view exposes an import action and the service calls the import endpoint', () => {
	const view = readFileSync(join(root, 'src', 'views', 'TemplatesView.vue'), 'utf8')
	assert.ok(view.includes('onImportFileSelected'), 'the view must handle file selection')
	assert.ok(view.includes('confirmImport'), 'the view must confirm before importing')
	assert.ok(view.includes("t('runbook', 'Import template')"), 'the view must render an Import action')
	assert.ok(view.includes("t('runbook', 'Importing…')"), 'the view must show an in-progress label')
	assert.ok(view.includes(':disabled="importing"'), 'the view must prevent double submission')
	assert.ok(view.includes('accept=".json,application/json"'), 'the view must accept JSON files')
	assert.ok(view.includes('input.value = \'\''), 'the input must reset so the same file can be chosen again')
	assert.ok(view.includes("emit('open', template.id)"), 'the view must open the imported draft')
	assert.ok(view.includes('refresh: () => refresh()'), 'the view must refresh the list after import')

	const service = readFileSync(join(root, 'src', 'services', 'templates.ts'), 'utf8')
	assert.ok(service.includes("endpoint('/templates/import')"), 'the service must call the import endpoint')
})

test('the view guards file size and dialog close paths while importing', () => {
	const view = readFileSync(join(root, 'src', 'views', 'TemplatesView.vue'), 'utf8')

	// The source-file safety cap is checked from File.size before the file is
	// read into memory; no document-size decision is made in the browser.
	assert.ok(view.includes('assertSourceFileSize(file.size)'), 'the view must apply the source-file cap before reading')
	assert.ok(
		view.indexOf('assertSourceFileSize(file.size)') < view.indexOf('await file.text()'),
		'the source-file check must run before file.text()',
	)
	assert.ok(!view.includes('importPayloadBytes'), 'the view must not gate on a JavaScript size estimate')

	// While pending, the supported user close paths are suppressed and Cancel is
	// disabled. `@closing` is only a notification, so the handler performs
	// cleanup and is guarded rather than claimed to veto closure.
	assert.ok(view.includes(':noClose="importing"'), 'the close control must be suppressed while importing')
	assert.ok(view.includes(':closeOnClickOutside="!importing"'), 'the backdrop must not close the dialog while importing')
	assert.ok(view.includes('@closing="onImportDialogClosing"'), 'close notifications must run the cleanup handler')
	assert.ok(view.includes('canCloseImportDialog(importing.value)'), 'the cleanup/cancel guard must use the shared predicate')
	assert.ok(view.includes('canStartImport(importing.value)'), 'submission must be idempotent through the shared predicate')
	assert.ok(view.includes(':disabled="importing" @click="onImportCancel"'), 'Cancel must be disabled and guarded while importing')

	// A created template that could not be refreshed/opened is preserved for recovery.
	assert.ok(view.includes('runTemplateImport'), 'the view must use the tested import flow')
	assert.ok(view.includes('importedTemplate'), 'the created template must be preserved')
	assert.ok(view.includes("t('runbook', 'Open imported template')"), 'there must be a safe way to open the created draft')
	assert.ok(view.includes('importWarning'), 'a non-fatal post-creation warning must be surfaced')
})

test('import error reasons map to clear messages without internal ids', async () => {
	globalThis._oc_l10n_registry_translations ??= {}
	globalThis._oc_l10n_registry_plural_functions ??= {}
	globalThis._oc_l10n_registry_translations.runbook = translations('en.json')

	const { apiErrorMessage } = await import('../../src/utils/apiError.ts')

	const reasons = [
		'invalid_import_format',
		'unsupported_import_schema_version',
		'invalid_import_document',
		'invalid_import_reference',
		'duplicate_import_reference',
		'invalid_import_assignee',
		'import_document_too_large',
		'import_too_many_sections',
		'import_too_many_steps',
		'import_too_many_dependencies',
		'import_too_many_conditions',
	]
	const pt = translations('pt_PT.json')
	const br = translations('pt_BR.json')
	for (const reason of reasons) {
		const message = apiErrorMessage({ response: { data: { reason } } })
		assert.notEqual(message, 'Something went wrong. Please try again.', `unmapped reason: ${reason}`)
		assert.doesNotMatch(message, /[0-9]/, `the message for ${reason} must not expose internal identifiers`)
		assert.ok(pt[message], `pt_PT must translate: ${message}`)
		assert.notEqual(pt[message], message, `pt_PT must not fall back to English for: ${message}`)
		assert.equal(br[message], pt[message], `pt_BR must mirror pt_PT for: ${message}`)
	}

	const permission = apiErrorMessage({ response: { data: { reason: 'template_creation_forbidden' } } })
	assert.equal(permission, 'You are not allowed to create templates.')
})
