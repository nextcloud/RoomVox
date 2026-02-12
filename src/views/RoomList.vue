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
                <NcButton type="secondary" @click="$emit('create-group')">
                    <template #icon>
                        <FolderPlus :size="20" />
                    </template>
                    {{ $t('New Group') }}
                </NcButton>
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
            v-if="!loading && rooms.length > 0 && visibleGroupCount === 0 && filteredUngroupedRooms.length === 0"
            :name="$t('No matching rooms')"
            :description="$t('Try a different search query')">
            <template #icon>
                <Magnify :size="64" />
            </template>
        </NcEmptyContent>

        <div v-if="!loading && (visibleGroupCount > 0 || filteredUngroupedRooms.length > 0)" class="room-list__groups">
            <!-- Grouped rooms -->
            <div v-for="group in sortedGroups"
                 :key="group.id"
                 class="room-group"
                 :class="{ 'room-group--empty': groupedRooms[group.id]?.length === 0 }">
                <div class="room-group__header" @click="toggleGroup(group.id)">
                    <ChevronRight v-if="!expandedGroups.has(group.id)" :size="20" class="chevron" />
                    <ChevronDown v-else :size="20" class="chevron" />
                    <FolderMultiple :size="18" />
                    <span class="room-group__name">{{ group.name }}</span>
                    <NcCounterBubble v-if="filteredGroupRooms(group.id).length > 0" class="room-group__count" :count="filteredGroupRooms(group.id).length" />
                    <span class="room-group__spacer" />
                    <div class="room-group__actions" @click.stop>
                        <NcActions>
                            <NcActionButton @click="$emit('edit-group', group)">
                                <template #icon>
                                    <Pencil :size="20" />
                                </template>
                                {{ $t('Edit Group') }}
                            </NcActionButton>
                        </NcActions>
                    </div>
                </div>

                <div v-if="expandedGroups.has(group.id) && filteredGroupRooms(group.id).length > 0"
                     class="room-group__body">
                    <div class="room-list__card">
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
                                <tr v-for="room in sortRooms(filteredGroupRooms(group.id))"
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
                                            <NcActionButton v-for="groupOption in moveToGroupOptions(room)"
                                                :key="groupOption.id ?? 'none'"
                                                @click="handleMoveToGroup(room, groupOption.id)">
                                                <template #icon>
                                                    <Check v-if="groupOption.isCurrent" :size="20" />
                                                    <FolderMove v-else :size="20" />
                                                </template>
                                                {{ groupOption.label }}
                                            </NcActionButton>
                                        </NcActions>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="expandedGroups.has(group.id) && filteredGroupRooms(group.id).length === 0"
                     class="room-group__empty">
                    {{ $t('No rooms in this group') }}
                </div>
            </div>

            <!-- Ungrouped rooms -->
            <div v-if="filteredUngroupedRooms.length > 0" class="room-group room-group--ungrouped">
                <div class="room-group__header" @click="toggleGroup('__ungrouped')">
                    <ChevronRight v-if="!expandedGroups.has('__ungrouped')" :size="20" class="chevron" />
                    <ChevronDown v-else :size="20" class="chevron" />
                    <FolderMultiple :size="18" />
                    <span class="room-group__name">{{ $t('Ungrouped Rooms') }}</span>
                    <NcCounterBubble class="room-group__count" :count="filteredUngroupedRooms.length" />
                    <span class="room-group__spacer" />
                </div>

                <div v-if="expandedGroups.has('__ungrouped')" class="room-group__body">
                    <div class="room-list__card">
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
                                <tr v-for="room in sortRooms(filteredUngroupedRooms)"
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
                                            <NcActionButton v-for="groupOption in moveToGroupOptions(room)"
                                                :key="groupOption.id ?? 'none'"
                                                @click="handleMoveToGroup(room, groupOption.id)">
                                                <template #icon>
                                                    <Check v-if="groupOption.isCurrent" :size="20" />
                                                    <FolderMove v-else :size="20" />
                                                </template>
                                                {{ groupOption.label }}
                                            </NcActionButton>
                                        </NcActions>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
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
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import Plus from 'vue-material-design-icons/Plus.vue'
import FolderPlus from 'vue-material-design-icons/FolderPlus.vue'
import FolderMultiple from 'vue-material-design-icons/FolderMultiple.vue'
import DoorOpen from 'vue-material-design-icons/DoorOpen.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import FolderMove from 'vue-material-design-icons/FolderMove.vue'
import Check from 'vue-material-design-icons/Check.vue'

