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
                        required />
                    <NcTextField
                        :label="$t('Email address')"
                        v-model="form.email"
                        :placeholder="$t('e.g. room1@company.com')"
                        type="email" />
                    <NcTextField
                        :label="$t('Location')"
                        v-model="form.location"
                        :placeholder="$t('e.g. Building A, Floor 2')" />
                    <NcTextField
                        :label="$t('Capacity')"
                        v-model="form.capacity"
                        type="number"
                        :placeholder="$t('Number of seats')" />
                </div>

                <div class="form-field">
                    <label>{{ $t('Description') }}</label>
                    <textarea
                        v-model="form.description"
                        :placeholder="$t('Optional room description')"
                        rows="3" />
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
                        type="number"
                        placeholder="587" />
                    <NcTextField
                        :label="$t('Username')"
                        v-model="smtp.username"
                        :placeholder="$t('SMTP username')" />
                    <NcTextField
                        :label="$t('Password')"
                        v-model="smtp.password"
                        type="password"
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
                <NcButton type="primary" @click="save" :disabled="!form.name">
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
                    @click="confirmDelete">
                    {{ $t('Delete Room') }}
                </NcButton>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'

const props = defineProps({
    room: { type: Object, default: null },
    creating: { type: Boolean, default: false },
})

const emit = defineEmits(['save', 'cancel', 'delete', 'manage-permissions'])

const availableFacilities = [
    { id: 'projector', label: 'Projector' },
    { id: 'whiteboard', label: 'Whiteboard' },
    { id: 'videoconf', label: 'Video conference' },
    { id: 'audio', label: 'Audio system' },
    { id: 'display', label: 'Display screen' },
    { id: 'wheelchair', label: 'Wheelchair accessible' },
]

const form = reactive({
    name: '',
    email: '',
    description: '',
    capacity: 0,
    location: '',
    facilities: [],
    autoAccept: false,
    active: true,
})

const smtp = reactive({
    host: '',
    port: 587,
    username: '',
    password: '',
    encryption: 'tls',
})

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

const save = () => {
    const data = { ...form }

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

const confirmDelete = () => {
    if (confirm('Are you sure you want to delete this room? This action cannot be undone.')) {
        emit('delete', props.room.id)
    }
}
</script>

<style scoped>
.room-editor__header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
}

.room-editor__header h2 {
    font-size: 20px;
    font-weight: 700;
}

.form-section {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 20px;
    margin-bottom: 16px;
}

.form-section h3 {
    font-size: 16px;
    font-weight: 600;
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
    gap: 12px;
    margin-bottom: 12px;
}

.form-field {
    margin-bottom: 12px;
}

.form-field label {
    display: block;
    font-weight: 500;
    margin-bottom: 4px;
    font-size: 14px;
}

.form-field textarea {
    width: 100%;
    padding: 8px 12px;
    border: 2px solid var(--color-border-maxcontrast);
    border-radius: var(--border-radius-large);
    font-family: inherit;
    resize: vertical;
    background: var(--color-main-background);
    color: var(--color-main-text);
    min-height: 80px;
}

.form-field textarea:focus {
    border-color: var(--color-primary-element);
    outline: none;
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

.form-actions {
    display: flex;
    gap: 8px;
    margin-top: 4px;
}
</style>
