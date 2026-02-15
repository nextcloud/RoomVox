<template>
    <div class="resource-calendar">
        <div class="calendar-toolbar">
            <div class="toolbar-left">
                <NcButton type="tertiary" @click="navigateDate(-1)">
                    <template #icon>
                        <ChevronLeft :size="20" />
                    </template>
                </NcButton>
                <span class="date-title">{{ dateTitle }}</span>
                <NcButton type="tertiary" @click="navigateDate(1)">
                    <template #icon>
                        <ChevronRight :size="20" />
                    </template>
                </NcButton>
                <NcButton type="tertiary" @click="goToToday">{{ $t('Today') }}</NcButton>
            </div>
            <div class="toolbar-right">
                <div class="view-selector">
                    <NcButton
                        :type="currentView === 'resourceTimelineDay' ? 'primary' : 'tertiary'"
                        @click="changeView('resourceTimelineDay')">
                        {{ $t('Day') }}
                    </NcButton>
                    <NcButton
                        :type="currentView === 'resourceTimelineWeek' ? 'primary' : 'tertiary'"
                        @click="changeView('resourceTimelineWeek')">
                        {{ $t('Week') }}
                    </NcButton>
                    <NcButton
                        :type="currentView === 'dayGridMonth' ? 'primary' : 'tertiary'"
                        @click="changeView('dayGridMonth')">
                        {{ $t('Month') }}
                    </NcButton>
                </div>
            </div>
        </div>

        <FullCalendar
            ref="calendarRef"
            :options="calendarOptions"
            class="fullcalendar-container" />

        <!-- Create Booking Modal -->
        <CreateBookingModal
            v-if="createModal.show"
            :room-id="createModal.roomId"
            :start="createModal.start"
            :end="createModal.end"
            :rooms="rooms"
            @close="closeCreateModal"
            @created="handleBookingCreated" />

        <!-- Event Details Modal -->
        <EventDetailsModal
            v-if="detailsModal.show"
            :booking="detailsModal.booking"
            @close="closeDetailsModal"
            @updated="handleBookingUpdated"
            @deleted="handleBookingDeleted" />
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import FullCalendar from '@fullcalendar/vue3'
import resourceTimelinePlugin from '@fullcalendar/resource-timeline'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import interactionPlugin from '@fullcalendar/interaction'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate, getLanguage } from '@nextcloud/l10n'
const t = (text, vars = {}) => translate('roomvox', text, vars)
const ncLocale = getLanguage().replace('_', '-')

import NcButton from '@nextcloud/vue/components/NcButton'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'

import CreateBookingModal from './CreateBookingModal.vue'
import EventDetailsModal from './EventDetailsModal.vue'
import { useCalendarEvents } from '../../composables/useCalendarEvents.js'
import { updateBooking } from '../../services/api.js'

const props = defineProps({
    rooms: { type: Array, default: () => [] },
    bookings: { type: Array, default: () => [] },
})

const emit = defineEmits(['reload'])

const calendarRef = ref(null)
const currentView = ref('resourceTimelineWeek')
const dateTitle = ref('')

// Modals state
const createModal = ref({
    show: false,
    roomId: null,
    start: null,
    end: null,
})

const detailsModal = ref({
    show: false,
    booking: null,
})

// Use composable for data transformation
const { events, resources } = useCalendarEvents(
    computed(() => props.bookings),
    computed(() => props.rooms)
)

// Calendar options
const calendarOptions = computed(() => ({
    plugins: [resourceTimelinePlugin, dayGridPlugin, timeGridPlugin, interactionPlugin],
    initialView: currentView.value,
    schedulerLicenseKey: 'GPL-My-Project-Is-Open-Source',
    headerToolbar: false,
    height: 'auto',
    nowIndicator: true,
    editable: true,
    selectable: true,
    selectMirror: true,
    eventResizableFromStart: true,
    resourceAreaWidth: '130px',
    slotMinTime: '08:00:00',
    slotMaxTime: '19:00:00',
    slotDuration: '01:00:00',
    snapDuration: '00:15:00',
    scrollTime: '08:00:00',
    weekends: false,
    locale: ncLocale,
    firstDay: 1,
    buttonText: {
        today: t('Today'),
        day: t('Day'),
        week: t('Week'),
        month: t('Month'),
    },
    resources: resources.value,
    events: events.value,
    resourceLabelContent: (arg) => {
        return { html: `<span class="resource-label">${arg.resource.title}</span>` }
    },
    eventContent: (arg) => {
        const status = arg.event.extendedProps?.partstat || ''
        const statusClass = getStatusClass(status)
        return {
            html: `<div class="fc-event-content ${statusClass}">
                <span class="fc-event-time">${arg.timeText}</span>
                <span class="fc-event-title">${arg.event.title}</span>
            </div>`,
        }
    },
    datesSet: handleDatesSet,
    eventClick: handleEventClick,
    eventDrop: handleEventDrop,
    eventResize: handleEventResize,
    select: handleSelect,
    dateClick: handleDateClick,
}))

