/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Localization completeness regression (issue #33).
 *
 * Scans the frontend sources for static translation keys and asserts every one
 * exists in the English and both Portuguese catalogs, that PT-BR mirrors PT-PT
 * exactly, and that no catalog value leaks a literal HTML entity or a raw
 * translation key. Dynamic keys (variables) cannot be checked statically and
 * are covered by the purpose-built tests elsewhere.
 */

import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

/**
 * @param {string} file Relative path under l10n/.
 */
function translations(file) {
	return JSON.parse(readFileSync(join(root, 'l10n', file), 'utf8')).translations
}

function sourceFiles() {
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

	return files
}

/**
 * @param {string} text Source text.
 */
function staticKeys(text) {
	const keys = new Set()
	const pattern = /\bt\(\s*'runbook'\s*,\s*'((?:[^'\\]|\\.)*)'/g
	for (const match of text.matchAll(pattern)) {
		keys.add(match[1].replace(/\\'/g, "'"))
	}

	return keys
}

test('every static t() key used in the UI exists in all catalogs', () => {
	const en = translations('en.json')
	const pt = translations('pt_PT.json')
	const br = translations('pt_BR.json')

	const missing = []
	let checked = 0
	for (const file of sourceFiles()) {
		for (const key of staticKeys(readFileSync(file, 'utf8'))) {
			checked++
			if (!(key in en) || !(key in pt) || !(key in br)) {
				missing.push(`${file}: ${key}`)
				continue
			}
			assert.equal(br[key], pt[key], `pt_BR must mirror pt_PT for: ${key}`)
			assert.notEqual(br[key], '', `empty translation for: ${key}`)
		}
	}

	assert.ok(checked > 0, 'the scan must find translation keys')
	assert.deepEqual(missing, [], 'every UI key must be present in all catalogs')
})

test('catalogs contain no literal translation keys or HTML entities', () => {
	const en = translations('en.json')
	const pt = translations('pt_PT.json')
	const entities = ['&quot;', '&amp;', '&#39;', '&apos;', '&lt;', '&gt;', '&#x27;', '&#x2F;']

	for (const [locale, catalog] of Object.entries({ en, pt, br: translations('pt_BR.json') })) {
		assert.deepEqual(Object.keys(catalog), Object.keys(en), `${locale} key order must match en`)
		for (const [key, value] of Object.entries(catalog)) {
			for (const entity of entities) {
				assert.ok(!value.includes(entity), `${locale} value for "${key}" contains ${entity}`)
			}
			assert.ok(value.trim() !== '', `${locale} value for "${key}" must not be empty`)
		}
		assert.ok(!Object.keys(catalog).some((key) => key.startsWith('runbook.')), `${locale} must not contain scoped keys`)
	}
})
