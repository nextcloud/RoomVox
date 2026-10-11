/**
 * Simulate the Nextcloud Transifex extractor and fail if it would miss a string.
 *
 * Our own extractor (scripts/extract-en-json.js) is deliberately permissive —
 * it recognises every call form so the POT is complete — but the POT we commit
 * is NOT what reaches Transifex. The sync bot runs `translationtool.phar
 * create-pot-files`, which OVERWRITES our template with its own extraction,
 * pushes that, and deletes the file. Whatever its extractor cannot see does not
 * exist for translators, no matter how correct our own tooling is.
 *
 * Verified against nextcloud/docker-ci (translations/translationtool/src/
 * translationtool.php). Two passes over src/:
 *
 *   <template>  its own regexes, matching  t('appid', '…')  and
 *               n('appid', '…', '…', …)  literally
 *   <script>    handed to  xgettext --keyword=t:2 --keyword=n:2,3
 *
 * Both need the app id as the FIRST argument, and xgettext only knows the
 * keywords `t` and `n` — a helper named anything else is invisible to it.
 * Modelled on IntroVox's test, where a `trans()` wrapper hid 120 of 207
 * strings from Transifex for three months.
 *
 * The rules this pins, for any new translation call:
 *   - name the helper `t` (or `n`), never `trans`, `$t` or `this.t`
 *   - pass 'roomvox' explicitly at every call-site
 *   - no '|' in a msgid (validateTranslationFiles.sh rejects the resource)
 *
 * Run with: npm run test:js
 */

const { test, describe } = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')

const ROOT = path.resolve(__dirname, '..')
const APP_ID = 'roomvox'

// translationtool.php collapses whitespace in a template match with PHP's
// preg_replace('/\s+/', ' '). PHP's \s is ASCII-only, so a U+00A0 survives it;
// JavaScript's \s also matches NBSP and would rewrite "Saving …" to
// "Saving …", making strings that are present look missing. RoomVox puts a
// NBSP before every ellipsis (the Nextcloud punctuation guideline).
const collapsePhpWhitespace = (s) => s.replace(/[ \t\n\r\f\v]+/g, ' ')
const unescapeJs = (s) => s.replace(/\\'/g, "'").replace(/\\\\/g, '\\')
const { computeSourceStrings } = require(path.join(ROOT, 'scripts/extract-en-json.js'))

// Collect .vue/.js under src/, honouring .l10nignore the way the bot does.
const IGNORED = fs.readFileSync(path.join(ROOT, '.l10nignore'), 'utf8')
	.split('\n').map((l) => l.trim()).filter((l) => l && !l.startsWith('#'))

const isIgnored = (rel) => IGNORED.some((pat) => {
	const clean = pat.replace(/\/$/, '')
	return rel === clean || rel.startsWith(clean + '/') || rel.split('/').includes(clean)
})

function walk(dir, acc = []) {
	for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, e.name)
		const rel = path.relative(ROOT, full)
		if (isIgnored(rel)) continue
		if (e.isDirectory()) walk(full, acc)
		else if (/\.(vue|js)$/.test(e.name)) acc.push(full)
	}
	return acc
}

const STR = "'((?:[^'\\\\]|\\\\.)*)'"

/**
 * The bot's view of one file: which msgids its two passes would extract.
 *
 * The template regexes are translationtool.php's own, made non-greedy on the
 * string: the real `'(.+)'` merges two calls on one line into one match, which
 * xgettext's pass over the generated file still splits correctly. Matching each
 * call separately is the stricter reading — does every call carry the app id —
 * which is the rule that matters.
 *
 * The script pass approximates xgettext --keyword=t:2 --keyword=n:2,3 by
 * matching a call to a bare `t` or `n` whose first argument is the app id.
 */
function botSees(file) {
	const source = fs.readFileSync(file, 'utf8')
	const found = new Set()
	const isVue = file.endsWith('.vue')

	const templateMatch = source.match(/<template>([\s\S]+)<\/template>/)
	if (isVue && templateMatch) {
		const tRe = new RegExp(`\\Wt\\s*\\(\\s*'?([\\w.]+)'?,\\s*${STR}`, 'g')
		for (const m of templateMatch[1].matchAll(tRe)) {
			if (m[1] === APP_ID) found.add(collapsePhpWhitespace(unescapeJs(m[2])))
		}
		const nRe = new RegExp(`\\Wn\\s*\\(\\s*'?([\\w.]+)'?,\\s*${STR}\\s*,\\s*${STR}`, 'g')
		for (const m of templateMatch[1].matchAll(nRe)) {
			if (m[1] === APP_ID) found.add(collapsePhpWhitespace(unescapeJs(m[2])))
		}
	}

	const scripts = isVue
		? [...source.matchAll(/<script[^>]*>([\s\S]+?)<\/script>/g)].map((m) => m[1])
		: [source]
	for (const body of scripts) {
		const re = new RegExp(`(?<![\\w$.])[tn]\\(\\s*'${APP_ID}'\\s*,\\s*${STR}`, 'g')
		for (const m of body.matchAll(re)) {
			found.add(unescapeJs(m[1]))
		}
	}
	return found
}

