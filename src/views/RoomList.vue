<template>
    <div class="room-list">
        <div class="room-list__header">
            <h2>{{ $t('Rooms') }}</h2>
            <div class="header-actions">
                <NcTextField
                    v-model="searchQuery"
                    :placeholder="$t('Search rooms...')"
                    class="search-field"
                    trailing-button-icon="close"
                    :show-trailing-button="searchQuery !== ''"
                    @trailing-button-click="searchQuery = ''" />
                <NcButton type="primary" @click="$emit('create')">
                    <template #icon>
                        <Plus :size="20" />
                    </template>
                    {{ $t('New Room') }}
                </NcButton>
            </div>
        </div>

        <NcEmptyContent
            v-if="!loading && rooms.length === 0"
            :name="$t('No rooms configured')"
            :description="$t('Create your first room to get started')">
            <template #icon>
                <DoorOpen :size="64" />
            </template>
            <template #action>
                <NcButton type="primary" @click="$emit('create')">
                    {{ $t('New Room') }}
                </NcButton>
            </template>
        </NcEmptyContent>

        <div v-if="loading" class="room-list__loading">
            <NcLoadingIcon :size="44" />
        </div>

        <NcEmptyContent
            v-if="!loading && rooms.length > 0 && sortedRooms.length === 0"
            :name="$t('No matching rooms')"
            :description="$t('Try a different search query')">
            <template #icon>
                <Magnify :size="64" />
            </template>
        </NcEmptyContent>

        <div v-if="!loading && sortedRooms.length > 0" class="room-list__card">
            <table class="room-list__table">
                <thead>
                    <tr>
                        <th @click="toggleSort('name')">
                            <span class="th-sortable">
                                {{ $t('Name') }}
                                <ChevronUp v-if="sortBy === 'name' && sortDir === 'asc'" :size="14" />
                                <ChevronDown v-else-if="sortBy === 'name' && sortDir === 'desc'" :size="14" />
                            </span>
                        </th>
                        <th @click="toggleSort('location')">
                            <span class="th-sortable">
                                {{ $t('Location') }}
                                <ChevronUp v-if="sortBy === 'location' && sortDir === 'asc'" :size="14" />
                                <ChevronDown v-else-if="sortBy === 'location' && sortDir === 'desc'" :size="14" />
                            </span>
                        </th>
                        <th @click="toggleSort('capacity')">
                            <span class="th-sortable">
                                {{ $t('Capacity') }}
                                <ChevronUp v-if="sortBy === 'capacity' && sortDir === 'asc'" :size="14" />
                                <ChevronDown v-else-if="sortBy === 'capacity' && sortDir === 'desc'" :size="14" />
                            </span>
                        </th>
                        <th>{{ $t('Auto-accept') }}</th>
                        <th>{{ $t('Status') }}</th>
                        <th class="th-actions">{{ $t('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="room in sortedRooms"
                        :key="room.id"
                        class="room-list__row"
                        @click="$emit('select', room)">
                        <td class="room-name">
                            <span class="room-name__inner">
                                <DoorOpen :size="16" />
                                <span>{{ room.name }}</span>
                            </span>
                        </td>
                        <td>{{ room.location || '—' }}</td>
                        <td>{{ room.capacity || '—' }}</td>
                        <td>
                            <NcChip
                                :text="room.autoAccept ? $t('Yes') : $t('No')"
                                :variant="room.autoAccept ? 'success' : 'secondary'"
                                no-close />
                        </td>
                        <td>
                            <NcChip
                                :text="room.active ? $t('Active') : $t('Inactive')"
                                :variant="room.active ? 'success' : 'warning'"
                                no-close />
                        </td>
                        <td class="td-actions" @click.stop>
                            <NcActions>
                                <NcActionButton @click="$emit('select', room)">
                                    <template #icon>
                                        <Pencil :size="20" />
                                    </template>
                                    {{ $t('Edit') }}
                                </NcActionButton>
                            </NcActions>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcChip from '@nextcloud/vue/components/NcChip'
import Plus from 'vue-material-design-icons/Plus.vue'
import DoorOpen from 'vue-material-design-icons/DoorOpen.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'

const props = defineProps({
    rooms: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
})

defineEmits(['select', 'create', 'refresh'])

const searchQuery = ref('')
const sortBy = ref('name')
const sortDir = ref('asc')

const toggleSort = (column) => {
    if (sortBy.value === column) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
    } else {
        sortBy.value = column
        sortDir.value = 'asc'
    }
}

const sortedRooms = computed(() => {
    let filtered = props.rooms

    // Filter by search query
    if (searchQuery.value.trim()) {
        const q = searchQuery.value.toLowerCase()
        filtered = filtered.filter(r =>
            (r.name || '').toLowerCase().includes(q)
            || (r.location || '').toLowerCase().includes(q)
        )
    }

    // Sort
    return [...filtered].sort((a, b) => {
        let aVal, bVal
        if (sortBy.value === 'capacity') {
            aVal = a.capacity || 0
            bVal = b.capacity || 0
        } else {
            aVal = (a[sortBy.value] || '').toLowerCase()
            bVal = (b[sortBy.value] || '').toLowerCase()
        }
        if (aVal < bVal) return sortDir.value === 'asc' ? -1 : 1
        if (aVal > bVal) return sortDir.value === 'asc' ? 1 : -1
        return 0
    })
})
</script>

<style scoped>
.room-list__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 12px;
}

.room-list__header h2 {
    font-size: 20px;
    font-weight: 700;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

.search-field {
    min-width: 180px;
    max-width: 250px;
    flex: 1;
}

.header-actions :deep(.button-vue) {
    flex-shrink: 0;
}

.room-list__loading {
    display: flex;
    justify-content: center;
    padding: 60px;
}

.room-list__card {
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    overflow: hidden;
}

.room-list__table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.room-list__table th {
    text-align: left;
    padding: 12px 16px;
    background: var(--color-background-dark);
    font-weight: 600;
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    border-bottom: 1px solid var(--color-border);
}

.room-list__table th[onclick],
.room-list__table th:has(.th-sortable) {
    cursor: pointer;
    user-select: none;
}

.th-sortable {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.room-list__table th:has(.th-sortable):hover {
    color: var(--color-main-text);
}

.th-actions {
    width: 60px;
    text-align: center !important;
}

.room-list__table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border);
}

.room-list__row {
    cursor: pointer;
    transition: background-color 0.1s;
}

.room-list__row:hover {
    background-color: var(--color-background-hover);
}

.room-list__row:last-child td {
    border-bottom: none;
}

.td-actions {
    text-align: center;
}

.room-name {
    font-weight: 500;
}

.room-name__inner {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
</style>
