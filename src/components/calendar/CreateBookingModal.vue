<template>
    <NcModal :name="$t('Create Booking')" @close="$emit('close')">
        <div class="create-booking-modal">
            <h2>{{ $t('New Booking') }}</h2>

            <form @submit.prevent="handleSubmit">
                <div class="form-group">
                    <label for="booking-summary">{{ $t('Title') }} *</label>
                    <NcTextField
                        id="booking-summary"
                        v-model="form.summary"
                        :placeholder="$t('Meeting name')"
                        required />
                </div>

                <div class="form-group">
                    <label for="booking-room">{{ $t('Room') }} *</label>
                    <NcSelect
                        v-model="selectedRoom"
                        :options="roomOptions"
                        :placeholder="$t('Select a room')"
                        label="label"
                        :reduce="option => option.value" />
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="booking-date">{{ $t('Date') }} *</label>
                        <NcDateTimePicker
                            id="booking-date"
                            v-model="form.date"
                            type="date"
                            :placeholder="$t('Select date')" />
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="booking-start">{{ $t('Start time') }} *</label>
                        <NcDateTimePicker
                            id="booking-start"
                            v-model="form.startTime"
                            type="time"
                            :minute-step="15"
                            :placeholder="$t('Start time')" />
                    </div>
                    <div class="form-group">
                        <label for="booking-end">{{ $t('End time') }} *</label>
                        <NcDateTimePicker
                            id="booking-end"
                            v-model="form.endTime"
                            type="time"
                            :minute-step="15"
                            :placeholder="$t('End time')" />
                    </div>
                </div>

                <div class="form-group">
                    <label for="booking-description">{{ $t('Description') }}</label>
                    <NcTextArea
                        id="booking-description"
                        v-model="form.description"
                        :placeholder="$t('Optional description')"
                        :rows="3" />
                </div>

                <div v-if="error" class="error-message">
                    {{ error }}
                </div>

                <div class="modal-actions">
                    <NcButton type="tertiary" @click="$emit('close')">
                        {{ $t('Cancel') }}
                    </NcButton>
                    <NcButton
                        type="primary"
                        native-type="submit"
                        :disabled="!isValid || loading">
                        <template v-if="loading">
                            <NcLoadingIcon :size="20" />
                        </template>
                        {{ $t('Create Booking') }}
                    </NcButton>
                </div>
            </form>
        </div>
    </NcModal>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { translate } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'

import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcDateTimePicker from '@nextcloud/vue/components/NcDateTimePicker'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import { createBooking } from '../../services/api.js'

const t = (text, vars = {}) => translate('roomvox', text, vars)

const props = defineProps({
    roomId: { type: String, default: null },
    start: { type: Date, default: null },
    end: { type: Date, default: null },
    rooms: { type: Array, default: () => [] },
})

const emit = defineEmits(['close', 'created'])

const loading = ref(false)
const error = ref('')
const selectedRoom = ref(props.roomId)

const form = ref({
    summary: '',
    date: props.start ? new Date(props.start) : new Date(),
    startTime: props.start ? new Date(props.start) : null,
    endTime: props.end ? new Date(props.end) : null,
    description: '',
})

// Room options for select
const roomOptions = computed(() => {
    return props.rooms.map(room => ({
        value: room.id,
        label: room.name,
    }))
})

// Validation
const isValid = computed(() => {
    return (
        form.value.summary.trim() !== '' &&
        selectedRoom.value !== null &&
        form.value.date !== null &&
        form.value.startTime !== null &&
        form.value.endTime !== null
    )
})

// Initialize times from props
onMounted(() => {
    if (props.start) {
        form.value.date = new Date(props.start)
        form.value.startTime = new Date(props.start)
    }
    if (props.end) {
        form.value.endTime = new Date(props.end)
    }
    if (props.roomId) {
        selectedRoom.value = props.roomId
    }
})

// Combine date and time into ISO string
function combineDateTime(date, time) {
    if (!date || !time) return null

    const result = new Date(date)
    const timeDate = new Date(time)

    result.setHours(timeDate.getHours())
    result.setMinutes(timeDate.getMinutes())
    result.setSeconds(0)
    result.setMilliseconds(0)

    return result.toISOString()
}

async function handleSubmit() {
    if (!isValid.value) return

    error.value = ''
    loading.value = true

    try {
        const startISO = combineDateTime(form.value.date, form.value.startTime)
        const endISO = combineDateTime(form.value.date, form.value.endTime)

        if (!startISO || !endISO) {
            error.value = t('Invalid date/time selection')
            return
        }

        // Validate end is after start
        if (new Date(endISO) <= new Date(startISO)) {
            error.value = t('End time must be after start time')
            return
        }

        await createBooking(selectedRoom.value, {
            summary: form.value.summary.trim(),
            start: startISO,
            end: endISO,
            description: form.value.description.trim(),
        })

        showSuccess(t('Booking created successfully'))
        emit('created')
    } catch (e) {
        const message = e.response?.data?.error || 'Failed to create booking'
        error.value = message
        showError(message)
    } finally {
        loading.value = false
    }
}
</script>

<style scoped>
.create-booking-modal {
    padding: 20px;
    min-width: 400px;
}

.create-booking-modal h2 {
    margin: 0 0 20px;
    font-size: 18px;
    font-weight: 600;
}

.form-group {
    margin-bottom: 16px;
}

.form-group label {
    display: block;
    margin-bottom: 4px;
    font-weight: 500;
    color: var(--color-text-maxcontrast);
}

.form-row {
    display: flex;
    gap: 16px;
}

.form-row .form-group {
    flex: 1;
}

.error-message {
    padding: 8px 12px;
    margin-bottom: 16px;
    background: var(--color-error-light, rgba(200, 0, 0, 0.1));
    color: var(--color-error);
    border-radius: var(--border-radius);
    font-size: 14px;
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid var(--color-border);
}

:deep(.mx-datepicker) {
    width: 100%;
}

:deep(.vs__dropdown-toggle) {
    min-height: 44px;
}
</style>
