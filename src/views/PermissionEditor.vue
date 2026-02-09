<template>
    <div class="permission-editor">
        <div class="permission-editor__header">
            <NcButton type="tertiary" @click="$emit('back')">
                <template #icon>
                    <ArrowLeft :size="20" />
                </template>
                {{ $t('Back') }}
            </NcButton>
            <h2>{{ $t('Permissions') }}: {{ room.name }}</h2>
        </div>

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
                        <span class="entry-badge" :class="'entry-badge--' + entry.type">
                            {{ entry.type === 'group' ? $t('Group') : $t('User') }}
                        </span>
                        <span class="entry-name">{{ entry.id }}</span>
                        <NcButton type="tertiary" @click="removeEntry(role.key, index)">
                            <template #icon>
                                <Close :size="20" />
                            </template>
                        </NcButton>
                    </div>

                    <div v-if="permissions[role.key].length === 0" class="no-entries">
                        {{ $t('No {role} configured', { role: role.label.toLowerCase() }) }}
                    </div>
                </div>

                <div class="add-entry">
                    <NcTextField
                        v-model="searchQueries[role.key]"
                        :placeholder="$t('Search users or groups...')"
                        @update:model-value="onSearch(role.key)" />
                    <div v-if="searchResults[role.key]?.length > 0" class="search-results">
                        <div v-for="result in searchResults[role.key]"
                             :key="result.type + '-' + result.id"
                             class="search-result"
                             @click="addEntry(role.key, result)">
                            <span class="entry-badge" :class="'entry-badge--' + result.type">
                                {{ result.type === 'group' ? $t('Group') : $t('User') }}
                            </span>
                            <span>{{ result.label }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-actions">
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

import { getPermissions, setPermissions, searchSharees } from '../services/api.js'

const props = defineProps({
    room: { type: Object, required: true },
})

defineEmits(['back'])

const roles = [
    { key: 'viewers', label: 'Viewers', description: 'Can see the room as a resource in their calendar app, but cannot book.' },
    { key: 'bookers', label: 'Bookers', description: 'Can see and book the room. Bookings follow the auto-accept or approval workflow.' },
    { key: 'managers', label: 'Managers', description: 'Can see, book, and manage the room. Receives approval requests and can accept/decline bookings.' },
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
        const response = await getPermissions(props.room.id)
        Object.assign(permissions, response.data)
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
        await setPermissions(props.room.id, {
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
    margin-bottom: 20px;
}

.permission-editor__header h2 {
    font-size: 20px;
    font-weight: 700;
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
    padding: 20px;
    margin-bottom: 16px;
}

.permission-section h3 {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 4px;
}

.section-description {
    color: var(--color-text-maxcontrast);
    margin-bottom: 16px;
    font-size: 13px;
}

.permission-entries {
    margin-bottom: 12px;
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

.entry-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.entry-badge--user {
    background: var(--color-primary-element-light);
    color: var(--color-primary-text);
}

.entry-badge--group {
    background: var(--color-warning-element-light);
    color: var(--color-warning-text);
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
