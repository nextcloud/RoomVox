<template>
    <div class="roomvox-app">
        <!-- Tab Navigation - IntraVox/FormVox style -->
        <nav class="tab-navigation">
            <button
                :class="['tab-button', { active: isTabActive('rooms') }]"
                @click="onTabClick('rooms')">
                <DoorOpen :size="16" />
                {{ $t('Rooms') }}
                <NcCounterBubble v-if="rooms.length > 0" :count="rooms.length" />
            </button>
            <button
                :class="['tab-button', { active: isTabActive('bookings') }]"
                @click="onTabClick('bookings')">
                <CalendarCheck :size="16" />
                {{ $t('Bookings') }}
            </button>
            <button
                :class="['tab-button', { active: isTabActive('settings') }]"
                @click="onTabClick('settings')">
                <Cog :size="16" />
                {{ $t('Settings') }}
            </button>
            <button
                :class="['tab-button', { active: isTabActive('statistics') }]"
                @click="onTabClick('statistics')">
                <ChartBox :size="16" />
                {{ $t('Statistics') }}
            </button>
        </nav>

        <!-- Content -->
        <div class="roomvox-content">
            <!-- Room list -->
            <RoomList
                v-if="currentView === 'rooms' && !selectedRoom && !creatingRoom && !selectedRoomGroup && !creatingRoomGroup"
                :rooms="rooms"
                :room-groups="roomGroups"
                :room-types="settings.roomTypes"
                :loading="loadingRooms"
                @select="onSelectRoom"
                @create="creatingRoom = true"
                @create-group="creatingRoomGroup = true"
                @edit-group="onSelectRoomGroup"
                @group-permissions="onManageGroupPermissions"
                @refresh="loadRooms"
                @move-to-group="onMoveToGroup" />

            <!-- Room editor -->
            <RoomEditor
                v-if="currentView === 'rooms' && (selectedRoom || creatingRoom)"
                :room="selectedRoom"
                :creating="creatingRoom"
                :room-groups="roomGroups"
                :room-types="settings.roomTypes"
                @save="onSaveRoom"
                @cancel="selectedRoom = null; creatingRoom = false"
                @delete="onDeleteRoom"
                @manage-permissions="onManagePermissions" />

            <!-- Room group editor -->
            <RoomGroupEditor
                v-if="currentView === 'rooms' && (selectedRoomGroup || creatingRoomGroup)"
                :group="selectedRoomGroup"
                :creating="creatingRoomGroup"
                @save="onSaveRoomGroup"
                @cancel="selectedRoomGroup = null; creatingRoomGroup = false"
                @delete="onDeleteRoomGroup"
                @manage-permissions="onManageGroupPermissions" />

            <!-- Room permissions -->
            <PermissionEditor
                v-if="currentView === 'permissions' && permissionTarget"
                :target="permissionTarget"
                :target-type="permissionTargetType"
                :read-only="permissionTargetType === 'room' && !!permissionTarget.groupId"
                @back="currentView = 'rooms'; permissionTarget = null" />

            <!-- Bookings -->
            <div v-if="currentView === 'bookings'" class="tab-content">
                <BookingOverview :rooms="rooms" />
            </div>

            <!-- Statistics -->
            <div v-if="currentView === 'statistics'" class="tab-content">
                <div class="settings-section">
                    <h2>{{ $t('Room Statistics') }}</h2>
                    <p class="settings-section-desc">{{ $t('Overview of rooms and bookings in your RoomVox installation.') }}</p>

                    <div class="stats-overview">
                        <div class="stat-row">
                            <div class="stat-info">
                                <span class="stat-icon">🚪</span>
                                <span class="stat-label">{{ $t('Total Rooms') }}</span>
                            </div>
                            <span class="stat-value">{{ rooms.length }}</span>
                        </div>
                        <div class="stat-row">
                            <div class="stat-info">
                                <span class="stat-icon">✅</span>
                                <span class="stat-label">{{ $t('Active Rooms') }}</span>
                            </div>
                            <span class="stat-value">{{ rooms.filter(r => r.active !== false).length }}</span>
                        </div>
                        <div class="stat-row">
                            <div class="stat-info">
                                <span class="stat-icon">📁</span>
                                <span class="stat-label">{{ $t('Room Groups') }}</span>
                            </div>
                            <span class="stat-value">{{ roomGroups.length }}</span>
                        </div>
                    </div>

                    <div class="about-info">
                        <h4>{{ $t('About RoomVox') }}</h4>
                        <p>{{ $t('RoomVox is open source room booking software for Nextcloud. RoomVox is free for small installations. Larger organisations may require a license in the future.') }}</p>
                        <p>{{ $t('Anonymous usage statistics help us understand how RoomVox is used and guide future development.') }}</p>
                    </div>
                </div>

                <div class="settings-section">
                    <h2>{{ $t('Anonymous Usage Statistics') }}</h2>
                    <p class="settings-section-desc">{{ $t('Help improve RoomVox by sharing anonymous usage statistics.') }}</p>

                    <div class="telemetry-settings">
                        <div class="engagement-option">
                            <NcCheckboxRadioSwitch
                                type="switch"
                                :model-value="telemetryEnabled"
                                @update:model-value="toggleTelemetry($event)">
                                <div class="option-info">
                                    <span class="option-label">{{ $t('Share anonymous usage statistics') }}</span>
                                    <span class="option-desc">{{ $t('We collect: room counts, booking counts, and version info (RoomVox, Nextcloud, PHP). No personal data or booking details are shared.') }}</span>
                                </div>
                            </NcCheckboxRadioSwitch>
                        </div>

                        <div v-if="telemetryEnabled" class="telemetry-info">
                            <NcNoteCard type="success">
                                <p>{{ $t('Thank you for helping improve RoomVox!') }}</p>
                                <p v-if="telemetryLastReport">{{ $t('Last report sent:') }} {{ telemetryLastReport }}</p>
                            </NcNoteCard>
                        </div>

                        <div class="telemetry-details">
                            <h4>{{ $t('What we collect:') }}</h4>
                            <ul>
                                <li>{{ $t('Number of rooms and room groups') }}</li>
                                <li>{{ $t('Number of bookings') }}</li>
                                <li>{{ $t('RoomVox, Nextcloud, and PHP version numbers') }}</li>
                                <li>{{ $t('A unique hash of your instance URL (privacy-friendly identifier)') }}</li>
                            </ul>
                            <h4>{{ $t('What we never collect:') }}</h4>
                            <ul class="not-collected">
                                <li>{{ $t('Room names or descriptions') }}</li>
                                <li>{{ $t('Booking details or attendees') }}</li>
                                <li>{{ $t('User names or email addresses') }}</li>
                                <li>{{ $t('Your actual server URL') }}</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Settings -->
            <div v-if="currentView === 'settings'" class="roomvox-settings">
                <NcSettingsSection :name="'General'">
                    <NcCheckboxRadioSwitch
                        :model-value="settings.defaultAutoAccept"
                        @update:model-value="settings.defaultAutoAccept = $event; saveGlobalSettings()">
                        {{ $t('Auto-accept bookings by default for new rooms') }}
                    </NcCheckboxRadioSwitch>
                    <NcCheckboxRadioSwitch
                        :model-value="settings.emailEnabled"
                        @update:model-value="settings.emailEnabled = $event; saveGlobalSettings()">
                        {{ $t('Enable email notifications') }}
                    </NcCheckboxRadioSwitch>
                </NcSettingsSection>

                <NcSettingsSection :name="'Room types'">
                    <p class="section-description">
                        {{ $t('Configure the available room types. Types that are in use cannot be deleted.') }}
                    </p>
                    <ul class="room-type-list">
                        <li v-for="(type, index) in settings.roomTypes"
                            :key="type.id"
                            :class="['room-type-item', { 'room-type-item--dragging': dragIndex === index, 'room-type-item--over': dragOverIndex === index && dragIndex !== index }]"
                            draggable="true"
                            @dragstart="onDragStart(index, $event)"
                            @dragover.prevent="onDragOver(index)"
                            @dragend="onDragEnd">
                            <span class="room-type-handle">
                                <DragHorizontalVariant :size="20" />
                            </span>
                            <input
                                type="text"
                                :value="type.label"
                                class="room-type-input"
                                @change="updateRoomTypeLabel(index, $event.target.value)" />
                            <span class="room-type-id">{{ type.id }}</span>
                            <NcButton
                                type="tertiary"
                                :aria-label="$t('Delete')"
                                :disabled="isRoomTypeInUse(type.id)"
                                @click="removeRoomType(index)">
                                <template #icon>
                                    <Close :size="20" />
                                </template>
                            </NcButton>
                        </li>
                    </ul>
                    <div class="room-type-add">
                        <input
                            type="text"
                            v-model="newRoomTypeLabel"
                            class="room-type-input"
                            :placeholder="$t('New room type...')"
                            @keyup.enter="addRoomType" />
                        <NcButton
                            type="secondary"
                            :aria-label="$t('Add')"
                            :disabled="!newRoomTypeLabel.trim()"
                            @click="addRoomType">
                            <template #icon>
                                <Plus :size="20" />
                            </template>
                        </NcButton>
                    </div>
                </NcSettingsSection>

                <NcNoteCard v-if="settingsSaved" type="success">
                    {{ $t('Settings saved') }}
                </NcNoteCard>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate } from '@nextcloud/l10n'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcButton from '@nextcloud/vue/components/NcButton'
