<template>
    <div class="booking-overview">
        <!-- Stats Cards -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-value">{{ stats.today }}</div>
                <div class="stat-label">{{ t('roomvox', 'Today') }}</div>
            </div>
            <div class="stat-card stat-card--warning" @click="statusFilter = 'pending'">
                <div class="stat-value">{{ stats.pending }}</div>
                <div class="stat-label">{{ t('roomvox', 'Pending') }}</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">{{ stats.thisWeek }}</div>
                <div class="stat-label">{{ t('roomvox', 'This week') }}</div>
            </div>
        </div>

        <!-- Filters Row -->
        <div class="filters-row">
            <div class="filters-left">
                <NcSelect
                    v-model="selectedRoom"
                    :options="roomOptions"
                    :placeholder="t('roomvox', 'All rooms')"
                    label="label"
                    track-by="id"
                    :clearable="true"
                    class="room-filter" />

                <div class="status-tabs">
                    <button
                        v-for="tab in statusTabs"
                        :key="tab.value"
                        :class="['status-tab', { active: statusFilter === tab.value }]"
                        @click="statusFilter = tab.value">
                        {{ tab.label }}
                        <span v-if="tab.value === 'pending' && stats.pending > 0" class="tab-badge">
                            {{ stats.pending }}
                        </span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Date range + View toggle row -->
        <div class="filters-row">
            <div class="filters-left">
                <div class="status-tabs">
                    <button
                        v-for="tab in dateRangeTabs"
                        :key="tab.value"
                        :class="['status-tab', { active: dateRange === tab.value }]"
                        @click="dateRange = tab.value">
                        {{ tab.label }}
                    </button>
                </div>
            </div>

            <div class="filters-right">
                <div class="view-toggle">
                    <button
                        :class="['view-btn', { active: viewMode === 'list' }]"
                        @click="viewMode = 'list'"
                        :title="t('roomvox', 'List view')">
                        <FormatListBulleted :size="20" />
                    </button>
                    <button
                        :class="['view-btn', { active: viewMode === 'calendar' }]"
                        @click="viewMode = 'calendar'"
                        :title="t('roomvox', 'Calendar view')">
                        <CalendarMonth :size="20" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="booking-overview__loading">
            <NcLoadingIcon :size="44" />
        </div>

        <!-- Empty State -->
        <NcEmptyContent
            v-if="!loading && filteredBookings.length === 0"
            :name="emptyTitle"
            :description="emptyDescription">
            <template #icon>
                <CalendarCheck :size="64" />
            </template>
        </NcEmptyContent>

        <!-- List View -->
        <div v-if="!loading && filteredBookings.length > 0 && viewMode === 'list'" class="booking-overview__card">
            <table class="booking-table">
                <colgroup>
                    <col style="width: 15%">
                    <col style="width: 13%">
                    <col style="width: 22%">
                    <col style="width: 15%">
                    <col style="width: 10%">
                    <col style="width: 10%">
                    <col style="width: 15%">
                </colgroup>
                <thead>
                    <tr>
                        <th class="th-sortable" @click="toggleSort('summary')">
                            {{ t('roomvox', 'Event') }}
                            <span v-if="sortField === 'summary'" class="sort-icon">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                        </th>
                        <th class="th-sortable" @click="toggleSort('roomName')">
                            {{ t('roomvox', 'Room') }}
                            <span v-if="sortField === 'roomName'" class="sort-icon">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                        </th>
                        <th class="th-sortable" @click="toggleSort('roomLocation')">
                            {{ t('roomvox', 'Location') }}
                            <span v-if="sortField === 'roomLocation'" class="sort-icon">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                        </th>
                        <th class="th-sortable" @click="toggleSort('dtstart')">
                            {{ t('roomvox', 'When') }}
                            <span v-if="sortField === 'dtstart'" class="sort-icon">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                        </th>
                        <th class="th-sortable" @click="toggleSort('organizerName')">
                            {{ t('roomvox', 'Organizer') }}
                            <span v-if="sortField === 'organizerName'" class="sort-icon">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                        </th>
                        <th class="th-sortable" @click="toggleSort('partstat')">
                            {{ t('roomvox', 'Status') }}
                            <span v-if="sortField === 'partstat'" class="sort-icon">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                        </th>
                        <th class="th-actions">{{ t('roomvox', 'Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="booking in filteredBookings" :key="booking.uid + booking.roomId">
                        <td class="booking-summary">{{ booking.summary || t('roomvox', 'Unnamed event') }}</td>
                        <td class="booking-room">{{ booking.roomName }}</td>
                        <td>{{ booking.roomLocation || '—' }}</td>
                        <td class="booking-when">
                            <div class="when-date">{{ formatRelativeDate(booking.dtstart) }}</div>
                            <div class="when-time">
                                <template v-if="booking.allDay">{{ t('roomvox', 'All day') }}</template>
                                <template v-else>{{ formatTime(booking.dtstart) }} – {{ formatTime(booking.dtend) }}</template>
                            </div>
                        </td>
                        <td>{{ booking.organizerName || booking.organizer }}</td>
                        <td>
                            <NcChip
                                :text="getStatusLabel(booking.partstat)"
                                :type="getStatusType(booking.partstat)"
                                no-close />
                        </td>
                        <td>
                            <div class="booking-actions">
                                <template v-if="booking.partstat === 'TENTATIVE'">
                                    <NcButton
                                        type="success"
                                        :disabled="responding === booking.uid"
                                        @click="respond(booking.roomId, booking.uid, 'accept')">
                                        <template #icon>
                                            <Check :size="20" />
                                        </template>
                                    </NcButton>
                                    <NcButton
                                        type="error"
                                        :disabled="responding === booking.uid"
                                        @click="respond(booking.roomId, booking.uid, 'decline')">
                                        <template #icon>
                                            <Close :size="20" />
                                        </template>
                                    </NcButton>
                                </template>
                                <NcButton
                                    type="tertiary"
                                    :href="getCalendarLink(booking)"
                                    :title="t('roomvox', 'Open in Calendar')">
                                    <template #icon>
                                        <OpenInNew :size="20" />
                                    </template>
                                </NcButton>
                                <NcButton
                                    type="tertiary"
                                    :title="t('roomvox', 'Cancel booking')"
                                    @click="confirmDelete(booking)">
                                    <template #icon>
                                        <Delete :size="20" />
                                    </template>
                                </NcButton>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Calendar View (FullCalendar Resource Timeline) -->
        <ResourceCalendar
            v-if="!loading && viewMode === 'calendar'"
            :rooms="roomsForCalendar"
            :bookings="bookings"
            :show-weekends="showWeekends"
            @reload="loadBookings" />
        <!-- Cancel Booking Confirmation Dialog -->
        <NcDialog
            v-if="deleteTarget"
            :name="t('roomvox', 'Cancel booking')"
            :open="!!deleteTarget"
            @close="deleteTarget = null">
            <template v-if="deleteTarget?.recurrenceId">
                <p>{{ t('roomvox', 'This booking is part of a recurring series. What would you like to cancel?') }}</p>
                <p><strong>{{ deleteTarget?.summary }}</strong></p>
                <p>{{ formatRelativeDate(deleteTarget?.dtstart) }} {{ formatTime(deleteTarget?.dtstart) }} – {{ formatTime(deleteTarget?.dtend) }}</p>
                <p>{{ t('roomvox', 'The booker will be notified by email.') }}</p>
            </template>
            <template v-else>
                <p>{{ t('roomvox', 'Are you sure you want to cancel this booking? The booker will be notified by email.') }}</p>
                <p><strong>{{ deleteTarget?.summary }}</strong></p>
                <p>{{ formatRelativeDate(deleteTarget?.dtstart) }} {{ formatTime(deleteTarget?.dtstart) }} – {{ formatTime(deleteTarget?.dtend) }}</p>
            </template>
            <template #actions>
                <NcButton type="tertiary" @click="deleteTarget = null">{{ t('roomvox', 'Keep') }}</NcButton>
                <template v-if="deleteTarget?.recurrenceId">
                    <NcButton type="warning" :disabled="deleting" @click="executeDelete('occurrence')">
                        {{ t('roomvox', 'This occurrence') }}
                    </NcButton>
                    <NcButton type="error" :disabled="deleting" @click="executeDelete('series')">
                        {{ t('roomvox', 'Entire series') }}
                    </NcButton>
                </template>
                <NcButton v-else type="error" :disabled="deleting" @click="executeDelete('series')">
                    {{ t('roomvox', 'Cancel booking') }}
                </NcButton>
            </template>
        </NcDialog>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { translate, getLanguage } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcChip from '@nextcloud/vue/components/NcChip'
import CalendarCheck from 'vue-material-design-icons/CalendarCheck.vue'
import CalendarMonth from 'vue-material-design-icons/CalendarMonth.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

import NcDialog from '@nextcloud/vue/components/NcDialog'
import ResourceCalendar from '../components/calendar/ResourceCalendar.vue'
import { getAllBookings, respondToBooking, deleteBooking } from '../services/api.js'
import { generateUrl } from '@nextcloud/router'

const t = (app, text, vars = {}) => translate(app, text, vars)
const ncLocale = getLanguage().replace('_', '-')

const props = defineProps({
    rooms: { type: Array, default: () => [] },
    // Room groups, used to disambiguate rooms with the same name across
    // groups in the filter (issue #19). Optional — falls back to plain names.
    roomGroups: { type: Array, default: () => [] },
    showWeekends: { type: Boolean, default: true },
    // 'view' (admin default) shows everything the user can view;
    // 'manage' restricts to rooms the user can manage (issue #12).
    scope: { type: String, default: 'view' },
})

const selectedRoom = ref(null)
const bookings = ref([])
const loading = ref(false)
const responding = ref(null)
const deleting = ref(false)
const deleteTarget = ref(null)
const statusFilter = ref('all')
const viewMode = ref('list')
const dateRange = ref('upcoming')
const sortField = ref('dtstart')
const sortDirection = ref('asc')

const stats = ref({
    today: 0,
    pending: 0,
    thisWeek: 0,
})

const statusTabs = computed(() => [
    { value: 'all', label: t('roomvox', 'All') },
    { value: 'pending', label: t('roomvox', 'Pending') },
    { value: 'accepted', label: t('roomvox', 'Accepted') },
    { value: 'declined', label: t('roomvox', 'Declined') },
])

const dateRangeTabs = computed(() => [
    { value: 'upcoming', label: t('roomvox', 'Upcoming') },
    { value: 'thisWeek', label: t('roomvox', 'This week') },
    { value: 'thisMonth', label: t('roomvox', 'This month') },
    { value: 'all', label: t('roomvox', 'All') },
    { value: 'past', label: t('roomvox', 'Past') },
])

const groupNameById = computed(() => {
    const map = {}
    for (const g of props.roomGroups) {
        map[g.id] = g.name
    }
    return map
})

// Label rooms as "Room (Group)" so names reused across groups can be told
// apart in the filter (issue #19). Rooms without a (resolvable) group keep
// their plain name.
const roomOptions = computed(() => [
    { id: null, label: t('roomvox', 'All rooms') },
    ...props.rooms.map(r => {
        const groupName = r.groupId ? groupNameById.value[r.groupId] : null
        return { id: r.id, label: groupName ? `${r.name} (${groupName})` : r.name }
    }),
])

const filteredBookings = computed(() => {
    const sorted = [...bookings.value]
    sorted.sort((a, b) => {
        let aVal = a[sortField.value] ?? ''
        let bVal = b[sortField.value] ?? ''
        // For organizerName, fall back to organizer
        if (sortField.value === 'organizerName') {
            aVal = a.organizerName || a.organizer || ''
            bVal = b.organizerName || b.organizer || ''
        }
        let cmp = 0
        if (sortField.value === 'dtstart' || sortField.value === 'dtend') {
            cmp = new Date(aVal) - new Date(bVal)
        } else {
            cmp = String(aVal).localeCompare(String(bVal))
        }
        return sortDirection.value === 'asc' ? cmp : -cmp
    })
    return sorted
})

const emptyTitle = computed(() => {
    if (statusFilter.value === 'pending') return t('roomvox', 'No pending bookings')
    if (statusFilter.value === 'accepted') return t('roomvox', 'No accepted bookings')
    if (statusFilter.value === 'declined') return t('roomvox', 'No declined bookings')
    return t('roomvox', 'No bookings')
})

const emptyDescription = computed(() => {
    if (statusFilter.value === 'pending') return t('roomvox', 'All booking requests have been processed')
    return t('roomvox', 'No events found for the selected filters')
})

const roomsForCalendar = computed(() => {
    if (selectedRoom.value?.id) {
        return props.rooms.filter(r => r.id === selectedRoom.value.id)
    }
    return props.rooms
})

const getDateRangeParams = () => {
    const now = new Date()
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())

    switch (dateRange.value) {
        case 'upcoming':
            return { from: today.toISOString() }
        case 'thisWeek': {
            const day = today.getDay()
            const monday = new Date(today)
            monday.setDate(today.getDate() - (day === 0 ? 6 : day - 1))
            const sunday = new Date(monday)
            sunday.setDate(monday.getDate() + 6)
            sunday.setHours(23, 59, 59)
            return { from: monday.toISOString(), to: sunday.toISOString() }
        }
        case 'thisMonth': {
            const firstDay = new Date(today.getFullYear(), today.getMonth(), 1)
            const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0, 23, 59, 59)
            return { from: firstDay.toISOString(), to: lastDay.toISOString() }
        }
        case 'past':
            return { to: today.toISOString() }
        default:
            return {}
    }
}

const toggleSort = (field) => {
    if (sortField.value === field) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
    } else {
        sortField.value = field
        sortDirection.value = 'asc'
    }
}

