<template>
    <NcModal :name="// TRANSLATORS Dialog title
        t('roomvox', 'Booking details')" @close="$emit('close')">
        <div class="event-details-modal">
            <div class="event-header">
                <h2>{{ booking.summary || t('roomvox', 'Unnamed event') }}</h2>
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
                        <!-- TRANSLATORS Button: accept a booking request (verb) -->
                        {{ t('roomvox', 'Accept') }}
                    </NcButton>
                    <NcButton
                        type="error"
                        :disabled="loading"
                        @click="handleRespond('decline')">
                        <template #icon>
                            <CloseIcon :size="20" />
                        </template>
                        <!-- TRANSLATORS Button: decline a booking request (verb) -->
                        {{ t('roomvox', 'Decline') }}
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
                    <!-- TRANSLATORS Button and tooltip; Calendar is the Nextcloud Calendar app -->
                    {{ t('roomvox', 'Open in Calendar') }}
                </NcButton>

                <!-- Delete button -->
                <NcButton
                    type="tertiary"
                    :disabled="loading"
                    @click="confirmDelete">
                    <template #icon>
                        <DeleteIcon :size="20" />
                    </template>
                    <!-- TRANSLATORS Button, dialog title and confirmation: cancel a booking (verb) -->
                    {{ t('roomvox', 'Cancel booking') }}
                </NcButton>

                <div class="spacer" />

                <NcButton type="tertiary" @click="$emit('close')">
                    <!-- TRANSLATORS Button: close the dialog -->
                    {{ t('roomvox', 'Close') }}
                </NcButton>
            </div>
        </div>

        <NcDialog
            v-if="showDeleteDialog"
            :name="t('roomvox', 'Cancel booking')"
            :open="showDeleteDialog"
            @close="showDeleteDialog = false">
            <template v-if="booking.recurrenceId">
                <p>{{ t('roomvox', 'This booking is part of a recurring series. What would you like to cancel?') }}</p>
                <p>{{ t('roomvox', 'The booker will be notified by email.') }}</p>
            </template>
            <template v-else>
                <p>{{ t('roomvox', 'Are you sure you want to cancel this booking? The booker will be notified by email.') }}</p>
            </template>
            <template #actions>
                <!-- TRANSLATORS Button in the cancel dialog: keep the booking, do not cancel it -->
                <NcButton type="tertiary" @click="showDeleteDialog = false">{{ t('roomvox', 'Keep') }}</NcButton>
                <template v-if="booking.recurrenceId">
                    <NcButton type="warning" :disabled="loading" @click="executeDelete('occurrence')">
                        <!-- TRANSLATORS Choice in the cancel dialog: cancel only this date of a recurring booking -->
                        {{ t('roomvox', 'This occurrence') }}
                    </NcButton>
                    <NcButton type="error" :disabled="loading" @click="executeDelete('series')">
                        <!-- TRANSLATORS Choice in the cancel dialog: cancel all dates of a recurring booking -->
                        {{ t('roomvox', 'Entire series') }}
                    </NcButton>
                </template>
                <NcButton v-else type="error" :disabled="loading" @click="executeDelete('series')">
                    {{ t('roomvox', 'Cancel booking') }}
                </NcButton>
            </template>
        </NcDialog>
    </NcModal>
</template>

<script setup>
import { computed, ref } from 'vue'
import { getLanguage } from '@nextcloud/l10n'
import { t } from '../../l10n.js'
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

const ncLocale = getLanguage().replace('_', '-')

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
        // TRANSLATORS Booking status
        case 'ACCEPTED': return t('roomvox', 'Accepted')
        // TRANSLATORS Booking status: waiting for approval
        case 'TENTATIVE': return t('roomvox', 'Pending')
        // TRANSLATORS Booking status
        case 'DECLINED': return t('roomvox', 'Declined')
        // TRANSLATORS Booking status when the server reports none
        default: return props.booking.partstat || t('roomvox', 'Unknown')
    }
})

/**
 * All-day bookings arrive as a bare "YYYY-MM-DD", which `new Date()` reads as
 * UTC midnight — west of UTC that renders as the previous day. Build those as
 * a local date instead (issue #27).
 */
const parseBookingDate = (dateStr) => {
    const dateOnly = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dateStr)
    if (dateOnly) {
        return new Date(Number(dateOnly[1]), Number(dateOnly[2]) - 1, Number(dateOnly[3]))
    }
    return new Date(dateStr)
}

// Date formatting
const formattedDate = computed(() => {
    if (!props.booking.dtstart) return ''
    const date = parseBookingDate(props.booking.dtstart)
    return date.toLocaleDateString(ncLocale, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    })
})

const formattedTime = computed(() => {
    // TRANSLATORS Shown instead of a time for a booking that lasts all day
    if (props.booking.allDay) return t('roomvox', 'All day')
    if (!props.booking.dtstart || !props.booking.dtend) return ''
    const start = new Date(props.booking.dtstart)
    const end = new Date(props.booking.dtend)
    const startTime = start.toLocaleTimeString(ncLocale, { hour: '2-digit', minute: '2-digit' })
    const endTime = end.toLocaleTimeString(ncLocale, { hour: '2-digit', minute: '2-digit' })
    return `${startTime} - ${endTime}`
})

// Calendar link
const calendarLink = computed(() => {
    if (!props.booking.dtstart) return '#'
    // Format from the local date parts: toISOString() would convert back to
    // UTC and can land on the wrong day.
    const date = parseBookingDate(props.booking.dtstart)
    const dateStr = [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-')
    return generateUrl(`/apps/calendar/dayGridMonth/${dateStr}`)
})

// Actions
async function handleRespond(action) {
    loading.value = true
    try {
        await respondToBooking(props.booking.roomId, props.booking.uid, action)
        showSuccess(action === 'accept'
            // TRANSLATORS Confirmation message
            ? t('roomvox', 'Booking accepted')
            // TRANSLATORS Confirmation message
            : t('roomvox', 'Booking declined'))
        emit('updated')
    } catch (e) {
        showError(t('roomvox', 'Failed to respond to booking'))
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
        showSuccess(mode === 'occurrence'
            // TRANSLATORS Confirmation message: one date of a recurring booking was canceled
            ? t('roomvox', 'Occurrence canceled')
            // TRANSLATORS Confirmation message
            : t('roomvox', 'Booking canceled'))
        showDeleteDialog.value = false
        emit('deleted')
    } catch (e) {
        showError(t('roomvox', 'Failed to cancel booking'))
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
}

/* --color-success / --color-warning are already the light badge backgrounds in
   Nextcloud's palette, paired with the -text variant for the foreground. Both
   flip with the theme, so no dark-mode override is needed. */
.status-accepted {
    background: var(--color-success);
    color: var(--color-success-text);
}

.status-pending {
    background: var(--color-warning);
    color: var(--color-warning-text);
}

.status-declined {
    background: var(--color-background-dark);
    color: var(--color-text-maxcontrast);
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

/* No dark-theme overrides needed: the status colours above are Nextcloud
   theme tokens, which already carry the right value per theme. */

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
