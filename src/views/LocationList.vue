<template>
    <div class="location-list">
        <template v-if="!form">
            <div class="location-list__header">
                <h2>{{ t('roomvox', 'Locations') }}</h2>
                <div class="header-actions">
                    <NcTextField v-model="searchQuery"
                        :placeholder="t('roomvox', 'Search locations …')"
                        class="search-field"
                        trailing-button-icon="close"
                        :show-trailing-button="searchQuery !== ''"
                        @trailing-button-click="searchQuery = ''" />
                    <NcButton variant="primary" :disabled="loading" @click="edit()">
                        <template #icon><Plus :size="20" /></template>
                        {{ t('roomvox', 'New location') }}
                    </NcButton>
                </div>
            </div>

            <div v-if="loading" class="location-list__loading"><NcLoadingIcon :size="44" /></div>
            <NcEmptyContent v-else-if="!locations.length"
                :name="t('roomvox', 'No locations configured')"
                :description="t('roomvox', 'Create a location, then assign it to rooms in the room editor.')">
                <template #icon><MapMarker :size="64" /></template>
                <template #action>
                    <NcButton variant="primary" @click="edit()">{{ t('roomvox', 'New location') }}</NcButton>
                </template>
            </NcEmptyContent>
            <NcEmptyContent v-else-if="!visibleLocations.length"
                :name="t('roomvox', 'No matching locations')"
                :description="t('roomvox', 'Try a different search query')">
                <template #icon><Magnify :size="64" /></template>
            </NcEmptyContent>
            <div v-else class="location-list__card">
                <table class="location-list__table">
                    <colgroup>
                        <col class="col-name"><col class="col-building"><col class="col-address"><col class="col-rooms"><col class="col-actions">
                    </colgroup>
                    <thead>
                        <tr>
                            <th v-for="column in columns" :key="column.key"
                                :aria-sort="sortBy === column.key ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'">
                                <button class="th-sortable" @click="toggleSort(column.key)">
                                    {{ column.label }}
                                    <ChevronUp v-if="sortBy === column.key && sortDir === 'asc'" :size="14" />
                                    <ChevronDown v-else-if="sortBy === column.key" :size="14" />
                                </button>
                            </th>
                            <th class="th-actions">{{ t('roomvox', 'Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="location in visibleLocations" :key="location.id" class="location-list__row" @click="edit(location)">
                            <td class="location-name">
                                <button class="location-name__inner" @click.stop="edit(location)">
                                    <MapMarker :size="16" /><span>{{ location.name }}</span>
                                </button>
                            </td>
                            <td :title="location.building">{{ location.building || '—' }}</td>
                            <td :title="formatAddress(location)">{{ formatAddress(location) || '—' }}</td>
                            <td :title="roomNames(location.id)">{{ roomNames(location.id) || '—' }}</td>
                            <td class="td-actions" @click.stop>
                                <NcActions>
                                    <NcActionButton @click="edit(location)">
                                        <template #icon><Pencil :size="20" /></template>
                                        {{ t('roomvox', 'Edit') }}
                                    </NcActionButton>
                                    <NcActionButton :disabled="assignedRooms(location.id).length > 0" @click="deleting = location">
                                        <template #icon><Delete :size="20" /></template>
                                        {{ t('roomvox', 'Delete') }}
                                    </NcActionButton>
                                </NcActions>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <div v-else class="location-editor">
            <div class="location-editor__header">
                <NcButton variant="tertiary" :disabled="busy" @click="form = null">
                    <template #icon><ArrowLeft :size="20" /></template>
                    {{ t('roomvox', 'Back') }}
                </NcButton>
                <h2>{{ form.id ? t('roomvox', 'Edit location') : t('roomvox', 'New location') }}</h2>
            </div>
            <form @submit.prevent="save">
                <div class="form-section">
                    <h3>{{ t('roomvox', 'General') }}</h3>
                    <div class="form-grid">
                        <NcTextField v-model="form.name" :label="t('roomvox', 'Name')" required :disabled="busy" />
                        <NcTextField v-model="form.building" :label="t('roomvox', 'Building')" :disabled="busy" />
                    </div>
                    <NcTextArea v-model="form.description" :label="t('roomvox', 'Description')" resize="vertical" :disabled="busy" />
                </div>
                <div class="form-section">
                    <h3>{{ t('roomvox', 'Address') }}</h3>
                    <div class="form-grid">
                        <NcTextField v-model="form.street" :label="t('roomvox', 'Street and number')" :disabled="busy" />
                        <NcTextField v-model="form.postalCode" :label="t('roomvox', 'Postal code')" :disabled="busy" />
                        <NcTextField v-model="form.city" :label="t('roomvox', 'City')" :disabled="busy" />
                        <NcTextField v-model="form.country" :label="t('roomvox', 'Country')" :disabled="busy" />
                    </div>
                </div>
                <div v-if="form.id" class="form-section">
                    <h3>{{ t('roomvox', 'Rooms') }}</h3>
                    <p>{{ roomNames(form.id) || '—' }}</p>
                    <p class="form-hint">{{ t('roomvox', 'To delete a location, first remove its room assignments.') }}</p>
                </div>
                <div class="form-actions">
                    <NcButton variant="primary" :disabled="busy || !form.name.trim()" @click="save">
                        {{ form.id ? t('roomvox', 'Save changes') : t('roomvox', 'Create location') }}
                    </NcButton>
                    <NcButton variant="secondary" :disabled="busy" @click="form = null">{{ t('roomvox', 'Cancel') }}</NcButton>
                    <NcButton v-if="form.id" variant="error" :disabled="busy || assignedRooms(form.id).length > 0" @click="deleting = form">
                        {{ t('roomvox', 'Delete location') }}
                    </NcButton>
                </div>
            </form>
        </div>
        <NcDialog v-if="deleting" :name="t('roomvox', 'Delete location')" @closing="!busy && (deleting = null)">
            <p>{{ t('roomvox', 'Delete location {name}?', { name: deleting.name }) }}</p>
            <template #actions>
                <NcButton variant="secondary" :disabled="busy" @click="deleting = null">{{ t('roomvox', 'Cancel') }}</NcButton>
                <NcButton variant="error" :disabled="busy" @click="remove">{{ t('roomvox', 'Delete') }}</NcButton>
            </template>
        </NcDialog>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import MapMarker from 'vue-material-design-icons/MapMarker.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import { createLocation, updateLocation, deleteLocation } from '../services/api.js'

const props = defineProps({
    locations: { type: Array, default: () => [] },
    rooms: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
})
const emit = defineEmits(['changed'])
const searchQuery = ref('')
const sortBy = ref('name')
const sortDir = ref('asc')
const columns = computed(() => [
    { key: 'name', label: t('roomvox', 'Name') },
    { key: 'building', label: t('roomvox', 'Building') },
    { key: 'address', label: t('roomvox', 'Address') },
    { key: 'rooms', label: t('roomvox', 'Rooms') },
])
const formatAddress = location => [location.street, [location.postalCode, location.city].filter(Boolean).join(' '), location.country].filter(Boolean).join(', ')
const roomNames = id => assignedRooms(id).map(room => room.name).join(', ')
const sortValue = (location, key) => key === 'address' ? formatAddress(location) : key === 'rooms' ? roomNames(location.id) : location[key] || ''
const visibleLocations = computed(() => {
    const query = searchQuery.value.trim().toLocaleLowerCase()
    return props.locations.filter(location => [location.name, location.building, formatAddress(location), location.description, roomNames(location.id)]
        .some(value => (value || '').toLocaleLowerCase().includes(query)))
        .sort((a, b) => sortValue(a, sortBy.value).localeCompare(sortValue(b, sortBy.value), undefined, { numeric: true, sensitivity: 'base' }) * (sortDir.value === 'asc' ? 1 : -1))
})
const toggleSort = key => {
    sortDir.value = sortBy.value === key && sortDir.value === 'asc' ? 'desc' : 'asc'
    sortBy.value = key
}
const form = ref(null)
const busy = ref(false)
const deleting = ref(null)
const assignedRooms = id => props.rooms.filter(room => room.locationId === id)
const edit = (location = {}) => {
    form.value = { name: '', building: '', street: '', postalCode: '', city: '', country: '', description: '', ...location }
}
const save = async () => {
    if (busy.value || !form.value.name.trim()) return
    busy.value = true
    try {
        if (form.value.id) await updateLocation(form.value.id, form.value)
        else await createLocation(form.value)
        form.value = null
        showSuccess(t('roomvox', 'Location saved'))
        emit('changed')
    } catch (e) {
        showError(t('roomvox', 'Failed to save location'))
    } finally {
        busy.value = false
    }
}
const remove = async () => {
    if (busy.value) return
    busy.value = true
    try {
        await deleteLocation(deleting.value.id)
        deleting.value = null
        form.value = null
        showSuccess(t('roomvox', 'Location deleted'))
        emit('changed')
    } catch (e) {
        showError(e.response?.status === 409
            ? t('roomvox', 'To delete a location, first remove its room assignments.')
            : t('roomvox', 'Failed to delete location'))
    } finally {
        busy.value = false
    }
}
</script>

<style scoped>
.location-list__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
    min-width: 0;
}
.location-list__header h2, .location-editor__header h2 { font-size: 20px; font-weight: 700; }
.header-actions { display: flex; align-items: center; gap: 12px; min-width: 0; flex-wrap: wrap; }
.search-field { min-width: 120px; max-width: 250px; flex: 1; }
.header-actions :deep(.button-vue) { flex-shrink: 0; }
.location-list__loading { display: flex; justify-content: center; padding: 60px; }
.location-list__card { border: 1px solid var(--color-border); border-radius: var(--border-radius-large); overflow-x: auto; }
.location-list__table { width: 100%; min-width: 620px; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
.col-name { width: 22%; }
.col-building { width: 18%; }
.col-address { width: 30%; }
.col-rooms { width: 20%; }
.col-actions { width: 10%; }
.location-list__table th { text-align: left; padding: 12px; background: var(--color-background-dark); font-weight: 600; color: var(--color-text-maxcontrast); font-size: 13px; border-bottom: 1px solid var(--color-border); }
.th-sortable { display: inline-flex; align-items: center; gap: 4px; background: transparent; border: 0; padding: 0; margin: 0; min-height: 24px; font: inherit; color: inherit; cursor: pointer; }
.th-sortable:hover { color: var(--color-main-text); }
.th-actions, .td-actions { text-align: center !important; }
.location-list__table td { padding: 12px; border-bottom: 1px solid var(--color-border); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.location-list__row { cursor: pointer; transition: background-color 0.1s; }
.location-list__row:hover { background-color: var(--color-background-hover); }
.location-list__row:last-child td { border-bottom: none; }
.location-name__inner { display: inline-flex; align-items: center; gap: 8px; max-width: 100%; font: inherit; font-weight: 500; color: inherit; background: transparent; border: 0; padding: 0; margin: 0; cursor: pointer; }
.location-name__inner span { overflow: hidden; text-overflow: ellipsis; }
.location-name__inner :deep(.material-design-icon) { flex-shrink: 0; }
.location-editor__header { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
.form-section { background: var(--color-main-background); border: 1px solid var(--color-border); border-radius: var(--border-radius-large); padding: 24px; margin-bottom: 16px; }
.form-section h3 { font-size: 17px; font-weight: 700; padding-bottom: 12px; border-bottom: 1px solid var(--color-border); margin-bottom: 16px; }
.form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-bottom: 16px; }
.form-section p { overflow-wrap: anywhere; }
.form-hint { color: var(--color-text-maxcontrast); margin-top: 8px; }
.form-actions { display: flex; gap: 12px; margin-top: 4px; flex-wrap: wrap; }
@media (max-width: 600px) { .form-grid { grid-template-columns: 1fr; } }
</style>