const props = defineProps({
    rooms: { type: Array, default: () => [] },
    roomGroups: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
})

const emit = defineEmits(['select', 'create', 'create-group', 'edit-group', 'group-permissions', 'refresh', 'move-to-group'])

const searchQuery = ref('')
const sortBy = ref('name')
const sortDir = ref('asc')
const expandedGroups = ref(new Set(['__ungrouped']))

// Expand all groups on load
const initExpanded = () => {
    props.roomGroups.forEach(g => expandedGroups.value.add(g.id))
}

// Watch for new groups being loaded
import { watch } from 'vue'
watch(() => props.roomGroups, () => {
    initExpanded()
}, { immediate: true })

const toggleGroup = (groupId) => {
    if (expandedGroups.value.has(groupId)) {
        expandedGroups.value.delete(groupId)
    } else {
        expandedGroups.value.add(groupId)
    }
}

const toggleSort = (column) => {
    if (sortBy.value === column) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
    } else {
        sortBy.value = column
        sortDir.value = 'asc'
    }
}

const matchesSearch = (room) => {
    if (!searchQuery.value.trim()) return true
    const q = searchQuery.value.toLowerCase()
    return (room.name || '').toLowerCase().includes(q)
        || (room.location || '').toLowerCase().includes(q)
}

const groupedRooms = computed(() => {
    const map = {}
    props.roomGroups.forEach(g => { map[g.id] = [] })
    props.rooms.forEach(room => {
        if (room.groupId && map[room.groupId]) {
            map[room.groupId].push(room)
        }
    })
    return map
})

const ungroupedRooms = computed(() => {
    const groupIds = new Set(props.roomGroups.map(g => g.id))
    return props.rooms.filter(r => !r.groupId || !groupIds.has(r.groupId))
})

const filteredGroupRooms = (groupId) => {
    return (groupedRooms.value[groupId] || []).filter(matchesSearch)
}

const filteredUngroupedRooms = computed(() => {
    return ungroupedRooms.value.filter(matchesSearch)
})

const sortedGroups = computed(() => {
    return [...props.roomGroups].sort((a, b) =>
        (a.name || '').localeCompare(b.name || '')
    )
})

const visibleGroupCount = computed(() => {
    return sortedGroups.value.filter(g =>
        filteredGroupRooms(g.id).length > 0 || !searchQuery.value.trim()
    ).length
})

const sortRooms = (rooms) => {
    return [...rooms].sort((a, b) => {
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
}

const moveToGroupOptions = (room) => {
    const options = props.roomGroups.map(g => ({
        id: g.id,
        label: g.name,
        isCurrent: room.groupId === g.id
    }))
    // Add "No group" option
    options.push({
        id: null,
        label: '— No group —',
        isCurrent: !room.groupId
    })
    return options
}

const handleMoveToGroup = (room, groupId) => {
    emit('move-to-group', { room, groupId })
}
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

.room-list__groups {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.room-group {
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    overflow: hidden;
}

.room-group__header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    background: var(--color-background-dark);
    cursor: pointer;
    user-select: none;
}

.room-group__header:hover {
    background: var(--color-background-hover);
}

.chevron {
    flex-shrink: 0;
    color: var(--color-text-maxcontrast);
}

.room-group__name {
    font-weight: 600;
    font-size: 15px;
}

.room-group__count {
    flex-shrink: 0;
}

.room-group__spacer {
    flex: 1;
}

.room-group__actions {
    flex-shrink: 0;
}

.room-group__body .room-list__card {
    border: none;
    border-radius: 0;
}

.room-group__empty {
    padding: 16px 24px;
    color: var(--color-text-maxcontrast);
    font-style: italic;
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