import DoorOpen from 'vue-material-design-icons/DoorOpen.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import DragHorizontalVariant from 'vue-material-design-icons/DragHorizontalVariant.vue'
import CalendarCheck from 'vue-material-design-icons/CalendarCheck.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import ChartBox from 'vue-material-design-icons/ChartBox.vue'

import RoomList from './views/RoomList.vue'
import RoomEditor from './views/RoomEditor.vue'
import RoomGroupEditor from './views/RoomGroupEditor.vue'
import PermissionEditor from './views/PermissionEditor.vue'
import BookingOverview from './views/BookingOverview.vue'

import {
    getRooms, createRoom, updateRoom, deleteRoom,
    getRoomGroups, createRoomGroup, updateRoomGroup, deleteRoomGroup,
    getSettings, saveSettings,
} from './services/api.js'

const t = (text, vars = {}) => translate('roomvox', text, vars)

const currentView = ref('rooms')
const rooms = ref([])
const roomGroups = ref([])
const selectedRoom = ref(null)
const creatingRoom = ref(false)
const selectedRoomGroup = ref(null)
const creatingRoomGroup = ref(false)
const permissionTarget = ref(null)
const permissionTargetType = ref('room')
const loadingRooms = ref(true)
const settings = ref({ defaultAutoAccept: false, emailEnabled: true, roomTypes: [] })
const settingsSaved = ref(false)
const newRoomTypeLabel = ref('')
const dragIndex = ref(null)
const dragOverIndex = ref(null)
const telemetryEnabled = ref(true)
const telemetryLastReport = ref(null)

