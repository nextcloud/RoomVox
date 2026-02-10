<template>
    <div class="room-editor">
        <div class="room-editor__header">
            <NcButton type="tertiary" @click="$emit('cancel')">
                <template #icon>
                    <ArrowLeft :size="20" />
                </template>
                {{ $t('Back') }}
            </NcButton>
            <h2>{{ creating ? $t('New Room') : $t('Edit Room') }}</h2>
        </div>

        <div class="room-editor__form">
            <div class="form-section">
                <h3>{{ $t('General') }}</h3>
                <div class="form-grid">
                    <NcTextField
                        :label="$t('Room name')"
                        v-model="form.name"
                        :placeholder="$t('e.g. Meeting Room 1')"
                        :error="!!errors.name"
                        :helper-text="errors.name"
                        required
                        @update:model-value="clearError('name')" />
                    <NcTextField
                        :label="$t('Email address')"
                        v-model="form.email"
                        :placeholder="$t('e.g. room1@company.com')"
                        :error="!!errors.email"
                        :helper-text="errors.email"
                        type="email"
                        @update:model-value="clearError('email')" />
                    <NcTextField
                        :label="$t('Location')"
                        v-model="form.location"
                        :placeholder="$t('e.g. Building A, Floor 2')" />
                    <NcTextField
                        :label="$t('Capacity')"
                        v-model="form.capacity"
                        :error="!!errors.capacity"
                        :helper-text="errors.capacity"
                        type="number"
                        :placeholder="$t('Number of seats')"
                        @update:model-value="clearError('capacity')" />
                </div>

                <div class="form-field">
                    <NcTextArea
                        :label="$t('Description')"
                        v-model="form.description"
                        :placeholder="$t('Optional room description')"
                        resize="vertical" />
                </div>

                <div class="form-field">
                    <label>{{ $t('Facilities') }}</label>
                    <div class="facilities-grid">
                        <NcCheckboxRadioSwitch
                            v-for="facility in availableFacilities"
                            :key="facility.id"
                            :model-value="form.facilities.includes(facility.id)"
                            @update:model-value="toggleFacility(facility.id, $event)">
                            {{ facility.label }}
                        </NcCheckboxRadioSwitch>
                    </div>
                </div>

                <div class="form-field">
                    <NcCheckboxRadioSwitch :model-value="form.autoAccept" @update:model-value="form.autoAccept = $event">
                        {{ $t('Auto-accept bookings (no manual approval required)') }}
                    </NcCheckboxRadioSwitch>
                </div>

                <div v-if="!creating" class="form-field">
                    <NcCheckboxRadioSwitch :model-value="form.active" @update:model-value="form.active = $event">
                        {{ $t('Room is active and bookable') }}
                    </NcCheckboxRadioSwitch>
                </div>

                <div v-if="roomGroups.length > 0" class="form-field">
                    <label>{{ $t('Room Group') }}</label>
                    <NcSelect
                        :model-value="groupOptions.find(o => o.id === form.groupId) || groupOptions[0]"
                        :options="groupOptions"
                        label="label"
                        :clearable="false"
                        @update:model-value="form.groupId = $event?.id || null" />
                </div>
            </div>

            <div class="form-section">
                <h3>{{ $t('Availability') }}</h3>
                <p class="section-description">
                    {{ $t('Restrict when this room can be booked. Bookings outside these hours will be automatically declined.') }}
                </p>

                <div class="form-field">
                    <NcCheckboxRadioSwitch
                        :model-value="availability.enabled"
                        @update:model-value="availability.enabled = $event">
                        {{ $t('Restrict booking hours') }}
                    </NcCheckboxRadioSwitch>
                </div>

                <div v-if="availability.enabled">
                    <div v-for="(rule, ruleIndex) in availability.rules"
                         :key="ruleIndex"
                         class="availability-rule">
                        <div class="rule-header">
                            <span class="rule-label">{{ $t('Rule') }} {{ ruleIndex + 1 }}</span>
                            <NcButton v-if="availability.rules.length > 1"
                                      type="tertiary"
                                      @click="removeRule(ruleIndex)">
                                <template #icon>
                                    <Close :size="20" />
                                </template>
                            </NcButton>
                        </div>

                        <div class="form-field">
                            <label>{{ $t('Days') }}</label>
                            <div class="days-grid">
                                <NcCheckboxRadioSwitch
                                    v-for="day in weekDays"
                                    :key="day.value"
                                    :model-value="rule.days.includes(day.value)"
                                    @update:model-value="toggleDay(ruleIndex, day.value, $event)">
                                    {{ day.label }}
                                </NcCheckboxRadioSwitch>
                            </div>
                        </div>

                        <div class="time-grid">
                            <div class="form-field">
                                <label>{{ $t('From') }}</label>
                                <input type="time" v-model="rule.startTime" class="time-input" />
                            </div>
                            <div class="form-field">
                                <label>{{ $t('To') }}</label>
                                <input type="time" v-model="rule.endTime" class="time-input" />
                            </div>
                        </div>
                    </div>

                    <div class="availability-actions">
                        <NcButton type="secondary" @click="addRule">
                            {{ $t('+ Add Rule') }}
                        </NcButton>
                    </div>

                    <div class="availability-presets">
                        <span class="presets-label">{{ $t('Presets:') }}</span>
                        <NcButton type="tertiary" @click="applyPreset([1,2,3,4,5], '08:00', '18:00')">
                            {{ $t('Weekdays 08-18') }}
                        </NcButton>
                        <NcButton type="tertiary" @click="applyPreset([1,2,3,4,5], '09:00', '17:00')">
                            {{ $t('Weekdays 09-17') }}
                        </NcButton>
                    </div>
                </div>

                <div class="form-field horizon-field">
                    <label>{{ $t('Maximum booking horizon') }}</label>
                    <p class="section-description">
                        {{ $t('Limit how far in advance bookings can be made. Recurring events that extend beyond this limit will be declined. Set to 0 for no limit.') }}
                    </p>
                    <div class="horizon-input">
                        <NcTextField
                            v-model="form.maxBookingHorizon"
                            type="number"
                            :placeholder="$t('e.g. 90')"
                            min="0" />
                        <span class="horizon-unit">{{ $t('days') }}</span>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>{{ $t('SMTP Configuration') }}</h3>
                <p class="section-description">
                    {{ $t('Optional: configure a dedicated SMTP server for this room. If empty, the global Nextcloud mail configuration is used.') }}
                </p>
                <div class="form-grid">
                    <NcTextField
                        :label="$t('SMTP Host')"
                        v-model="smtp.host"
                        :placeholder="$t('e.g. smtp.company.com')" />
                    <NcTextField
                        :label="$t('SMTP Port')"
                        v-model="smtp.port"
                        :error="!!errors.smtpPort"
                        :helper-text="errors.smtpPort"
                        type="number"
                        placeholder="587"
                        @update:model-value="clearError('smtpPort')" />
                    <NcTextField
                        :label="$t('Username')"
                        v-model="smtp.username"
                        :placeholder="$t('SMTP username')" />
                    <NcPasswordField
                        :label="$t('Password')"
                        v-model="smtp.password"
                        :placeholder="creating ? '' : $t('Leave empty to keep current')" />
                </div>
                <div class="form-field">
                    <label>{{ $t('Encryption') }}</label>
                    <div class="encryption-options">
                        <NcCheckboxRadioSwitch
                            :model-value="smtp.encryption === 'tls'"
                            type="radio"
                            name="encryption"
                            @update:model-value="smtp.encryption = 'tls'">
                            TLS
                        </NcCheckboxRadioSwitch>
                        <NcCheckboxRadioSwitch
                            :model-value="smtp.encryption === 'ssl'"
                            type="radio"
                            name="encryption"
                            @update:model-value="smtp.encryption = 'ssl'">
                            SSL
                        </NcCheckboxRadioSwitch>
                        <NcCheckboxRadioSwitch
                            :model-value="smtp.encryption === 'none'"
                            type="radio"
                            name="encryption"
                            @update:model-value="smtp.encryption = 'none'">
                            {{ $t('None') }}
                        </NcCheckboxRadioSwitch>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <NcButton type="primary" @click="save">
                    {{ creating ? $t('Create Room') : $t('Save Changes') }}
                </NcButton>
                <NcButton type="secondary" @click="$emit('cancel')">
                    {{ $t('Cancel') }}
                </NcButton>
                <NcButton
                    v-if="!creating"
                    type="secondary"
                    @click="$emit('manage-permissions', room)">
                    {{ $t('Manage Permissions') }}
                </NcButton>
                <NcButton
                    v-if="!creating"
                    type="error"
                    @click="showDeleteDialog = true">
                    {{ $t('Delete Room') }}
                </NcButton>
            </div>
        </div>

        <NcDialog
            v-if="showDeleteDialog"
            :name="$t('Delete Room')"
            @closing="showDeleteDialog = false">
            <p>{{ $t('Are you sure you want to delete this room? This action cannot be undone.') }}</p>
            <template #actions>
                <NcButton type="secondary" @click="showDeleteDialog = false">
                    {{ $t('Cancel') }}
                </NcButton>
                <NcButton type="error" @click="showDeleteDialog = false; $emit('delete', room.id)">
                    {{ $t('Delete') }}
                </NcButton>
            </template>
        </NcDialog>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Close from 'vue-material-design-icons/Close.vue'

