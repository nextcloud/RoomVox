/**
 * Plurals are found at runtime.
 *
 * @nextcloud/l10n's translatePlural() (and PHP's IL10N::n()) look a plural up
 * under the key "_<singular>_::_<plural>_", which is how the Transifex bot
 * writes l10n/<lang>.{js,json}. RoomVox's own tooling stored hand-made plurals
 * under the singular alone, so they were never found and showed in English.
 * This loads the generated l10n/nl.js the way Nextcloud does, through
 * OC.L10N.register, which hands the bundle to @nextcloud/l10n's register().
 *
 * Run with: npm run test:js
 */

import { test, describe, after } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import { createRequire } from 'node:module'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { register, unregister, translatePlural } from '@nextcloud/l10n'

const ROOT = fileURLToPath(new URL('..', import.meta.url))
const require = createRequire(import.meta.url)
const { computeSourceStrings, pluralKey } = require(join(ROOT, 'scripts/extract-en-json.js'))

/** Evaluate a generated l10n/<lang>.js against a minimal OC.L10N */
function loadBundle(lang) {
	const OC = { L10N: { register: (app, bundle) => register(app, bundle) } }
	new Function('OC', readFileSync(join(ROOT, 'l10n', `${lang}.js`), 'utf8'))(OC)
}

describe('plural translations at runtime', () => {
	after(() => unregister('roomvox'))

	test('the generated Dutch bundle answers n() for both forms', () => {
		loadBundle('nl')
		assert.equal(translatePlural('roomvox', '%n room found', '%n rooms found', 2), '2 ruimtes gevonden')
		assert.equal(translatePlural('roomvox', '%n room found', '%n rooms found', 1), '1 ruimte gevonden')
	})

	test('a plural keyed by its singular alone is not found (the old format)', () => {
		unregister('roomvox')
		register('roomvox', { '%n room found': ['%n ruimte gevonden', '%n ruimtes gevonden'] })
		assert.equal(translatePlural('roomvox', '%n room found', '%n rooms found', 2), '2 rooms found')
	})

	test('every plural in l10n/*.json uses the runtime key of a source plural', () => {
		const plurals = new Map(computeSourceStrings().plurals)
		const wrong = []
		for (const file of readdirSync(join(ROOT, 'l10n')).filter((f) => f.endsWith('.json') && !f.startsWith('.') && f !== 'en.json')) {
			const { translations } = JSON.parse(readFileSync(join(ROOT, 'l10n', file), 'utf8'))
			for (const [key, value] of Object.entries(translations)) {
				if (!Array.isArray(value)) continue
				const m = key.match(/^_(.*)_::_(.*)_$/s)
				if (!m || !plurals.has(m[1]) || pluralKey(m[1], plurals.get(m[1])) !== key) wrong.push(`${file}: ${key}`)
			}
		}
		assert.deepEqual(wrong, [])
	})
})