const loadBookings = async () => {
    loading.value = true
    try {
        const params = { ...getDateRangeParams() }
        if (selectedRoom.value?.id) {
            params.room = selectedRoom.value.id
        }
        if (statusFilter.value !== 'all') {
            params.status = statusFilter.value
        }
        if (props.scope === 'manage') {
            params.scope = 'manage'
        }

        const response = await getAllBookings(params)
        bookings.value = response.data.bookings || []
        stats.value = response.data.stats || { today: 0, pending: 0, thisWeek: 0 }
    } catch (e) {
        showError(t('roomvox', 'Failed to load bookings'))
        bookings.value = []
    } finally {
        loading.value = false
    }
}

const respond = async (roomId, bookingUid, action) => {
    responding.value = bookingUid
    try {
        await respondToBooking(roomId, bookingUid, action)
        showSuccess(action === 'accept' ? t('roomvox', 'Booking accepted') : t('roomvox', 'Booking declined'))
        await loadBookings()
    } catch (e) {
        showError(t('roomvox', 'Failed to process response'))
    } finally {
        responding.value = null
    }
}

/**
 * Parse a booking boundary. All-day bookings arrive as a bare "YYYY-MM-DD",
 * which `new Date()` reads as UTC midnight — west of UTC that renders as the
 * previous day. Build those as a local date instead (issue #27).
 */
