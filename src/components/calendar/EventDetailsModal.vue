<template>
    <NcModal :name="$t('Booking Details')" @close="$emit('close')">
        <div class="event-details-modal">
            <div class="event-header">
                <h2>{{ booking.summary || $t('Unnamed event') }}</h2>
                <span :class="['status-badge', statusClass]">
                    {{ statusLabel }}
                </span>
            </div>

            <div class="event-info">
                <div class="info-row">
                    <CalendarIcon :size="20" />
                    <div>
                        <strong>{{ formattedDate }}</strong>
                        <span class="time">{{ formattedTime }}</span>
                    </div>
                </div>

                <div v-if="booking.roomName" class="info-row">
                    <HomeIcon :size="20" />
                    <span>{{ booking.roomName }}</span>
                </div>

                <div v-if="booking.organizerName || booking.organizer" class="info-row">
                    <AccountIcon :size="20" />
                    <span>{{ booking.organizerName || booking.organizer }}</span>
                </div>

                <div v-if="booking.description" class="info-row description">
                    <TextIcon :size="20" />
                    <p>{{ booking.description }}</p>
                </div>
            </div>

            <div class="modal-actions">
                <!-- Pending booking actions -->
                <template v-if="isPending">
                    <NcButton
                        type="success"
                        :disabled="loading"
                        @click="handleRespond('accept')">
                        <template #icon>
                            <CheckIcon :size="20" />
                        </template>
                        {{ $t('Accept') }}
                    </NcButton>
                    <NcButton
                        type="error"
                        :disabled="loading"
                        @click="handleRespond('decline')">
                        <template #icon>
                            <CloseIcon :size="20" />
                        </template>
                        {{ $t('Decline') }}
                    </NcButton>
                </template>

                <!-- Open in Calendar link -->
                <NcButton
                    type="tertiary"
                    :href="calendarLink"
                    target="_blank">
                    <template #icon>
                        <OpenInNewIcon :size="20" />
                    </template>
                    {{ $t('Open in Calendar') }}
                </NcButton>

                <!-- Delete button -->
                <NcButton
                    type="tertiary"
                    :disabled="loading"
                    @click="confirmDelete">
                    <template #icon>
                        <DeleteIcon :size="20" />
                    </template>
                    {{ $t('Cancel booking') }}
                </NcButton>

                <div class="spacer" />

                <NcButton type="tertiary" @click="$emit('close')">
                    {{ $t('Close') }}
                </NcButton>
            </div>
        </div>

        <NcDialog
            v-if="showDeleteDialog"
            :name="$t('Cancel booking')"
            :open="showDeleteDialog"
            @close="showDeleteDialog = false">
            <template v-if="booking.recurrenceId">
                <p>{{ $t('This booking is part of a recurring series. What would you like to cancel?') }}</p>
                <p>{{ $t('The booker will be notified by email.') }}</p>
            </template>
            <template v-else>
                <p>{{ $t('Are you sure you want to cancel this booking? The booker will be notified by email.') }}</p>
            </template>
            <template #actions>
                <NcButton type="tertiary" @click="showDeleteDialog = false">{{ $t('Keep') }}</NcButton>
                <template v-if="booking.recurrenceId">
                    <NcButton type="warning" :disabled="loading" @click="executeDelete('occurrence')">
                        {{ $t('This occurrence') }}
                    </NcButton>
                    <NcButton type="error" :disabled="loading" @click="executeDelete('series')">
                        {{ $t('Entire series') }}
                    </NcButton>
                </template>
                <NcButton v-else type="error" :disabled="loading" @click="executeDelete('series')">
                    {{ $t('Cancel booking') }}
                </NcButton>
            </template>
        </NcDialog>
    </NcModal>
</template>

<script setup>
import { computed, ref } from 'vue'
import { translate } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'

import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import TextIcon from 'vue-material-design-icons/Text.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'

import { respondToBooking, deleteBooking } from '../../services/api.js'

const t = (text, vars = {}) => translate('roomvox', text, vars)

