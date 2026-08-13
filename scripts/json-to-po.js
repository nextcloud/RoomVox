#!/usr/bin/env node
/**
 * Seed translationfiles/<lang>/roomvox.po from the hand-maintained
 * l10n/<lang>.json bundles.
 *
 * RoomVox was translated by hand long before it moved to the Transifex PO
 * workflow (issue #24). Those German, French and Dutch translations are real
 * work; if we handed Transifex an empty resource every language would restart
 * from zero. This script carries them across once, as the initial PO content.
 *
 * Only strings that still exist in the current POT are emitted — stale entries
 * for strings that no longer occur in the source are dropped rather than
 * shipped as obsolete msgids.
 *
 * Run once during onboarding:
 *   npm run pot && node scripts/json-to-po.js
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const POT = path.join(ROOT, 'translationfiles', 'templates', 'roomvox.pot');
const VERSION = JSON.parse(fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8')).version;

const LANGS = process.argv.slice(2);
if (LANGS.length === 0) {
    console.error('usage: node scripts/json-to-po.js <lang> [<lang> …]');
    process.exit(1);
}

function escapePo(s) {
    return s
        .replace(/\\/g, '\\\\')
        .replace(/"/g, '\\"')
        .replace(/\n/g, '\\n')
        .replace(/\t/g, '\\t');
}

/** Read the msgids (and plural pairs) the POT declares. */
function readPot() {
    const text = fs.readFileSync(POT, 'utf8');
    const singular = new Set();
    const plural = new Map(); // singular -> plural

    // Unescape a PO string literal body.
    const unescape = (s) => s
        .replace(/\\n/g, '\n')
        .replace(/\\t/g, '\t')
        .replace(/\\"/g, '"')
        .replace(/\\\\/g, '\\');

    const blocks = text.split(/\n\n+/);
    for (const block of blocks) {
        const idMatch = block.match(/^msgid "((?:[^"\\]|\\.)*)"/m);
        if (!idMatch) continue;
        const id = unescape(idMatch[1]);
        if (id === '') continue; // header

        const plMatch = block.match(/^msgid_plural "((?:[^"\\]|\\.)*)"/m);
        if (plMatch) {
            plural.set(id, unescape(plMatch[1]));
        } else {
            singular.add(id);
        }
    }
    return { singular, plural };
}

const { singular, plural } = readPot();

for (const lang of LANGS) {
    const jsonPath = path.join(ROOT, 'l10n', `${lang}.json`);
    if (!fs.existsSync(jsonPath)) {
        console.warn(`  ${lang}: no l10n/${lang}.json — skipped`);
        continue;
    }

    const translations = JSON.parse(fs.readFileSync(jsonPath, 'utf8')).translations || {};

    const header = `# RoomVox ${lang} translation.
# Carried over from the pre-Transifex l10n/${lang}.json bundle.
#
msgid ""
msgstr ""
"Project-Id-Version: RoomVox ${VERSION}\\n"
"Report-Msgid-Bugs-To: translations@nextcloud.com\\n"
"PO-Revision-Date: ${new Date().toISOString().slice(0, 10)} 00:00+0000\\n"
"Last-Translator: RoomVox <translations@nextcloud.com>\\n"
"Language-Team: ${lang}\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Language: ${lang}\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"
`;

    const entries = [];
    let translated = 0;

    for (const msgid of [...singular].sort((a, b) => a.localeCompare(b))) {
        const value = translations[msgid];
        const str = typeof value === 'string' ? value : '';
        if (str) translated++;
        entries.push(`msgid "${escapePo(msgid)}"\nmsgstr "${escapePo(str)}"`);
    }

    for (const [msgid, msgidPlural] of [...plural.entries()].sort((a, b) => a[0].localeCompare(b[0]))) {
        const value = translations[msgid];
        const forms = Array.isArray(value) ? value : ['', ''];
        if (forms[0]) translated++;
        entries.push(
            `msgid "${escapePo(msgid)}"\n` +
            `msgid_plural "${escapePo(msgidPlural)}"\n` +
            `msgstr[0] "${escapePo(forms[0] || '')}"\n` +
            `msgstr[1] "${escapePo(forms[1] || forms[0] || '')}"`
        );
    }

    const outDir = path.join(ROOT, 'translationfiles', lang);
    fs.mkdirSync(outDir, { recursive: true });
    const outFile = path.join(outDir, 'roomvox.po');
    fs.writeFileSync(outFile, header + '\n' + entries.join('\n\n') + '\n', 'utf8');

    const total = singular.size + plural.size;
    const pct = Math.round((translated / total) * 100);
    console.log(`  ${lang}: ${translated}/${total} translated (${pct}%) → translationfiles/${lang}/roomvox.po`);
}