describe('Transifex bot visibility', () => {
	const files = walk(path.join(ROOT, 'src'))
	const visible = new Set()
	for (const f of files) for (const s of botSees(f)) visible.add(s)

	// Our own extractor also scans lib/, which the bot reads with plain
	// xgettext --keyword=t --keyword=n:1,2 on PHP. Those forms are
	// unambiguous, so this test only has to police the JS/Vue side.
	const src = computeSourceStrings()
	const allMsgids = [...src.singulars, ...src.plurals.map(([s]) => s)]
	const frontend = new Set()
	for (const f of files) {
		const body = fs.readFileSync(f, 'utf8')
		for (const s of allMsgids) {
			if (body.includes(`'${s.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`)) frontend.add(s)
		}
	}

	test('the simulation finds something', () => {
		// Guard against a broken simulation passing everything vacuously.
		assert.ok(visible.size > 300, `bot simulation only found ${visible.size} strings`)
	})

	test('every frontend string is visible to the bot extractor', () => {
		const invisible = [...frontend].filter((s) => !visible.has(s)).sort()
		assert.deepEqual(invisible, [], invisible.length
			? `${invisible.length} string(s) the Transifex bot cannot see, so translators never get them:\n`
				+ invisible.slice(0, 20).map((s) => `      ${JSON.stringify(s.slice(0, 80))}`).join('\n')
				+ '\n\n  Fix: name the helper t/n and pass the app id first —'
				+ "\n  t('roomvox', 'text'), not trans('text') or this.t('text')."
			: '')
	})

	test('no translation helper is named anything but t or n', () => {
		// The rule above, enforced at the source rather than by its symptom,
		// so a new wrapper is caught the moment it appears.
		const offenders = []
		for (const f of files) {
			const body = fs.readFileSync(f, 'utf8')
			for (const m of body.matchAll(/\b(?:const|let|var)\s+(\w+)\s*=\s*\([^)]*\)\s*=>\s*(?:\{\s*return\s+)?translate(?:Plural)?\(/g)) {
				if (m[1] !== 't' && m[1] !== 'n') {
					offenders.push(`${path.relative(ROOT, f)}: const ${m[1]} = (…) => translate(…)`)
				}
			}
			// translate('roomvox', '…') works at runtime and is invisible to
			// xgettext, which only knows t and n. Wrappers pass variables, so a
			// string literal here is always a call-site that should use t().
			for (const m of body.matchAll(/(?<![\w$.])translate(?:Plural)?\(\s*'/g)) {
				const line = body.slice(0, m.index).split('\n').length
				offenders.push(`${path.relative(ROOT, f)}:${line}: translate('…') — use t('${APP_ID}', '…')`)
			}
			for (const m of body.matchAll(/import\s*\{([^}]*)\}\s*from\s*'@nextcloud\/l10n'/g)) {
				for (const alias of m[1].matchAll(/\btranslate(?:Plural)?\s+as\s+(\w+)/g)) {
					if (alias[1] !== 't' && alias[1] !== 'n') {
						offenders.push(`${path.relative(ROOT, f)}: import { … as ${alias[1]} }`)
					}
				}
			}
		}
		assert.deepEqual(offenders, [], offenders.length
			? 'translation helpers xgettext cannot see (it only knows the keywords t and n):\n'
				+ offenders.map((o) => `      ${o}`).join('\n')
			: '')
	})

	test('no call-site omits the app id', () => {
		// t('Some text') resolves fine at runtime through a wrapper, and is
		// invisible to both extractor passes. The first argument must be the
		// app id, so a literal that contains a space is a missing app id.
		const offenders = []
		for (const f of files) {
			const body = fs.readFileSync(f, 'utf8')
			for (const m of body.matchAll(/(?<![\w$.'])\$?[tn]\(\s*'((?:[^'\\]|\\.)*)'\s*[,)]/g)) {
				if (m[1] !== APP_ID && /\s/.test(m[1])) {
					offenders.push(`${path.relative(ROOT, f)}: t('${m[1].slice(0, 50)}…')`)
				}
			}
		}
		assert.deepEqual(offenders, [], offenders.length
			? `${offenders.length} call-site(s) without the app id:\n`
				+ offenders.slice(0, 15).map((o) => `      ${o}`).join('\n')
			: '')
	})

	test('no msgid contains a pipe character', () => {
		// docker-ci's validateTranslationFiles.sh fails the whole resource on a
		// '|' in any source string; put commands in a placeholder or markup.
		const piped = allMsgids.filter((s) => s.includes('|'))
		assert.deepEqual(piped, [], `msgids with '|':\n${piped.map((s) => `      ${JSON.stringify(s)}`).join('\n')}`)
	})
})
