/**
 * Escape a value for a place that parses its input as HTML.
 *
 * Two such places exist in RoomVox: toasts from @nextcloud/dialogs, which set
 * a plain-text message through innerHTML before reading it back as text, and
 * FullCalendar's `html:` content. Room names, booking titles and server error
 * texts reach both, and any of them can carry markup.
 *
 * @param {*} value The text to escape; null and undefined become ''.
 * @return {string}
 */
export function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
}
