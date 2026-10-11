/**
 * Translation helpers for RoomVox.
 *
 * t and n are @nextcloud/l10n's translate and translatePlural, unchanged:
 * the call sites keep the form the Transifex extractor reads (t or n with the
 * app id as the first argument), and the library default stays on. That default
 * HTML-escapes placeholder values and runs the result through DOMPurify, which
 * is what a translation needs where it ends up in HTML: v-html, innerHTML, an
 * `html:` option, and every toast. @nextcloud/dialogs puts a toast's message
 * through innerHTML even without isHTML (it reads innerText back from a
 * detached element, where <img onerror=…> still fires), so showError,
 * showSuccess and the other show* calls always take the escaped default; the
 * entities decode to the value as typed. Text that reaches a toast without
 * going through t() is escaped with src/utils/escapeHtml.js.
 *
 * Text sites are Vue {{ }} and attribute bindings (labels, placeholders,
 * aria-label, title). Vue escapes those itself, so an escaped value would show
 * as "Tom &amp; Jerry". Such a call passes asText after the placeholders: as the
 * fourth argument of t, the sixth of n (see RoomList.vue and App.vue).
 *
 * sanitize goes off with escape: DOMPurify returns HTML, so a value with "<"
 * would come back with "&" re-escaped and the tag removed.
 *
 * Forgetting asText at a text site shows an entity; using it at an HTML site
 * injects markup. So asText is opt-in, never the default.
 */
import { translate, translatePlural } from '@nextcloud/l10n'

export const t = translate
export const n = translatePlural

/** Options for a translation rendered as text, never as HTML */
export const asText = Object.freeze({ escape: false, sanitize: false })
