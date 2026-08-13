import { computed } from 'vue'

/**
 * Transform bookings and rooms into FullCalendar-compatible events and resources.
 */
export function useCalendarEvents(bookingsRef, roomsRef) {
    const events = computed(() =>
        (bookingsRef.value || []).map((b) => ({
            id: b.uid,
            resourceId: b.roomId,
            title: b.summary || 'Unnamed',
            start: b.dtstart,
            end: b.dtend,
            // All-day bookings arrive as bare Y-m-d dates; telling FullCalendar
            // so keeps it from interpreting them in the local timezone and
            // shifting them into the next day (issue #27).
            allDay: b.allDay === true,
            extendedProps: {
                organizer: b.organizer,
                organizerName: b.organizerName,
                partstat: b.partstat,
                status: b.status,
                roomId: b.roomId,
                roomName: b.roomName,
            },
            backgroundColor: getColor(b.partstat),
            borderColor: getColor(b.partstat),
        }))
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
