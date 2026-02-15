<template>
    <div class="booking-overview">
        <!-- Stats Cards -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-value">{{ stats.today }}</div>
                <div class="stat-label">{{ $t('Today') }}</div>
            </div>
            <div class="stat-card stat-card--warning" @click="statusFilter = 'pending'">
                <div class="stat-value">{{ stats.pending }}</div>
                <div class="stat-label">{{ $t('Pending') }}</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">{{ stats.thisWeek }}</div>
                <div class="stat-label">{{ $t('This Week') }}</div>
            </div>
        </div>

        <!-- Filters Row -->
        <div class="filters-row">
            <div class="filters-left">
                <NcSelect
                    v-model="selectedRoom"
                    :options="roomOptions"
                    :placeholder="$t('All rooms')"
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
                        :title="$t('List view')">
                        <FormatListBulleted :size="20" />
                    </button>
                    <button
                        :class="['view-btn', { active: viewMode === 'calendar' }]"
                        @click="viewMode = 'calendar'"
                        :title="$t('Calendar view')">
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
                            {{ $t('Event') }}
                            <span v-if="sortField === 'summary'" class="sort-icon">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                        </th>
                        <th class="th-sortable" @click="toggleSort('roomName')">
                            {{ $t('Room') }}
                            <span v-if="sortField === 'roomName'" class="sort-icon">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                        </th>
                        <th class="th-sortable" @click="toggleSort('roomLocation')">
                            {{ $t('Location') }}
                            <span v-if="sortField === 'roomLocation'" class="sort-icon">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                        </th>
                        <th class="th-sortable" @click="toggleSort('dtstart')">
                            {{ $t('When') }}
                            <span v-if="sortField === 'dtstart'" class="sort-icon">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                        </th>
                        <th class="th-sortable" @click="toggleSort('organizerName')">
                            {{ $t('Organizer') }}
                            <span v-if="sortField === 'organizerName'" class="sort-icon">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                        </th>
                        <th class="th-sortable" @click="toggleSort('partstat')">
                            {{ $t('Status') }}
                            <span v-if="sortField === 'partstat'" class="sort-icon">{{ sortDirection === 'asc' ? '▲' : '▼' }}</span>
                        </th>
                        <th class="th-actions">{{ $t('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="booking in filteredBookings" :key="booking.uid + booking.roomId">
                        <td class="booking-summary">{{ booking.summary || $t('Unnamed event') }}</td>
                        <td class="booking-room">{{ booking.roomName }}</td>
                        <td>{{ booking.roomLocation || '—' }}</td>
                        <td class="booking-when">
                            <div class="when-date">{{ formatRelativeDate(booking.dtstart) }}</div>
                            <div class="when-time">{{ formatTime(booking.dtstart) }} – {{ formatTime(booking.dtend) }}</div>
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
                                    :title="$t('Open in Calendar')">
                                    <template #icon>
                                        <OpenInNew :size="20" />
                                    </template>
                                </NcButton>
                                <NcButton
                                    type="tertiary"
                                    :title="$t('Delete booking')"
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
            @reload="loadBookings" />
        <!-- Delete Confirmation Dialog -->
        <NcDialog
            v-if="deleteTarget"
            :name="$t('Delete booking')"
            :open="!!deleteTarget"
            @close="deleteTarget = null">
            <p>{{ $t('Are you sure you want to delete this booking?') }}</p>
            <p><strong>{{ deleteTarget?.summary }}</strong></p>
            <p>{{ formatRelativeDate(deleteTarget?.dtstart) }} {{ formatTime(deleteTarget?.dtstart) }} – {{ formatTime(deleteTarget?.dtend) }}</p>
            <template #actions>
                <NcButton type="tertiary" @click="deleteTarget = null">{{ $t('Cancel') }}</NcButton>
                <NcButton type="error" :disabled="deleting" @click="executeDelete">
                    <template #icon>
                        <Delete :size="20" />
                    </template>
                    {{ $t('Delete') }}
                </NcButton>
            </template>
        </NcDialog>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { translate } from '@nextcloud/l10n'
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

const t = (text, vars = {}) => translate('roomvox', text, vars)

const props = defineProps({
    rooms: { type: Array, default: () => [] },
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
    { value: 'all', label: t('All') },
    { value: 'pending', label: t('Pending') },
    { value: 'accepted', label: t('Accepted') },
    { value: 'declined', label: t('Declined') },
])

const dateRangeTabs = computed(() => [
    { value: 'upcoming', label: t('Upcoming') },
    { value: 'thisWeek', label: t('This week') },
    { value: 'thisMonth', label: t('This month') },
    { value: 'all', label: t('All') },
    { value: 'past', label: t('Past') },
])

const roomOptions = computed(() => [
    { id: null, label: t('All rooms') },
    ...props.rooms.map(r => ({ id: r.id, label: r.name })),
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
    if (statusFilter.value === 'pending') return t('No pending bookings')
    if (statusFilter.value === 'accepted') return t('No accepted bookings')
    if (statusFilter.value === 'declined') return t('No declined bookings')
    return t('No bookings')
})

const emptyDescription = computed(() => {
    if (statusFilter.value === 'pending') return t('All booking requests have been processed')
    return t('No events found for the selected filters')
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

        const response = await getAllBookings(params)
        bookings.value = response.data.bookings || []
        stats.value = response.data.stats || { today: 0, pending: 0, thisWeek: 0 }
    } catch (e) {
        showError(t('Failed to load bookings'))
        bookings.value = []
    } finally {
        loading.value = false
    }
}

const respond = async (roomId, bookingUid, action) => {
    responding.value = bookingUid
    try {
        await respondToBooking(roomId, bookingUid, action)
        showSuccess(action === 'accept' ? t('Booking accepted') : t('Booking declined'))
        await loadBookings()
    } catch (e) {
        showError(t('Failed to process response'))
    } finally {
        responding.value = null
    }
}

const formatRelativeDate = (dateStr) => {
    if (!dateStr) return '—'
    const d = new Date(dateStr)
    const today = new Date()
    const tomorrow = new Date()
    tomorrow.setDate(today.getDate() + 1)

    if (d.toDateString() === today.toDateString()) {
        return t('Today')
    }
    if (d.toDateString() === tomorrow.toDateString()) {
        return t('Tomorrow')
    }
    return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })
}

const formatTime = (dateStr) => {
    if (!dateStr) return '—'
    const d = new Date(dateStr)
    return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
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
        case 'ACCEPTED': return t('Accepted')
        case 'DECLINED': return t('Declined')
        case 'TENTATIVE': return t('Pending')
        case 'NEEDS-ACTION': return t('Needs action')
        default: return partstat || t('Unknown')
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

const executeDelete = async () => {
    if (!deleteTarget.value) return
    deleting.value = true
    try {
        await deleteBooking(deleteTarget.value.roomId, deleteTarget.value.uid)
        showSuccess(t('Booking deleted'))
        deleteTarget.value = null
        await loadBookings()
    } catch (e) {
        showError(t('Failed to delete booking'))
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
</style>