const isTabActive = (tabId) => {
    if (tabId === 'rooms') return currentView.value === 'rooms' || currentView.value === 'permissions'
    return currentView.value === tabId
}

const onTabClick = (tabId) => {
    currentView.value = tabId
    if (tabId === 'rooms') {
        selectedRoom.value = null
        creatingRoom.value = false
        selectedRoomGroup.value = null
        creatingRoomGroup.value = false
        permissionTarget.value = null
    }
}

const loadRooms = async () => {
    loadingRooms.value = true
    try {
        const [roomsRes, groupsRes] = await Promise.all([getRooms(), getRoomGroups()])
        rooms.value = roomsRes.data
        roomGroups.value = groupsRes.data
    } catch (e) {
        showError(t('Failed to load rooms'))
    } finally {
        loadingRooms.value = false
    }
}

const loadSettings = async () => {
    try {
        const response = await getSettings()
        settings.value = response.data
    } catch (e) {
        // Settings might not be accessible for non-admins
    }
}

// Room handlers
const onSelectRoom = (room) => {
    selectedRoom.value = room
    creatingRoom.value = false
}

const onSaveRoom = async (roomData) => {
    try {
        if (creatingRoom.value) {
            await createRoom(roomData)
            showSuccess(t('Room created'))
        } else {
            await updateRoom(selectedRoom.value.id, roomData)
            showSuccess(t('Room updated'))
        }
        selectedRoom.value = null
        creatingRoom.value = false
        await loadRooms()
    } catch (e) {
        showError(t('Failed to save room') + ': ' + (e.response?.data?.error || e.message))
    }
}