const parseBookingDate = (dateStr) => {
    const dateOnly = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dateStr)
    if (dateOnly) {
        return new Date(Number(dateOnly[1]), Number(dateOnly[2]) - 1, Number(dateOnly[3]))
    }
    return new Date(dateStr)
}

const formatRelativeDate = (dateStr) => {
    if (!dateStr) return '—'
    const d = parseBookingDate(dateStr)
    const today = new Date()
    const tomorrow = new Date()
    tomorrow.setDate(today.getDate() + 1)

    if (d.toDateString() === today.toDateString()) {
        return t('roomvox', 'Today')
    }
    if (d.toDateString() === tomorrow.toDateString()) {
        return t('roomvox', 'Tomorrow')
    }
    return d.toLocaleDateString(ncLocale, { weekday: 'short', day: 'numeric', month: 'short' })
}

const formatTime = (dateStr) => {
    if (!dateStr) return '—'
    const d = new Date(dateStr)
    return d.toLocaleTimeString(ncLocale, { hour: '2-digit', minute: '2-digit' })
}

const getStatusType = (partstat) => {
    switch (partstat) {
        case 'ACCEPTED': return 'success'
        case 'DECLINED': return 'error'
        case 'TENTATIVE': return 'warning'
        default: return 'secondary'
    }
}