const props = defineProps({
    room: { type: Object, default: null },
    creating: { type: Boolean, default: false },
    roomGroups: { type: Array, default: () => [] },
})

const emit = defineEmits(['save', 'cancel', 'delete', 'manage-permissions'])

const showDeleteDialog = ref(false)

const availableFacilities = [
    { id: 'projector', label: 'Projector' },
    { id: 'whiteboard', label: 'Whiteboard' },
    { id: 'videoconf', label: 'Video conference' },
    { id: 'audio', label: 'Audio system' },
    { id: 'display', label: 'Display screen' },
    { id: 'wheelchair', label: 'Wheelchair accessible' },
]

const weekDays = [
    { value: 1, label: 'Mon' },
    { value: 2, label: 'Tue' },
    { value: 3, label: 'Wed' },
    { value: 4, label: 'Thu' },
    { value: 5, label: 'Fri' },
    { value: 6, label: 'Sat' },
    { value: 0, label: 'Sun' },
]

const groupOptions = computed(() => {
    return [
        { id: null, label: '— No group —' },
        ...props.roomGroups.map(g => ({ id: g.id, label: g.name })),
    ]
})

const form = reactive({
    name: '',
    email: '',
    description: '',
    capacity: 0,
    location: '',
    facilities: [],
    autoAccept: false,
    active: true,
    groupId: null,
    maxBookingHorizon: 0,
})

