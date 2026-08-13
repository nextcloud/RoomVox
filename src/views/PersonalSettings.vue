<template>
    <div class="roomvox-personal">
        <nav class="tab-navigation">
            <button
                :class="['tab-button', { active: currentTab === 'rooms' }]"
                @click="currentTab = 'rooms'">
                <DoorOpen :size="16" />
                {{ t('roomvox', 'My rooms') }}
                <NcCounterBubble v-if="rooms.length > 0" :count="rooms.length" />
            </button>
            <button
                :class="['tab-button', { active: currentTab === 'approvals' }]"
                @click="currentTab = 'approvals'">
                <CheckDecagram :size="16" />
                {{ t('roomvox', 'Approvals') }}
                <NcCounterBubble v-if="approvals.length > 0" type="highlighted" :count="approvals.length" />
            </button>
            <button
                v-if="managedRooms.length > 0"
                :class="['tab-button', { active: currentTab === 'bookings' }]"
                @click="currentTab = 'bookings'">
                <CalendarCheck :size="16" />
                {{ t('roomvox', 'Bookings') }}
            </button>
        </nav>

        <div class="tab-content">
            <!-- My Rooms Tab -->
            <div v-if="currentTab === 'rooms'">
                <div v-if="loadingRooms" class="loading-state">
                    <NcLoadingIcon :size="32" />
                </div>

                <NcEmptyContent v-else-if="rooms.length === 0"
                    :name="t('roomvox', 'No rooms')"
                    :description="t('roomvox', 'You don\'t have access to any rooms yet. Ask an administrator to grant you access.')">
                    <template #icon>
                        <DoorOpen :size="64" />
                    </template>
                </NcEmptyContent>

                <table v-else class="rooms-table">
                    <thead>
                        <tr>
                            <th>{{ t('roomvox', 'Name') }}</th>
                            <th>{{ t('roomvox', 'Type') }}</th>
                            <th>{{ t('roomvox', 'Capacity') }}</th>
                            <th>{{ t('roomvox', 'Location') }}</th>
                            <th>{{ t('roomvox', 'Responsible contact') }}</th>
                            <th>{{ t('roomvox', 'Role') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="room in rooms" :key="room.id">
                            <td class="room-name">{{ room.name }}</td>
                            <td>{{ room.roomType || '—' }}</td>
                            <td>{{ room.capacity || '—' }}</td>
                            <td>{{ formatAddress(room.address) }}</td>
                            <td>{{ room.responsibleContact || '—' }}</td>
                            <td>
                                <NcChip
                                    :text="getRoleLabel(room.role)"
                                    :type="getRoleVariant(room.role)"
                                    no-close />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Approvals Tab -->
            <div v-if="currentTab === 'approvals'">
                <div v-if="loadingApprovals" class="loading-state">
                    <NcLoadingIcon :size="32" />
                </div>

                <NcEmptyContent v-else-if="approvals.length === 0"
                    :name="t('roomvox', 'No pending approvals')"
                    :description="t('roomvox', 'All booking requests have been processed.')">
                    <template #icon>
                        <CheckDecagram :size="64" />
                    </template>
                </NcEmptyContent>

                <table v-else class="approvals-table">
                    <thead>
                        <tr>
                            <th>{{ t('roomvox', 'Room') }}</th>
                            <th>{{ t('roomvox', 'Event') }}</th>
                            <th>{{ t('roomvox', 'When') }}</th>
                            <th>{{ t('roomvox', 'Requested by') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="booking in approvals" :key="booking.uid">
                            <td class="room-name">{{ booking.roomName }}</td>
                            <td>{{ booking.summary }}</td>
                            <td>
                                <div class="when-date">{{ formatRelativeDate(booking.dtstart) }}</div>
                                <div class="when-time">{{ formatTime(booking.dtstart) }} – {{ formatTime(booking.dtend) }}</div>
                            </td>
                            <td>{{ booking.organizerName || booking.organizer }}</td>
                            <td>
                                <div class="booking-actions">
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
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Bookings Tab (manager view) -->
            <div v-if="currentTab === 'bookings'">
                <BookingOverview :rooms="managedRooms" scope="manage" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { translate, getLanguage } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcChip from '@nextcloud/vue/components/NcChip'
import DoorOpen from 'vue-material-design-icons/DoorOpen.vue'
import CheckDecagram from 'vue-material-design-icons/CheckDecagram.vue'
import CalendarCheck from 'vue-material-design-icons/CalendarCheck.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'

import BookingOverview from './BookingOverview.vue'
import { getMyRooms, getMyApprovals, respondToBooking } from '../services/api.js'

const t = (app, text, vars = {}) => translate(app, text, vars)
const ncLocale = getLanguage().replace('_', '-')

const currentTab = ref('rooms')
const rooms = ref([])
const approvals = ref([])
const loadingRooms = ref(true)
const loadingApprovals = ref(true)
const responding = ref(null)

// Rooms this user can manage — drives the Bookings tab visibility and scope.
const managedRooms = computed(() =>
    rooms.value.filter(r => r.role === 'manager' || r.role === 'admin'),
)

const getRoleLabel = (role) => {
    switch (role) {
        case 'admin': return t('roomvox', 'Admin')
        case 'manager': return t('roomvox', 'Manager')
        case 'booker': return t('roomvox', 'Booker')
        case 'viewer': return t('roomvox', 'Viewer')
        default: return role
    }
}

const getRoleVariant = (role) => {
    switch (role) {
        case 'admin': return 'error'
        case 'manager': return 'warning'
        case 'booker': return 'success'
        case 'viewer': return 'primary'
        default: return 'secondary'
    }
}

const formatAddress = (address) => {
    if (!address) return '—'
    const cleaned = address.replace(/^[\s,]+|[\s,]+$/g, '')
    return cleaned || '—'
}

const formatRelativeDate = (dateStr) => {
    if (!dateStr) return '—'
    const d = new Date(dateStr)
    const today = new Date()
    const tomorrow = new Date()
    tomorrow.setDate(today.getDate() + 1)

    if (d.toDateString() === today.toDateString()) return t('roomvox', 'Today')
    if (d.toDateString() === tomorrow.toDateString()) return t('roomvox', 'Tomorrow')
    return d.toLocaleDateString(ncLocale, { weekday: 'short', day: 'numeric', month: 'short' })
}

const formatTime = (dateStr) => {
    if (!dateStr) return '—'
    const d = new Date(dateStr)
    return d.toLocaleTimeString(ncLocale, { hour: '2-digit', minute: '2-digit' })
}

const respond = async (roomId, bookingUid, action) => {
    responding.value = bookingUid
    try {
        await respondToBooking(roomId, bookingUid, action)
        showSuccess(action === 'accept' ? t('roomvox', 'Booking accepted') : t('roomvox', 'Booking declined'))
        approvals.value = approvals.value.filter(b => b.uid !== bookingUid)
    } catch (e) {
        showError(t('roomvox', 'Failed to process response'))
    } finally {
        responding.value = null
    }
}

const loadRooms = async () => {
    loadingRooms.value = true
    try {
        const response = await getMyRooms()
        rooms.value = response.data
    } catch (e) {
        showError(t('roomvox', 'Failed to load rooms'))
    } finally {
        loadingRooms.value = false
    }
}

const loadApprovals = async () => {
    loadingApprovals.value = true
    try {
        const response = await getMyApprovals()
        approvals.value = response.data
    } catch (e) {
        showError(t('roomvox', 'Failed to load approvals'))
    } finally {
        loadingApprovals.value = false
    }
}

onMounted(() => {
    loadRooms()
    loadApprovals()
})
</script>

<style scoped>
.roomvox-personal {
    padding: 20px;
}

.tab-navigation {
    border-bottom: 1px solid var(--color-border);
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.tab-button {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    border: none;
    background: none;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    color: var(--color-text-lighter);
    font-size: 14px;
    transition: all 0.2s ease;
}

.tab-button:hover:not(.active) {
    background: var(--color-background-hover);
}

.tab-button.active {
    border-bottom-color: var(--color-primary);
    color: var(--color-primary);
    background: var(--color-primary-element-light);
}

.tab-content {
    animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}

.loading-state {
    display: flex;
    justify-content: center;
    padding: 48px 0;
}

.rooms-table,
.approvals-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.rooms-table th,
.approvals-table th {
    text-align: left;
    padding: 10px 12px;
    font-weight: 600;
    border-bottom: 2px solid var(--color-border);
    white-space: nowrap;
}

.rooms-table td,
.approvals-table td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--color-border);
}

.room-name {
    font-weight: 500;
}

.when-date {
    font-weight: 500;
}

.when-time {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}

.booking-actions {
    display: flex;
    gap: 4px;
}
</style>
