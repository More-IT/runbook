/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Cross-runtime contract test (issue #33).
 *
 * The same fixture bytes that the PHP import contract test consumes are read
 * here through the frontend parser and formatter. The two runtimes share the
 * fixture rather than a single process: this Node test proves the browser can
 * read the fixture and re-emit it byte-for-byte in the download format, and the
 * PHP test proves the service imports those same bytes.
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import { formatTemplateExport } from '../../src/utils/templateExport.ts'
import { parseTemplateImport } from '../../src/utils/templateImport.ts'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const FIXTURE = join(root, 'tests', 'fixtures', 'template-export-v1.json')

test('the shared export fixture is byte-identical to the frontend download format', () => {
	const text = readFileSync(FIXTURE, 'utf8')
	const parsed = parseTemplateImport(text)

	assert.equal(parsed.summary.title, 'Contoso Release')
	assert.equal(parsed.summary.sections, 5)
	assert.equal(parsed.summary.steps, 8)

	// The fixture has no trailing newline, so this is a genuine byte comparison
	// against exactly what downloadJson() writes (formatTemplateExport).
	assert.equal(formatTemplateExport(parsed.document), text)

	// The compact body the browser would POST re-parses to the same document.
	const compact = JSON.stringify(parsed.document)
	assert.deepEqual(parseTemplateImport(compact).document, parsed.document)
})

test('the fixture exercises flow references, typed conditions and a zero-step section', () => {
	const doc = JSON.parse(readFileSync(FIXTURE, 'utf8'))

	const types = doc.steps.map((step) => step.type).sort()
	assert.deepEqual(types, ['CHECK', 'CONFIRMATION', 'DATE', 'FILE', 'NUMBER', 'SELECT', 'TEXT', 'USER'])

	const zeroStep = doc.sections.find((section) => section.ref === 's2')
	assert.ok(zeroStep, 'the zero-step section is present')
	assert.ok(doc.steps.every((step) => step.sectionRef !== 's2'), 'the zero-step section has no steps')

	const final = doc.sections.find((section) => section.ref === 's5')
	assert.deepEqual(final.dependsOn, ['s3', 's4'], 'multiple dependencies are preserved')
	assert.deepEqual(final.conditions, [
		{ stepRef: 't1', operator: 'equals', value: 'prod' },
		{ stepRef: 't2', operator: 'greater_than', value: 10.5 },
	], 'multiple AND conditions keep their operators and typed values')
	assert.equal(typeof final.conditions[1].value, 'number')
})
