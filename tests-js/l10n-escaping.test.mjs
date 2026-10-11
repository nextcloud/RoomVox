/**
 * Placeholder escaping: text sites show values literally, HTML sites stay safe.
 *
 * @nextcloud/l10n HTML-escapes placeholder values (and runs DOMPurify) by
 * default. That is right where a translation is inserted as HTML, and wrong
 * where Vue or the browser renders it as text: there "Tom & Jerry" showed as
 * "Tom &amp; Jerry". src/l10n.js keeps the library default and text sites pass
 * asText. These tests render through Vue's real compiler and renderer.
 *
 * Run with: npm run test:js
 */

import { test, describe } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import { createRequire } from 'node:module'
import { join, relative } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = fileURLToPath(new URL('..', import.meta.url))
const require = createRequire(import.meta.url)

// src/l10n.js is ESM in a CommonJS package, as webpack expects; load the real
// file with its one bare import pointed at the installed library.
const library = pathToFileURL(require.resolve('@nextcloud/l10n')).href
const source = readFileSync(join(ROOT, 'src/l10n.js'), 'utf8').replace("'@nextcloud/l10n'", JSON.stringify(library))
const { t, n, asText } = await import('data:text/javascript,' + encodeURIComponent(source))
const { createSSRApp } = await import('vue')
const { renderToString } = await import('vue/server-renderer')

const VALUE = 'Tom & Jerry\'s <b>"x"</b>'
// What a browser shows for the text inside an element of rendered HTML
const ENTITIES = { '&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#39;': '\'', '&#039;': '\'' }
const shown = (html) => html.replace(/<!--.*?-->/g, '').replace(/&(amp|lt|gt|quot|#39|#039);/g, (e) => ENTITIES[e])

const render = (template) => renderToString(createSSRApp({ template, setup: () => ({ t, n, asText, value: VALUE }) }))

describe('placeholder escaping', () => {
	test('a value with & \' " < > renders literally at a text site', async () => {
		const html = await render('<p>{{ t(\'roomvox\', \'Room {name}\', { name: value }, asText) }}</p>')
		// Escaped exactly once, by Vue, so the browser shows the value as typed
		assert.equal(html, '<p>Room Tom &amp; Jerry&#39;s &lt;b&gt;&quot;x&quot;&lt;/b&gt;</p>')
		assert.equal(shown(html), `<p>Room ${VALUE}</p>`)
	})

	test('a value renders literally in an attribute and in a plural', async () => {
		const html = await render('<button :aria-label="t(\'roomvox\', \'Actions for room {name}\', { name: value }, asText)">{{ n(\'roomvox\', \'%n room in {name}\', \'%n rooms in {name}\', 2, { name: value }, asText) }}</button>')
		assert.ok(html.includes('aria-label="Actions for room Tom &amp; Jerry&#39;s &lt;b&gt;&quot;x&quot;&lt;/b&gt;"'), html)
		assert.ok(html.includes('>2 rooms in Tom &amp; Jerry&#39;s &lt;b&gt;&quot;x&quot;&lt;/b&gt;<'), html)
	})

	test('without asText a text site shows entities (the bug this fixes)', async () => {
		const html = await render('<p>{{ t(\'roomvox\', \'Room {name}\', { name: value }) }}</p>')
		assert.ok(html.includes('&amp;amp;'), html)
	})

	test('an HTML site keeps escaping: the value cannot inject markup', async () => {
		const html = await render('<div v-html="t(\'roomvox\', \'Room {name}\', { name: value })"></div>')
		// v-html inserts the string as markup: no element came from the value,
		// and what the browser shows is the value as typed
		assert.ok(!html.includes('<b>'), html)
		assert.equal(shown(html), `<div>Room ${VALUE}</div>`)
	})
})

describe('toasts', () => {
	test('a toast message keeps the default escaping and reads as typed', () => {
		// @nextcloud/dialogs sets the message as innerHTML and shows its
		// innerText: the default output contains no tag, and decoding the
		// entities once gives the value back.
		const message = t('roomvox', 'Room {name}', { name: VALUE })
		assert.ok(!message.includes('<'), message)
		assert.equal(shown(message), `Room ${VALUE}`)
	})
})

describe('escaping rules in src/', () => {
	const files = []
	const walk = (dir) => {
		for (const e of readdirSync(dir, { withFileTypes: true })) {
			const full = join(dir, e.name)
			if (e.isDirectory()) walk(full)
			else if (/\.(vue|js)$/.test(e.name)) files.push(full)
		}
	}
	walk(join(ROOT, 'src'))
	const sources = files.map((file) => ({ file: relative(ROOT, file), body: readFileSync(file, 'utf8') }))
	const lineOf = (body, index) => body.slice(0, index).split('\n').length

	/** [start, end] of the call whose opening parenthesis is at `open` */
	const spanAt = (body, open) => {
		let depth = 0
		for (let i = open; i < body.length; i++) {
			if (body[i] === '(') depth++
			else if (body[i] === ')' && --depth === 0) return [open, i]
		}
		return [open, body.length]
	}

	// Sinks that parse their input as HTML. @nextcloud/dialogs puts every
	// toast through innerHTML, isHTML or not.
	const SINK = /\b(show(?:Error|Success|Warning|Info|Message|Undo)|escapeHtml)\(/g
	const sinkSpans = (body) => [...body.matchAll(SINK)].map((m) => ({ name: m[1], span: spanAt(body, m.index + m[0].length - 1) }))

	test('no toast and no HTML sink gets an asText translation', () => {
		const offenders = []
		for (const { file, body } of sources) {
			// Variables that hold an asText translation
			const tainted = [...body.matchAll(/\b(\w+)\s*=\s*(?!["'=>])[^;\n]*\basText\b/g)].map((m) => m[1])
			for (const { name, span } of sinkSpans(body)) {
				const args = body.slice(span[0], span[1] + 1)
				const viaVariable = tainted.find((v) => new RegExp(`\\b${v}\\b`).test(args))
				if (/\basText\b/.test(args) || viaVariable) {
					offenders.push(`${file}:${lineOf(body, span[0])}: ${name}(… ${viaVariable ?? 'asText'} …)`)
				}
			}
			body.split('\n').forEach((line, i) => {
				if (/v-html|innerHTML|\bhtml:/.test(line) && /\basText\b/.test(line)) offenders.push(`${file}:${i + 1}`)
			})
		}
		assert.deepEqual(offenders, [], `asText reaches an HTML sink:\n${offenders.join('\n')}`)
	})

	test('every other t()/n() with placeholders passes asText', () => {
		// Outside the sinks above, RoomVox renders translations only as text
		// ({{ }} and attribute bindings), so each call with values passes
		// asText. A new HTML site belongs in the sink list, not here.
		const missing = []
		const STR = '\'(?:[^\'\\\\]|\\\\.)*\''
		const call = new RegExp(`(?<![\\w$.])(t|n)\\(\\s*'roomvox'\\s*,\\s*${STR}(?:\\s*,\\s*${STR}\\s*,[^,{]+)?\\s*,\\s*\\{`, 'g')
		for (const { file, body } of sources) {
			const sinks = sinkSpans(body).map((s) => s.span)
			for (const m of body.matchAll(call)) {
				if (sinks.some(([a, b]) => m.index > a && m.index < b)) continue
				const [a, b] = spanAt(body, m.index + m[0].indexOf('('))
				if (!/,\s*asText\s*\)$/.test(body.slice(m.index, b + 1))) missing.push(`${file}:${lineOf(body, a)}`)
			}
		}
		assert.deepEqual(missing, [], `text sites without asText:\n${missing.join('\n')}`)
	})
})