const getStatusLabel = (partstat) => {
    switch (partstat) {
        case 'ACCEPTED': return t('roomvox', 'Accepted')
        case 'DECLINED': return t('roomvox', 'Declined')
        case 'TENTATIVE': return t('roomvox', 'Pending')
        case 'NEEDS-ACTION': return t('roomvox', 'Needs action')
        default: return partstat || t('roomvox', 'Unknown')
    }
}

const getCalendarLink = (booking) => {
    if (!booking.dtstart) return '#'
    const date = booking.dtstart.split('T')[0]
    return generateUrl(`/apps/calendar/dayGridMonth/${date}`)
}

const confirmDelete = (booking) => {
    deleteTarget.value = booking
}

const executeDelete = async (mode = 'series') => {
    if (!deleteTarget.value) return
    deleting.value = true
    const recurrenceId = mode === 'occurrence' ? deleteTarget.value.recurrenceId : null
    try {
        await deleteBooking(deleteTarget.value.roomId, deleteTarget.value.uid, recurrenceId)
        showSuccess(mode === 'occurrence' ? t('roomvox', 'Occurrence cancelled') : t('roomvox', 'Booking cancelled'))
        deleteTarget.value = null
        await loadBookings()
    } catch (e) {
        showError(t('roomvox', 'Failed to cancel booking'))
    } finally {
        deleting.value = false
    }
}