const onDeleteRoom = async (roomId) => {
    try {
        await deleteRoom(roomId)
        showSuccess(t('Room deleted'))
        selectedRoom.value = null
        await loadRooms()
    } catch (e) {
        showError(t('Failed to delete room'))
    }
}

const onManagePermissions = (room) => {
    permissionTarget.value = room
    permissionTargetType.value = 'room'
    currentView.value = 'permissions'
}

// Room group handlers
const onSelectRoomGroup = (group) => {
    selectedRoomGroup.value = group
    creatingRoomGroup.value = false
}

const onSaveRoomGroup = async (groupData) => {
    try {
        if (creatingRoomGroup.value) {
            await createRoomGroup(groupData)
            showSuccess(t('Room group created'))
        } else {
            await updateRoomGroup(selectedRoomGroup.value.id, groupData)
            showSuccess(t('Room group updated'))
        }
        selectedRoomGroup.value = null
        creatingRoomGroup.value = false
        await loadRooms()
    } catch (e) {
        showError(t('Failed to save room group') + ': ' + (e.response?.data?.error || e.message))
    }
}

const onDeleteRoomGroup = async (groupId) => {
    try {
        await deleteRoomGroup(groupId)
        showSuccess(t('Room group deleted'))
        selectedRoomGroup.value = null
        await loadRooms()
    } catch (e) {
        showError(t('Failed to delete room group') + ': ' + (e.response?.data?.error || e.message))
    }
}

const onManageGroupPermissions = (group) => {
    permissionTarget.value = group
    permissionTargetType.value = 'group'
    currentView.value = 'permissions'
}

// Move room to group handler
const onMoveToGroup = async ({ room, groupId }) => {
    try {
        await updateRoom(room.id, { ...room, groupId })
        showSuccess(groupId ? t('Room moved to group') : t('Room removed from group'))
        await loadRooms()
    } catch (e) {
        showError(t('Failed to move room') + ': ' + (e.response?.data?.error || e.message))
    }
}

const saveGlobalSettings = async () => {
    try {
        await saveSettings(settings.value)
        settingsSaved.value = true
        setTimeout(() => { settingsSaved.value = false }, 3000)
    } catch (e) {
        showError(t('Failed to save settings'))
    }
}

const slugify = (text) => {
    return text.toLowerCase().trim()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/[\s]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '') || 'type'
}

const isRoomTypeInUse = (typeId) => {
    return rooms.value.some(r => r.roomType === typeId)
}

const addRoomType = () => {
    const label = newRoomTypeLabel.value.trim()
    if (!label) return

    let id = slugify(label)
    // Ensure unique id
    const existingIds = settings.value.roomTypes.map(t => t.id)
    if (existingIds.includes(id)) {
        let i = 2
        while (existingIds.includes(id + '-' + i)) i++
        id = id + '-' + i
    }

    settings.value.roomTypes.push({ id, label })
    newRoomTypeLabel.value = ''
    saveGlobalSettings()
}

const removeRoomType = (index) => {
    const type = settings.value.roomTypes[index]
    if (isRoomTypeInUse(type.id)) {
        showError(t('Cannot delete: this room type is in use'))
        return
    }
    settings.value.roomTypes.splice(index, 1)
    saveGlobalSettings()
}

const updateRoomTypeLabel = (index, newLabel) => {
    settings.value.roomTypes[index].label = newLabel
    saveGlobalSettings()
}

const onDragStart = (index, event) => {
    dragIndex.value = index
    event.dataTransfer.effectAllowed = 'move'
}

const onDragOver = (index) => {
    dragOverIndex.value = index
}

const onDragEnd = () => {
    if (dragIndex.value !== null && dragOverIndex.value !== null && dragIndex.value !== dragOverIndex.value) {
        const types = settings.value.roomTypes
        const [moved] = types.splice(dragIndex.value, 1)
        types.splice(dragOverIndex.value, 0, moved)
        saveGlobalSettings()
    }
    dragIndex.value = null
    dragOverIndex.value = null
}

const toggleTelemetry = async (enabled) => {
    try {
        // TODO: Implement API call to save telemetry setting
        // const response = await axios.post(generateUrl('/apps/roomvox/api/statistics/telemetry'), { enabled })
        telemetryEnabled.value = enabled
        if (enabled) {
            showSuccess(t('Thank you for helping improve RoomVox!'))
        }
    } catch (e) {
        showError(t('Failed to update telemetry setting'))
        telemetryEnabled.value = !enabled
    }
}