const smtp = reactive({
    host: '',
    port: 587,
    username: '',
    password: '',
    encryption: 'tls',
})

const availability = reactive({
    enabled: false,
    rules: [],
})

const errors = reactive({
    name: '',
    email: '',
    capacity: '',
    smtpPort: '',
})

const clearError = (field) => {
    errors[field] = ''
}

const validate = () => {
    let valid = true

    if (!form.name.trim()) {
        errors.name = 'Room name is required'
        valid = false
    }

    if (form.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) {
        errors.email = 'Invalid email address'
        valid = false
    }

    if (form.capacity < 0) {
        errors.capacity = 'Capacity cannot be negative'
        valid = false
    }

    const port = Number(smtp.port)
    if (smtp.host && (port < 1 || port > 65535)) {
        errors.smtpPort = 'Port must be between 1 and 65535'
        valid = false
    }

    return valid
}

// Initialize form from room data
watch(() => props.room, (room) => {
    if (room) {
        Object.assign(form, {
            name: room.name || '',
            email: room.email || '',
            description: room.description || '',
            capacity: room.capacity || 0,
            location: room.location || '',
            facilities: room.facilities || [],
            autoAccept: room.autoAccept || false,
            active: room.active !== false,
            groupId: room.groupId || null,
            maxBookingHorizon: room.maxBookingHorizon || 0,
        })
        if (room.smtpConfig) {
            Object.assign(smtp, {
                host: room.smtpConfig.host || '',
                port: room.smtpConfig.port || 587,
                username: room.smtpConfig.username || '',
                password: '',
                encryption: room.smtpConfig.encryption || 'tls',
            })
        }
        if (room.availabilityRules) {
            availability.enabled = room.availabilityRules.enabled || false
            availability.rules = (room.availabilityRules.rules || []).map(r => ({
                days: [...(r.days || [])],
                startTime: r.startTime || '08:00',
                endTime: r.endTime || '18:00',
            }))
        }
    }
}, { immediate: true })