watch([selectedRoom, statusFilter, dateRange], () => {
    loadBookings()
})

onMounted(() => {
    loadBookings()
})
</script>

<style scoped>
.stats-row {
    display: flex;
    gap: 16px;
    margin-bottom: 24px;
}

.stat-card {
    flex: 1;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 20px;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.2s;
}

.stat-card:hover {
    border-color: var(--color-primary-element);
}

.stat-card--warning {
    border-left: 4px solid var(--color-warning);
}

.stat-value {
    font-size: 32px;
    font-weight: 700;
    color: var(--color-main-text);
}

.stat-label {
    font-size: 14px;
    color: var(--color-text-maxcontrast);
    margin-top: 4px;
}

.filters-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    gap: 16px;
    flex-wrap: wrap;
    min-width: 0;
}

.filters-left {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    min-width: 0;
}

.room-filter {
    min-width: 120px;
}

.status-tabs {
    display: flex;
    background: var(--color-background-dark);
    border-radius: var(--border-radius-large);
    padding: 4px;
}

.status-tab {
    padding: 8px 16px;
    border: none;
    background: transparent;
    color: var(--color-text-maxcontrast);
    cursor: pointer;
    border-radius: var(--border-radius);
    font-size: 14px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}

.status-tab:hover {
    color: var(--color-main-text);
}

.status-tab.active {
    background: var(--color-main-background);
    color: var(--color-main-text);
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.tab-badge {
    background: var(--color-warning);
    color: white;
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 10px;
    font-weight: 600;
}

.view-toggle {
    display: flex;
    background: var(--color-background-dark);
    border-radius: var(--border-radius-large);
    padding: 4px;
}

.view-btn {
    padding: 8px;
    border: none;
    background: transparent;
    color: var(--color-text-maxcontrast);
    cursor: pointer;
    border-radius: var(--border-radius);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.view-btn:hover {
    color: var(--color-main-text);
}

.view-btn.active {
    background: var(--color-main-background);
    color: var(--color-primary-element);
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.booking-overview__loading {
    display: flex;
    justify-content: center;
    padding: 60px;
}

.booking-overview__card {
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    overflow: hidden;
}

.booking-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}

.booking-table th {
    text-align: left;
    padding: 12px;
    background: var(--color-background-dark);
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    border-bottom: 1px solid var(--color-border);
}

.th-sortable {
    cursor: pointer;
    user-select: none;
    white-space: nowrap;
    transition: color 0.15s;
}

.th-sortable:hover {
    color: var(--color-main-text);
}

.sort-icon {
    font-size: 10px;
    margin-left: 4px;
    color: var(--color-primary-element);
}

.th-actions {
    text-align: center !important;
}

.booking-table td {
    padding: 12px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: middle;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.booking-table tbody tr:last-child td {
    border-bottom: none;
}

.booking-table tbody tr:hover {
    background: var(--color-background-hover);
}

.booking-summary {
    font-weight: 500;
}

.booking-room {
    color: var(--color-text-maxcontrast);
}

.booking-when {
    white-space: nowrap;
}

.when-date {
    font-weight: 500;
}

.when-time {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.booking-actions {
    display: flex;
    gap: 4px;
    justify-content: center;
}

@media (max-width: 768px) {
    .stats-row {
        flex-direction: column;
    }

    .filters-row {
        flex-direction: column;
        align-items: stretch;
    }

    .filters-left {
        flex-direction: column;
        align-items: stretch;
    }

    .room-filter {
        min-width: auto;
    }
}

/* Cancel-booking dialog: allow action buttons to wrap on narrow widths or
   long translations (e.g. NL/DE/FR labels) instead of being truncated. */
:deep(.dialog__actions),
:deep(.dialog-buttons) {
    flex-wrap: wrap;
    gap: 8px;
}
:deep(.dialog__actions) .button-vue,
:deep(.dialog-buttons) .button-vue {
    flex-shrink: 0;
}
</style>
