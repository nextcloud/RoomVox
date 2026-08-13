import { computed } from 'vue'

/**
 * Visible span of the resource timeline — keep in sync with ResourceCalendar.
 * slotMaxTime is the *exclusive* end of the grid, so the last drawable moment
 * is one minute before it.
 */
const SLOT_MIN_TIME = '08:00:00'
const SLOT_LAST_MOMENT = '18:59:59'

/**
 * Bounds an all-day booking to the hours the resource timeline actually shows.
 *
 * An all-day VEVENT runs from midnight to midnight the next day. The timeline
 * only renders 08:00–19:00, so FullCalendar draws such an event from the left
 * edge straight through into the following day's slots — it looks like a
 * two-day booking (issue #27). Pinning it to the visible window makes it fill
 * exactly its own day.
 *
 * The end stops just short of slotMaxTime on purpose: an event ending exactly
 * on the grid boundary is pushed into the next day by nextDayThreshold.
 */
function allDaySpan(dtstart, dtend) {
    const startDay = String(dtstart).slice(0, 10)
    // DTEND of an all-day event is exclusive: 12 Aug → 13 Aug means "the 12th".
    const endExclusive = new Date(`${String(dtend).slice(0, 10)}T00:00:00`)
    endExclusive.setDate(endExclusive.getDate() - 1)
    const lastDay = [
        endExclusive.getFullYear(),
        String(endExclusive.getMonth() + 1).padStart(2, '0'),
        String(endExclusive.getDate()).padStart(2, '0'),
    ].join('-')

    return {
        start: `${startDay}T${SLOT_MIN_TIME}`,
        end: `${lastDay < startDay ? startDay : lastDay}T${SLOT_LAST_MOMENT}`,
    }
}

/**
 * Transform bookings and rooms into FullCalendar-compatible events and resources.
 *
 * @param {object} bookingsRef reactive list of bookings
 * @param {object} roomsRef    reactive list of rooms
 * @param {object} [viewRef]   reactive current view name; the resource timeline
 *                             needs all-day events bounded to its visible hours
 */
export function useCalendarEvents(bookingsRef, roomsRef, viewRef) {
    const events = computed(() =>
        (bookingsRef.value || []).map((b) => {
            const isAllDay = b.allDay === true
            const isTimeline = String(viewRef?.value || '').startsWith('resourceTimeline')
            // Only the timeline needs the clamp; the month/day grids render a
            // real all-day event correctly on their own.
            const span = isAllDay && isTimeline
                ? allDaySpan(b.dtstart, b.dtend)
                : { start: b.dtstart, end: b.dtend }

            return {
                id: b.uid,
                resourceId: b.roomId,
                title: b.summary || 'Unnamed',
                start: span.start,
                end: span.end,
                // All-day bookings arrive as bare Y-m-d dates; telling
                // FullCalendar so keeps it from interpreting them in the local
                // timezone and shifting them into the next day (issue #27).
                allDay: isAllDay && !isTimeline,
                extendedProps: {
                    organizer: b.organizer,
                    organizerName: b.organizerName,
                    partstat: b.partstat,
                    status: b.status,
                    roomId: b.roomId,
                    roomName: b.roomName,
                    allDay: isAllDay,
                },
                backgroundColor: getColor(b.partstat),
                borderColor: getColor(b.partstat),
            }
        })
    )

    const resources = computed(() =>
        (roomsRef.value || []).map((r) => ({
            id: r.id,
            title: r.name,
        }))
    )

    return { events, resources }
}

function getColor(partstat) {
    switch (partstat) {
        case 'ACCEPTED':
            return 'rgba(70, 186, 97, 0.9)'
        case 'TENTATIVE':
            return 'rgba(255, 193, 7, 0.9)'
        case 'DECLINED':
            return 'var(--color-background-dark)'
        default:
            return 'var(--color-primary-element)'
    }
}