function getStatusClass(partstat) {
    switch (partstat) {
        case 'ACCEPTED': return 'event-accepted'
        case 'TENTATIVE': return 'event-pending'
        case 'DECLINED': return 'event-declined'
        default: return ''
    }
}

function handleDatesSet(dateInfo) {
    const start = dateInfo.start
    const end = dateInfo.end

    if (currentView.value === 'dayGridMonth') {
        dateTitle.value = start.toLocaleDateString(ncLocale, { month: 'long', year: 'numeric' })
    } else if (currentView.value === 'resourceTimelineDay') {
        dateTitle.value = start.toLocaleDateString(ncLocale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
    } else {
        const endAdjusted = new Date(end)
        endAdjusted.setDate(endAdjusted.getDate() - 1)
        const startMonth = start.toLocaleDateString(ncLocale, { month: 'short' })
        const endMonth = endAdjusted.toLocaleDateString(ncLocale, { month: 'short' })
        if (startMonth === endMonth) {
            dateTitle.value = `${start.getDate()} - ${endAdjusted.getDate()} ${startMonth} ${start.getFullYear()}`
        } else {
            dateTitle.value = `${start.getDate()} ${startMonth} - ${endAdjusted.getDate()} ${endMonth} ${start.getFullYear()}`
        }
    }
}

function handleEventClick(info) {
    const booking = props.bookings.find(b => b.uid === info.event.id)
    if (booking) {
        detailsModal.value = {
            show: true,
            booking: booking,
        }
    }
}

async function handleEventDrop(info) {
    const { event, revert } = info
    const booking = props.bookings.find(b => b.uid === event.id)

    if (!booking) {
        revert()
        return
    }

    const newRoomId = event.getResources()[0]?.id || booking.roomId
    const originalRoomId = booking.roomId

    try {
        // Always use original roomId for the API call, pass newRoomId in body for room changes
        await updateBooking(originalRoomId, event.id, {
            start: event.start.toISOString(),
            end: event.end.toISOString(),
            roomId: newRoomId !== originalRoomId ? newRoomId : undefined,
        })
        showSuccess(t('Booking rescheduled'))
        emit('reload')
    } catch (e) {
        showError(t('Failed to reschedule booking'))
        revert()
    }
}

async function handleEventResize(info) {
    const { event, revert } = info
    const booking = props.bookings.find(b => b.uid === event.id)

    if (!booking) {
        revert()
        return
    }

    try {
        await updateBooking(booking.roomId, event.id, {
            start: event.start.toISOString(),
            end: event.end.toISOString(),
        })
        showSuccess(t('Booking updated'))
        emit('reload')
    } catch (e) {
        showError(t('Failed to update booking'))
        revert()
    }
}

function handleSelect(info) {
    const roomId = info.resource?.id || null
    createModal.value = {
        show: true,
        roomId: roomId,
        start: info.start,
        end: info.end,
    }
    // Clear selection
    const api = calendarRef.value?.getApi()
    if (api) {
        api.unselect()
    }
}

function handleDateClick(info) {
    // Only for month view where select doesn't work well
    if (currentView.value === 'dayGridMonth') {
        const start = info.date
        const end = new Date(start)
        end.setHours(start.getHours() + 1)

        createModal.value = {
            show: true,
            roomId: null,
            start: start,
            end: end,
        }
    }
}

function closeCreateModal() {
    createModal.value = {
        show: false,
        roomId: null,
        start: null,
        end: null,
    }
}

function closeDetailsModal() {
    detailsModal.value = {
        show: false,
        booking: null,
    }
}

function handleBookingCreated() {
    closeCreateModal()
    emit('reload')
}

function handleBookingUpdated() {
    closeDetailsModal()
    emit('reload')
}

function handleBookingDeleted() {
    closeDetailsModal()
    emit('reload')
}

function navigateDate(direction) {
    const api = calendarRef.value?.getApi()
    if (api) {
        if (direction > 0) {
            api.next()
        } else {
            api.prev()
        }
    }
}

function goToToday() {
    const api = calendarRef.value?.getApi()
    if (api) {
        api.today()
    }
}

function changeView(view) {
    currentView.value = view
    const api = calendarRef.value?.getApi()
    if (api) {
        api.changeView(view)
    }
}

// Watch for data changes
watch(() => props.bookings, () => {
    const api = calendarRef.value?.getApi()
    if (api) {
        api.refetchEvents()
    }
}, { deep: true })

watch(() => props.rooms, () => {
    const api = calendarRef.value?.getApi()
    if (api) {
        api.refetchResources()
    }
}, { deep: true })
</script>

<style scoped>
.resource-calendar {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    overflow: hidden;
}

.calendar-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border);
    background: var(--color-background-dark);
}