onMounted(() => {
    loadRooms()
    loadSettings()
})
</script>

<style scoped>
.roomvox-app {
    padding: 20px;
}

/* Tab Navigation - IntraVox/FormVox style */
.tab-navigation {
    border-bottom: 1px solid var(--color-border);
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.tab-button {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    border: none;
    background: none;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    color: var(--color-text-lighter);
    font-size: 14px;
    transition: all 0.2s ease;
}

.tab-button:hover:not(.active) {
    background: var(--color-background-hover);
}

.tab-button.active {
    border-bottom-color: var(--color-primary);
    color: var(--color-primary);
    background: var(--color-primary-element-light);
}

.tab-content {
    animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-4px); }
    to { opacity: 1; transform: translateY(0); }
}

.roomvox-content {
    margin-top: 0;
}

/* Settings sections */
.settings-section {
    margin-bottom: 32px;
}

.settings-section h2 {
    font-size: 20px;
    font-weight: bold;
    margin-bottom: 8px;
}

.settings-section-desc {
    color: var(--color-text-maxcontrast);
    margin-bottom: 20px;
}

/* Stats overview */
.stats-overview {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 24px;
}

.stat-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius-large);
}

.stat-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.stat-icon {
    font-size: 1.5em;
}

.stat-label {
    font-weight: 500;
    color: var(--color-main-text);
}

.stat-value {
    font-size: 24px;
    font-weight: 700;
    color: var(--color-primary);
}

.roomvox-settings {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.roomvox-settings .section-description {
    color: var(--color-text-maxcontrast);
    margin-bottom: 12px;
    font-size: 13px;
}

.room-type-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.room-type-item {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
    border-radius: var(--border-radius-large);
    padding: 2px 0;
    transition: background 0.15s ease;
}

.room-type-item--dragging {
    opacity: 0.4;
}

.room-type-item--over {
    background: var(--color-primary-element-light);
}

.room-type-handle {
    cursor: grab;
    color: var(--color-text-maxcontrast);
    display: flex;
    align-items: center;
    padding: 4px 0;
}

.room-type-handle:active {
    cursor: grabbing;
}

.room-type-input {
    flex: 1;
    max-width: 300px;
    padding: 8px 12px;
    border: 2px solid var(--color-border-maxcontrast);
    border-radius: var(--border-radius-large);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 14px;
}

.room-type-input:focus {
    border-color: var(--color-primary-element);
    outline: none;
}

.room-type-id {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    font-family: monospace;
    min-width: 120px;
}

.room-type-add {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
}

/* About info */
.about-info {
    margin-top: 24px;
    padding: 20px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius-large);
    border-left: 4px solid var(--color-primary-element);
}

.about-info h4 {
    margin: 0 0 12px 0;
    font-size: 16px;
    font-weight: 600;
    color: var(--color-main-text);
}

.about-info p {
    margin: 0 0 12px 0;
    color: var(--color-main-text);
    line-height: 1.5;
}

.about-info p:last-child {
    margin-bottom: 0;
}

/* Telemetry section */
.telemetry-settings {
    margin-top: 20px;
}

.engagement-option {
    padding: 8px 0;
}

.option-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.option-label {
    font-weight: 500;
    color: var(--color-main-text);
}

.option-desc {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}

.telemetry-info {
    margin-top: 16px;
}

.telemetry-details {
    margin-top: 24px;
    padding: 16px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius-large);
}

.telemetry-details h4 {
    margin: 0 0 12px 0;
    font-size: 14px;
    font-weight: 600;
    color: var(--color-main-text);
}

.telemetry-details h4:not(:first-child) {
    margin-top: 20px;
}

.telemetry-details ul {
    margin: 0;
    padding-left: 24px;
    color: var(--color-text-maxcontrast);
}

.telemetry-details ul li {
    margin-bottom: 6px;
    line-height: 1.4;
}

.telemetry-details ul.not-collected {
    list-style: none;
    padding-left: 0;
}

.telemetry-details ul.not-collected li {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    color: var(--color-main-text);
}

.telemetry-details ul.not-collected li::before {
    content: '✓';
    color: var(--color-success, #2d7b43);
    font-weight: 600;
    flex-shrink: 0;
}
</style>
