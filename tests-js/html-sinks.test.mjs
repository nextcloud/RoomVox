// Text that reaches an HTML sink must be escaped once: toasts from
// @nextcloud/dialogs 7.3 set a plain-text message through innerHTML on a
// detached element (an <img onerror> there still runs), and FullCalendar's
// `html:` content is inserted as markup.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { translate } from '@nextcloud/l10n'
import { escapeHtml } from '../src/utils/escapeHtml.js'

const PAYLOAD = '<img src=x onerror=alert(1)> & "Tom\'s"'

function sources(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name)
        if (statSync(path).isDirectory()) return sources(path)
        return /\.(vue|js)$/.test(name) ? [path] : []
    })
}

const files = sources('src').map((path) => ({ path, text: readFileSync(path, 'utf8') }))

test('escapeHtml leaves no markup and escapes every special character', () => {
    assert.equal(escapeHtml(PAYLOAD), '&lt;img src=x onerror=alert(1)&gt; &amp; &quot;Tom&#39;s&quot;')
    assert.equal(escapeHtml(null), '')
    assert.equal(escapeHtml(undefined), '')
})

test('a translation with the default options escapes its placeholder for a toast', () => {
    const message = translate('roomvox', 'Room "{room}" already uses this email address', { room: PAYLOAD })
    assert.ok(!message.includes('<img'), message)
})

test('no toast gets a translation with escaping switched off', () => {
    for (const { path, text } of files) {
        const calls = text.match(/show(Error|Success|Warning|Info|Message|Undo)\((?:[^()]|\([^()]*(?:\([^()]*\))*[^()]*\))*\)/gs) ?? []
        for (const call of calls) {
            assert.ok(!/asText|escape:\s*false/.test(call), `${path}: ${call}`)
        }
    }
})

test('server error text reaches a toast only through escapeHtml', () => {
    for (const { path, text } of files) {
        text.split('\n').forEach((line, i) => {
            if (/show(Error|Success|Warning|Info)\(/.test(line) && /data\?\.(error|message)|\.message\b/.test(line)) {
                assert.ok(line.includes('escapeHtml('), `${path}:${i + 1}: ${line.trim()}`)
            }
        })
    }
})

test('FullCalendar html content interpolates only escaped values', () => {
    const { text } = files.find(({ path }) => path.endsWith('ResourceCalendar.vue'))
    const templates = text.match(/html:\s*`[^`]*`/g) ?? []
    assert.ok(templates.length >= 2)
    for (const template of templates) {
        for (const [, expr] of template.matchAll(/\$\{([^}]*)\}/g)) {
            assert.ok(/^escapeHtml\(.*\)$/.test(expr.trim()) || expr.trim() === 'statusClass', expr)
        }
    }
})