.toolbar-left {
    display: flex;
    align-items: center;
    gap: 8px;
}

.date-title {
    font-weight: 600;
    min-width: 200px;
    text-align: center;
}

.toolbar-right {
    display: flex;
    align-items: center;
    gap: 16px;
}

.view-selector {
    display: flex;
    gap: 4px;
}

.fullcalendar-container {
    padding: 8px;
}

/* FullCalendar Nextcloud theming */
:deep(.fc) {
    --fc-border-color: var(--color-border);
    --fc-button-bg-color: var(--color-primary-element);
    --fc-button-border-color: var(--color-primary-element);
    --fc-button-hover-bg-color: var(--color-primary-element-hover);
    --fc-button-hover-border-color: var(--color-primary-element-hover);
    --fc-button-active-bg-color: var(--color-primary-element-hover);
    --fc-today-bg-color: var(--color-primary-element-light);
    --fc-neutral-bg-color: var(--color-background-dark);
    --fc-page-bg-color: var(--color-main-background);
    --fc-now-indicator-color: var(--color-error);
    font-family: var(--font-face);
}

:deep(.fc-theme-standard td),
:deep(.fc-theme-standard th) {
    border-color: var(--color-border);
}

:deep(.fc-col-header-cell) {
    background: var(--color-background-dark);
    padding: 8px 4px;
}

:deep(.fc-col-header-cell-cushion) {
    color: var(--color-main-text);
    font-weight: 500;
}

:deep(.fc-daygrid-day-number) {
    color: var(--color-main-text);
}

:deep(.fc-resource-timeline .fc-resource-group) {
    background: var(--color-background-dark);
}

:deep(.fc-timeline-slot) {
    min-width: 40px;
}

:deep(.fc-timeline-slot-label) {
    font-size: 10px;
    color: var(--color-text-maxcontrast);
}

:deep(.resource-label) {
    font-weight: 500;
    font-size: 12px;
    padding: 2px 6px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Event styling */
:deep(.fc-event) {
    border-radius: 4px;
    border: none;
    cursor: pointer;
    transition: transform 0.1s, box-shadow 0.1s;
}

:deep(.fc-event:hover) {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

:deep(.fc-event-content) {
    padding: 1px 4px;
    font-size: 10px;
    display: flex;
    align-items: center;
    gap: 3px;
    overflow: hidden;
}

:deep(.fc-event-time) {
    font-weight: 600;
    flex-shrink: 0;
}

:deep(.fc-event-title) {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

:deep(.event-accepted) {
    background: rgba(70, 186, 97, 0.9) !important;
    color: white;
}

:deep(.event-pending) {
    background: rgba(255, 193, 7, 0.9) !important;
    color: #333;
}

:deep(.event-declined) {
    background: var(--color-background-dark) !important;
    color: var(--color-text-maxcontrast);
    text-decoration: line-through;
}

/* Selection */
:deep(.fc-highlight) {
    background: var(--color-primary-element-light) !important;
}

/* Now indicator */
:deep(.fc-timegrid-now-indicator-line) {
    border-color: var(--color-error);
}

:deep(.fc-timeline-now-indicator-line) {
    border-color: var(--color-error);
}

/* Dark theme support */
[data-themes*="dark"] :deep(.event-accepted),
.theme--dark :deep(.event-accepted) {
    background: rgba(70, 186, 97, 0.8) !important;
}

[data-themes*="dark"] :deep(.event-pending),
.theme--dark :deep(.event-pending) {
    background: rgba(255, 193, 7, 0.8) !important;
    color: white;
}

/* Responsive */
@media (max-width: 768px) {
    .calendar-toolbar {
        flex-direction: column;
        gap: 12px;
    }

    .toolbar-left,
    .toolbar-right {
        width: 100%;
        justify-content: center;
    }

    .date-title {
        min-width: auto;
    }

    :deep(.fc-timeline-slot) {
        min-width: 30px;
    }
}
</style>
