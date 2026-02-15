<template>
    <div class="room-group-editor">
        <div class="room-group-editor__header">
            <NcButton type="tertiary" @click="$emit('cancel')">
                <template #icon>
                    <ArrowLeft :size="20" />
                </template>
                {{ $t('Back') }}
            </NcButton>
            <h2>{{ creating ? $t('New Room Group') : $t('Edit Room Group') }}</h2>
        </div>

        <div class="room-group-editor__form">
            <div class="form-section">
                <h3>{{ $t('General') }}</h3>
                <div class="form-fields">
                    <NcTextField
                        :label="$t('Group name')"
                        v-model="form.name"
                        :placeholder="$t('e.g. Floor 3')"
                        :error="!!errors.name"
                        :helper-text="errors.name"
                        required
                        @update:model-value="errors.name = ''" />
                    <NcTextArea
                        :label="$t('Description')"
                        v-model="form.description"
                        :placeholder="$t('Optional description')"
                        resize="vertical" />
                </div>
            </div>

            <div class="form-actions">
                <NcButton type="primary" @click="save">
                    {{ creating ? $t('Create Group') : $t('Save Changes') }}
                </NcButton>
                <NcButton type="secondary" @click="$emit('cancel')">
                    {{ $t('Cancel') }}
                </NcButton>
                <NcButton
                    v-if="!creating"
                    type="secondary"
                    @click="$emit('manage-permissions', group)">
                    {{ $t('Manage Permissions') }}
                </NcButton>
                <NcButton
                    v-if="!creating"
                    type="error"
                    @click="showDeleteDialog = true">
                    {{ $t('Delete Group') }}
                </NcButton>
            </div>
        </div>

        <NcDialog
            v-if="showDeleteDialog"
            :name="$t('Delete Room Group')"
            @closing="showDeleteDialog = false">
            <p>{{ $t('Are you sure you want to delete this room group? Rooms in this group will become ungrouped. This only works if no rooms are assigned.') }}</p>
            <template #actions>
                <NcButton type="secondary" @click="showDeleteDialog = false">
                    {{ $t('Cancel') }}
                </NcButton>
                <NcButton type="error" @click="showDeleteDialog = false; $emit('delete', group.id)">
                    {{ $t('Delete') }}
                </NcButton>
            </template>
        </NcDialog>
    </div>
</template>

<script setup>
import { ref, reactive, watch } from 'vue'
import { translate } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'

const t = (text, vars = {}) => translate('roomvox', text, vars)

const props = defineProps({
    group: { type: Object, default: null },
    creating: { type: Boolean, default: false },
})

const emit = defineEmits(['save', 'cancel', 'delete', 'manage-permissions'])

const showDeleteDialog = ref(false)

const form = reactive({
    name: '',
    description: '',
})

const errors = reactive({
    name: '',
})

watch(() => props.group, (group) => {
    if (group) {
        form.name = group.name || ''
        form.description = group.description || ''
    }
}, { immediate: true })

const save = () => {
    if (!form.name.trim()) {
        errors.name = t('Group name is required')
        return
    }
    emit('save', { ...form })
}
</script>

<style scoped>
.room-group-editor__header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 24px;
}

.room-group-editor__header h2 {
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

.form-fields {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.form-actions {
    display: flex;
    gap: 12px;
    margin-top: 4px;
}
</style>
