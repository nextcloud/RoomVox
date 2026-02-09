<template>
    <div class="permission-editor">
        <div class="permission-editor__header">
            <NcButton type="tertiary" @click="$emit('back')">
                <template #icon>
                    <ArrowLeft :size="20" />
                </template>
                {{ $t('Back') }}
            </NcButton>
            <h2>{{ $t('Permissions') }}: {{ target.name }}</h2>
        </div>

        <NcNoteCard v-if="targetType === 'group'" type="info" class="permission-editor__info">
            {{ $t('These permissions apply to all rooms in this group. Individual rooms can have additional permissions on top of these.') }}
        </NcNoteCard>

        <NcNoteCard v-if="readOnly" type="info" class="permission-editor__info">
            {{ $t('This room belongs to a group. Permissions are managed at group level and shown here for reference.') }}
        </NcNoteCard>

        <div v-if="loading" class="permission-editor__loading">
            <NcLoadingIcon :size="44" />
        </div>

        <div v-else class="permission-editor__sections">
            <div v-for="role in roles" :key="role.key" class="permission-section">
                <h3>{{ role.label }}</h3>
                <p class="section-description">{{ role.description }}</p>

                <div class="permission-entries">
                    <div v-for="(entry, index) in permissions[role.key]"
                         :key="index"
                         class="permission-entry">
                        <AccountGroup :size="16" />
                        <span class="entry-name">{{ entry.id }}</span>
                        <NcButton v-if="!readOnly" type="tertiary" @click="removeEntry(role.key, index)">
                            <template #icon>
                                <Close :size="20" />
                            </template>
                        </NcButton>
                    </div>

                    <div v-if="permissions[role.key].length === 0" class="no-entries">
                        {{ $t('No {role} configured', { role: role.label.toLowerCase() }) }}
                    </div>
                </div>

                <div v-if="!readOnly" class="add-entry">
                    <NcTextField
                        v-model="searchQueries[role.key]"
                        :placeholder="$t('Search groups...')"
                        @update:model-value="onSearch(role.key)" />
                    <div v-if="searchResults[role.key]?.length > 0" class="search-results">
                        <div v-for="result in searchResults[role.key]"
                             :key="result.type + '-' + result.id"
                             class="search-result"
                             @click="addEntry(role.key, result)">
                            <AccountGroup :size="16" />
                            <span>{{ result.label }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="!readOnly" class="form-actions">
                <NcButton type="primary" @click="save" :disabled="saving">
                    {{ saving ? $t('Saving...') : $t('Save Permissions') }}
                </NcButton>
            </div>
        </div>

        <NcNoteCard v-if="saved" type="success">
            {{ $t('Permissions saved') }}
        </NcNoteCard>
    </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Close from 'vue-material-design-icons/Close.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'

import {
    getPermissions, setPermissions,
    getGroupPermissions, setGroupPermissions,
    searchSharees,
} from '../services/api.js'

const props = defineProps({
    target: { type: Object, required: true },
    targetType: { type: String, default: 'room' }, // 'room' or 'group'
    readOnly: { type: Boolean, default: false },
})

defineEmits(['back'])

const roles = [
    { key: 'viewers', label: 'Viewers', description: 'Groups that can see the room in their calendar, but cannot book.' },
    { key: 'bookers', label: 'Bookers', description: 'Groups that can see and book the room. Bookings follow the auto-accept or approval workflow.' },
    { key: 'managers', label: 'Managers', description: 'Groups that can see, book, and manage the room. Members receive approval requests and can accept/decline bookings.' },
]

const loading = ref(true)
const saving = ref(false)
const saved = ref(false)
const permissions = reactive({ viewers: [], bookers: [], managers: [] })
const searchQueries = reactive({ viewers: '', bookers: '', managers: '' })
const searchResults = reactive({ viewers: [], bookers: [], managers: [] })

let searchTimeouts = {}

const loadPermissions = async () => {
    loading.value = true
    try {
        if (props.readOnly && props.target.groupId) {
            // Read-only mode: show group permissions (what this room inherits)
            const response = await getGroupPermissions(props.target.groupId)
            Object.assign(permissions, response.data)
        } else {
            const fetcher = props.targetType === 'group' ? getGroupPermissions : getPermissions
            const response = await fetcher(props.target.id)
            Object.assign(permissions, response.data)
        }
    } catch (e) {
        showError('Failed to load permissions')
    } finally {
        loading.value = false
    }
}

const onSearch = (role) => {
    clearTimeout(searchTimeouts[role])
    searchTimeouts[role] = setTimeout(async () => {
        const query = searchQueries[role]
        if (query.length < 2) {
            searchResults[role] = []
            return
        }
        try {
            const response = await searchSharees(query)
            // Filter out already added entries
            searchResults[role] = response.data.filter(r =>
                !permissions[role].some(e => e.type === r.type && e.id === r.id)
            )
        } catch (e) {
            searchResults[role] = []
        }
    }, 300)
}

const addEntry = (role, result) => {
    permissions[role].push({ type: result.type, id: result.id })
    searchQueries[role] = ''
    searchResults[role] = []
}

const removeEntry = (role, index) => {
    permissions[role].splice(index, 1)
}

const save = async () => {
    saving.value = true
    try {
        const saver = props.targetType === 'group' ? setGroupPermissions : setPermissions
        await saver(props.target.id, {
            viewers: permissions.viewers,
            bookers: permissions.bookers,
            managers: permissions.managers,
        })
        saved.value = true
        showSuccess('Permissions saved')
        setTimeout(() => { saved.value = false }, 3000)
    } catch (e) {
        showError('Failed to save permissions')
    } finally {
        saving.value = false
    }
}

onMounted(loadPermissions)
</script>

<style scoped>
.permission-editor__header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 24px;
}

.permission-editor__header h2 {
    font-size: 20px;
    font-weight: 700;
}

.permission-editor__info {
    margin-bottom: 16px;
}

.permission-editor__loading {
    display: flex;
    justify-content: center;
    padding: 60px;
}

.permission-section {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 24px;
    margin-bottom: 16px;
}

.permission-section h3 {
    font-size: 17px;
    font-weight: 700;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--color-border);
    margin-bottom: 4px;
}

.section-description {
    color: var(--color-text-maxcontrast);
    margin-bottom: 16px;
    font-size: 13px;
}

.permission-entries {
    margin-bottom: 16px;
}

.permission-entry {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 8px;
    border-radius: var(--border-radius);
    margin-bottom: 4px;
}

.permission-entry:hover {
    background: var(--color-background-hover);
}

.entry-name {
    flex: 1;
    font-weight: 500;
}

.no-entries {
    color: var(--color-text-maxcontrast);
    font-style: italic;
    padding: 8px;
}

.add-entry {
    position: relative;
}

.search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    z-index: 100;
    max-height: 200px;
    overflow-y: auto;
}

.search-result {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    cursor: pointer;
}

.search-result:hover {
    background: var(--color-background-hover);
}

.form-actions {
    margin-top: 4px;
}
</style>