const toggleFacility = (facilityId, checked) => {
    if (checked) {
        if (!form.facilities.includes(facilityId)) {
            form.facilities.push(facilityId)
        }
    } else {
        form.facilities = form.facilities.filter(f => f !== facilityId)
    }
}

const toggleDay = (ruleIndex, day, checked) => {
    const rule = availability.rules[ruleIndex]
    if (checked) {
        if (!rule.days.includes(day)) {
            rule.days.push(day)
        }
    } else {
        rule.days = rule.days.filter(d => d !== day)
    }
}

const addRule = () => {
    availability.rules.push({ days: [], startTime: '08:00', endTime: '18:00' })
}

const removeRule = (index) => {
    availability.rules.splice(index, 1)
}

const applyPreset = (days, startTime, endTime) => {
    availability.enabled = true
    availability.rules = [{ days: [...days], startTime, endTime }]
}

const save = () => {
    if (!validate()) return

    const data = { ...form }

    // Include availability rules
    data.availabilityRules = {
        enabled: availability.enabled,
        rules: availability.rules.map(r => ({
            days: [...r.days],
            startTime: r.startTime,
            endTime: r.endTime,
        })),
    }

    // Only include SMTP config if something is filled in
    if (smtp.host) {
        data.smtpConfig = { ...smtp }
        // Don't send empty password on update (keep existing)
        if (!props.creating && !smtp.password) {
            delete data.smtpConfig.password
        }
    } else {
        data.smtpConfig = null
    }

    emit('save', data)
}
</script>

<style scoped>
.room-editor__header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 24px;
}

.room-editor__header h2 {
    font-size: 20px;
    font-weight: 700;
}

.form-section {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 24px;
    margin-bottom: 16px;
}

.form-section h3 {
    font-size: 17px;
    font-weight: 700;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--color-border);
    margin-bottom: 16px;
}

.section-description {
    color: var(--color-text-maxcontrast);
    margin-top: -8px;
    margin-bottom: 16px;
    font-size: 13px;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}

.form-field {
    margin-bottom: 16px;
}

.form-field label {
    display: block;
    font-weight: 500;
    margin-bottom: 4px;
    font-size: 14px;
}

.facilities-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 4px;
}

.encryption-options {
    display: flex;
    gap: 16px;
}

.availability-rule {
    background: var(--color-background-dark);
    border-radius: var(--border-radius-large);
    padding: 16px;
    margin-bottom: 12px;
}

.rule-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}

.rule-label {
    font-weight: 600;
    font-size: 14px;
}

.days-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.time-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.time-input {
    width: 100%;
    padding: 8px 12px;
    border: 2px solid var(--color-border-maxcontrast);
    border-radius: var(--border-radius-large);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 14px;
}

.time-input:focus {
    border-color: var(--color-primary-element);
    outline: none;
}

.availability-actions {
    margin-bottom: 12px;
}

.availability-presets {
    display: flex;
    align-items: center;
    gap: 4px;
}

.presets-label {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.horizon-field {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--color-border);
}

.horizon-input {
    display: flex;
    align-items: center;
    gap: 8px;
    max-width: 200px;
}

.horizon-unit {
    font-size: 14px;
    color: var(--color-text-maxcontrast);
    white-space: nowrap;
}

.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 4px;
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }

    .facilities-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 480px) {
    .facilities-grid {
        grid-template-columns: 1fr;
    }

    .form-actions {
        flex-direction: column;
    }
}
</style>
