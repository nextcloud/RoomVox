#!/usr/bin/env node
/**
 * Regenerate l10n/<lang>.js from l10n/<lang>.json.
 *
 * Nextcloud loads the .js bundle at runtime and the .json server-side, so the
 * two must agree. The Transifex sync bot writes both, but while translations
 * are still maintained in-repo a source-string rename (issue #24) only lands
 * in the .json — leaving the .js holding dead keys and the UI showing
 * untranslated text. This script derives the .js from the .json so they cannot
 * drift apart.
 *
 * Usage: node scripts/generate-l10n-js.js [lang …]     (default: all .json found)
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const L10N = path.join(ROOT, 'l10n');

// Nextcloud ships these per language; keep whatever the existing file used so
// we don't silently change plural handling for a language.
const DEFAULT_PLURAL = 'nplurals=2; plural=(n != 1);';

function existingPluralForm(jsPath) {
    if (!fs.existsSync(jsPath)) return null;
    const m = fs.readFileSync(jsPath, 'utf8').match(/"(nplurals=[^"]+)"\s*\)?;?\s*$/m);
    return m ? m[1] : null;
}

const langs = process.argv.slice(2).length
    ? process.argv.slice(2)
    : fs.readdirSync(L10N)
        .filter(f => f.endsWith('.json') && !f.startsWith('.') && f !== 'en.json')
        .map(f => f.replace(/\.json$/, ''));

for (const lang of langs) {
    const jsonPath = path.join(L10N, `${lang}.json`);
    const jsPath = path.join(L10N, `${lang}.js`);
    if (!fs.existsSync(jsonPath)) {
        console.warn(`  ${lang}: no ${lang}.json — skipped`);
        continue;
    }

    const translations = JSON.parse(fs.readFileSync(jsonPath, 'utf8')).translations || {};
    const plural = existingPluralForm(jsPath) || DEFAULT_PLURAL;

    const lines = Object.entries(translations).map(([key, value]) => {
        const v = Array.isArray(value)
            ? '[' + value.map(s => JSON.stringify(s)).join(',') + ']'
            : JSON.stringify(value);
        return `        ${JSON.stringify(key)} : ${v},`;
    });

    const out = 'OC.L10N.register(\n'
        + '    "roomvox",\n'
        + '    {\n'
        + lines.join('\n') + '\n'
        + '    },\n'
        + `    "${plural}"\n`
        + ');\n';

    fs.writeFileSync(jsPath, out, 'utf8');
    console.log(`  ${lang}: ${Object.keys(translations).length} strings → l10n/${lang}.js`);
}