const props = defineProps({
    booking: { type: Object, required: true },
})

const emit = defineEmits(['close', 'updated', 'deleted'])

const loading = ref(false)
const showDeleteDialog = ref(false)

// Status helpers
const isPending = computed(() => props.booking.partstat === 'TENTATIVE')

const statusClass = computed(() => {
    switch (props.booking.partstat) {
        case 'ACCEPTED': return 'status-accepted'
        case 'TENTATIVE': return 'status-pending'
        case 'DECLINED': return 'status-declined'
        default: return ''
    }
})

const statusLabel = computed(() => {
    switch (props.booking.partstat) {
        case 'ACCEPTED': return t('Accepted')
        case 'TENTATIVE': return t('Pending')
        case 'DECLINED': return t('Declined')
        default: return props.booking.partstat || t('Unknown')
    }
})

// Date formatting
const formattedDate = computed(() => {
    if (!props.booking.dtstart) return ''
    const date = new Date(props.booking.dtstart)
    return date.toLocaleDateString(undefined, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    })
})

const formattedTime = computed(() => {
    if (!props.booking.dtstart || !props.booking.dtend) return ''
    const start = new Date(props.booking.dtstart)
    const end = new Date(props.booking.dtend)
    const startTime = start.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
    const endTime = end.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
    return `${startTime} - ${endTime}`
})

// Calendar link
const calendarLink = computed(() => {
    if (!props.booking.dtstart) return '#'
    const date = new Date(props.booking.dtstart)
    const dateStr = date.toISOString().split('T')[0]
    return generateUrl(`/apps/calendar/dayGridMonth/${dateStr}`)
})

// Actions
async function handleRespond(action) {
    loading.value = true
    try {
        await respondToBooking(props.booking.roomId, props.booking.uid, action)
        showSuccess(action === 'accept' ? t('Booking accepted') : t('Booking declined'))
        emit('updated')
    } catch (e) {
        showError(t('Failed to respond to booking'))
    } finally {
        loading.value = false
    }
}

function confirmDelete() {
    showDeleteDialog.value = true
}

async function executeDelete(mode) {
    const recurrenceId = mode === 'occurrence' ? props.booking.recurrenceId : null
    loading.value = true
    try {
        await deleteBooking(props.booking.roomId, props.booking.uid, recurrenceId)
        showSuccess(mode === 'occurrence' ? t('Occurrence cancelled') : t('Booking cancelled'))
        showDeleteDialog.value = false
        emit('deleted')
    } catch (e) {
        showError(t('Failed to cancel booking'))
    } finally {
        loading.value = false
    }
}
</script>

<style scoped>
.event-details-modal {
    padding: 20px;
    min-width: 400px;
}

.event-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
}

.event-header h2 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    flex: 1;
}

.status-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
}

.status-accepted {
    background: rgba(70, 186, 97, 0.15);
    color: #2d7b43;
}

.status-pending {
    background: rgba(255, 193, 7, 0.15);
    color: #8a6d3b;
}

.status-declined {
    background: rgba(200, 200, 200, 0.3);
    color: #666;
}

.event-info {
    margin-bottom: 20px;
}

.info-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 8px 0;
    border-bottom: 1px solid var(--color-border-dark);
}

.info-row:last-child {
    border-bottom: none;
}

.info-row svg,
.info-row :deep(.material-design-icon) {
    color: var(--color-text-maxcontrast);
    flex-shrink: 0;
    margin-top: 2px;
}

.info-row .time {
    display: block;
    color: var(--color-text-maxcontrast);
    font-size: 14px;
}

.info-row.description p {
    margin: 0;
    white-space: pre-wrap;
    color: var(--color-text-lighter);
}

.modal-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding-top: 16px;
    border-top: 1px solid var(--color-border);
}

.spacer {
    flex: 1;
}

/* Dark theme support */
[data-themes*="dark"] .status-accepted,
.theme--dark .status-accepted {
    color: #6dd38d;
}

[data-themes*="dark"] .status-pending,
.theme--dark .status-pending {
    color: #ffd54f;
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
