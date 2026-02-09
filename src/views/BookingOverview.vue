<template>
    <div class="booking-overview">
        <div class="booking-overview__header">
            <h2>{{ $t('Bookings') }}</h2>
            <div class="header-controls">
                <NcSelect
                    v-model="selectedRoom"
                    :options="roomOptions"
                    :placeholder="$t('Select a room')"
                    label="label"
                    track-by="id"
                    @input="loadBookings" />
                <NcCheckboxRadioSwitch :model-value="pendingOnly" @update:model-value="pendingOnly = $event; loadBookings()">
                    {{ $t('Pending only') }}
                </NcCheckboxRadioSwitch>
            </div>
        </div>

        <NcEmptyContent
            v-if="!selectedRoom"
            :name="$t('Select a room')"
            :description="$t('Choose a room from the dropdown to view its bookings')">
            <template #icon>
                <CalendarCheck :size="64" />
            </template>
        </NcEmptyContent>

        <div v-if="selectedRoom && loading" class="booking-overview__loading">
            <NcLoadingIcon :size="44" />
        </div>

        <NcEmptyContent
            v-if="selectedRoom && !loading && filteredBookings.length === 0"
            :name="pendingOnly ? $t('No pending bookings') : $t('No bookings')"
            :description="pendingOnly ? $t('All booking requests have been processed') : $t('No events found for this room')">
            <template #icon>
                <CalendarCheck :size="64" />
            </template>
        </NcEmptyContent>

        <div v-if="selectedRoom && !loading && filteredBookings.length > 0" class="booking-overview__card">
            <table class="booking-table">
                <thead>
                    <tr>
                        <th>{{ $t('Event') }}</th>
                        <th>{{ $t('Date') }}</th>
                        <th>{{ $t('Time') }}</th>
                        <th>{{ $t('Organizer') }}</th>
                        <th>{{ $t('Status') }}</th>
                        <th class="th-actions">{{ $t('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="booking in filteredBookings" :key="booking.uid">
                        <td class="booking-summary">{{ booking.summary || $t('Unnamed event') }}</td>
                        <td>{{ formatDate(booking.dtstart) }}</td>
                        <td>{{ formatTime(booking.dtstart) }} – {{ formatTime(booking.dtend) }}</td>
                        <td>{{ booking.organizerName || booking.organizer }}</td>
                        <td>
                            <span :class="'badge badge--' + getStatusClass(booking.partstat)">
                                {{ getStatusLabel(booking.partstat) }}
                            </span>
                        </td>
                        <td>
                            <div v-if="booking.partstat === 'TENTATIVE'" class="booking-actions">
                                <NcButton
                                    type="primary"
                                    :disabled="responding === booking.uid"
                                    @click="respond(booking.uid, 'accept')">
                                    {{ $t('Accept') }}
                                </NcButton>
                                <NcButton
                                    type="error"
                                    :disabled="responding === booking.uid"
                                    @click="respond(booking.uid, 'decline')">
                                    {{ $t('Decline') }}
                                </NcButton>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import CalendarCheck from 'vue-material-design-icons/CalendarCheck.vue'

import { getBookings, respondToBooking } from '../services/api.js'

const props = defineProps({
    rooms: { type: Array, default: () => [] },
})

const selectedRoom = ref(null)
const bookings = ref([])
const loading = ref(false)
const pendingOnly = ref(true)
const responding = ref(null)

const roomOptions = computed(() =>
    props.rooms.map(r => ({ id: r.id, label: r.name }))
)

const filteredBookings = computed(() => {
    if (pendingOnly.value) {
        return bookings.value.filter(b => b.partstat === 'TENTATIVE')
    }
    return bookings.value
})

const loadBookings = async () => {
    if (!selectedRoom.value) {
        bookings.value = []
        return
    }

    loading.value = true
    try {
        const response = await getBookings(selectedRoom.value.id)
        bookings.value = response.data
    } catch (e) {
        showError('Failed to load bookings')
        bookings.value = []
    } finally {
        loading.value = false
    }
}

const respond = async (bookingUid, action) => {
    if (!selectedRoom.value) return

    responding.value = bookingUid
    try {
        await respondToBooking(selectedRoom.value.id, bookingUid, action)
        showSuccess(action === 'accept' ? 'Booking accepted' : 'Booking declined')
        await loadBookings()
    } catch (e) {
        showError('Failed to process response')
    } finally {
        responding.value = null
    }
}

const formatDate = (dateStr) => {
    if (!dateStr) return '—'
    const d = new Date(dateStr)
    return d.toLocaleDateString(undefined, { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' })
}

const formatTime = (dateStr) => {
    if (!dateStr) return '—'
    const d = new Date(dateStr)
    return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
}

const getStatusClass = (partstat) => {
    switch (partstat) {
        case 'ACCEPTED': return 'success'
        case 'DECLINED': return 'error'
        case 'TENTATIVE': return 'warning'
        default: return 'neutral'
    }
}

const getStatusLabel = (partstat) => {
    switch (partstat) {
        case 'ACCEPTED': return 'Accepted'
        case 'DECLINED': return 'Declined'
        case 'TENTATIVE': return 'Pending'
        case 'NEEDS-ACTION': return 'Needs action'
        default: return partstat || 'Unknown'
    }
}
</script>

<style scoped>
.booking-overview__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 12px;
}

.booking-overview__header h2 {
    font-size: 20px;
    font-weight: 700;
}

.header-controls {
    display: flex;
    align-items: center;
    gap: 16px;
}

.header-controls .v-select {
    min-width: 250px;
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
}

.booking-table th {
    text-align: left;
    padding: 12px 16px;
    background: var(--color-background-dark);
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    border-bottom: 1px solid var(--color-border);
}

.th-actions {
    width: 160px;
    text-align: center !important;
}

.booking-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border);
}

.booking-table tbody tr:last-child td {
    border-bottom: none;
}

.booking-summary {
    font-weight: 500;
}

.booking-actions {
    display: flex;
    gap: 4px;
    justify-content: center;
}

.badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 500;
    white-space: nowrap;
}

.badge--success {
    background: var(--color-success-element-light);
    color: var(--color-success-text);
}

.badge--error {
    background: var(--color-error-element-light);
    color: var(--color-error-text);
}

.badge--warning {
    background: var(--color-warning-element-light);
    color: var(--color-warning-text);
}

.badge--neutral {
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
}
</style>
