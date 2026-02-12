<template>
    <NcContent app-name="roomvox">
        <NcAppContent>
            <div class="roomvox-app">
                <!-- Tab Navigation - IntraVox/FormVox style -->
                <nav class="tab-navigation">
                    <button
                        :class="['tab-button', { active: isTabActive('rooms') }]"
                        @click="onTabClick('rooms')">
                        <DoorOpen :size="16" />
                        Rooms
                        <NcCounterBubble v-if="rooms.length > 0" :count="rooms.length" />
                    </button>
                    <button
                        :class="['tab-button', { active: isTabActive('bookings') }]"
                        @click="onTabClick('bookings')">
                        <CalendarCheck :size="16" />
                        Bookings
                    </button>
                    <button
                        :class="['tab-button', { active: isTabActive('statistics') }]"
                        @click="onTabClick('statistics')">
                        <ChartBox :size="16" />
                        Statistics
                    </button>
                    <button
                        :class="['tab-button', { active: isTabActive('settings') }]"
                        @click="onTabClick('settings')">
                        <Cog :size="16" />
                        Settings
                    </button>
                </nav>

                <!-- Content -->
                <div class="roomvox-content">
                <!-- Room list -->
                <RoomList
                    v-if="currentView === 'rooms' && !selectedRoom && !creatingRoom && !selectedRoomGroup && !creatingRoomGroup"
                    :rooms="rooms"
                    :room-groups="roomGroups"
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
                    <!-- Room Statistics Section -->
                    <div class="settings-section">
                        <h2>Room Statistics</h2>
                        <p class="settings-section-desc">Overview of rooms and bookings in your RoomVox installation.</p>

                        <div class="stats-overview">
                            <div class="stat-row">
                                <div class="stat-info">
                                    <span class="stat-icon">🚪</span>
                                    <span class="stat-label">Total Rooms</span>
                                </div>
                                <span class="stat-value">{{ rooms.length }}</span>
                            </div>
                            <div class="stat-row">
                                <div class="stat-info">
                                    <span class="stat-icon">✅</span>
                                    <span class="stat-label">Active Rooms</span>
                                </div>
                                <span class="stat-value">{{ rooms.filter(r => r.active !== false).length }}</span>
                            </div>
                            <div class="stat-row">
                                <div class="stat-info">
                                    <span class="stat-icon">📁</span>
                                    <span class="stat-label">Room Groups</span>
                                </div>
                                <span class="stat-value">{{ roomGroups.length }}</span>
                            </div>
                        </div>

                        <!-- About RoomVox -->
                        <div class="about-info">
                            <h4>About RoomVox</h4>
                            <p>RoomVox is open source room booking software for Nextcloud. We aim to keep RoomVox free and accessible for everyone.</p>
                            <p>Anonymous usage statistics help us understand how RoomVox is used and guide future development.</p>
                        </div>
                    </div>

                    <!-- Telemetry Section -->
                    <div class="settings-section">
                        <h2>Anonymous Usage Statistics</h2>
                        <p class="settings-section-desc">Help improve RoomVox by sharing anonymous usage statistics.</p>

                        <div class="telemetry-settings">
                            <div class="engagement-option">
                                <NcCheckboxRadioSwitch
                                    type="switch"
                                    :model-value="telemetryEnabled"
                                    @update:model-value="toggleTelemetry($event)">
                                    <div class="option-info">
                                        <span class="option-label">Share anonymous usage statistics</span>
                                        <span class="option-desc">We collect: room counts, booking counts, and version info (RoomVox, Nextcloud, PHP). No personal data or booking details are shared.</span>
                                    </div>
                                </NcCheckboxRadioSwitch>
                            </div>

                            <div v-if="telemetryEnabled" class="telemetry-info">
                                <NcNoteCard type="success">
                                    <p>Thank you for helping improve RoomVox!</p>
                                    <p v-if="telemetryLastReport">Last report sent: {{ telemetryLastReport }}</p>
                                </NcNoteCard>
                            </div>

                            <div class="telemetry-details">
                                <h4>What we collect:</h4>
                                <ul>
                                    <li>Number of rooms and room groups</li>
                                    <li>Number of bookings</li>
                                    <li>RoomVox, Nextcloud, and PHP version numbers</li>
                                    <li>A unique hash of your instance URL (privacy-friendly identifier)</li>
                                </ul>
                                <h4>What we never collect:</h4>
                                <ul class="not-collected">
                                    <li>Room names or descriptions</li>
                                    <li>Booking details or attendees</li>
                                    <li>User names or email addresses</li>
                                    <li>Your actual server URL</li>
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
                    <NcNoteCard v-if="settingsSaved" type="success">
                        {{ $t('Settings saved') }}
                    </NcNoteCard>
                    </div>
                </div>
            </div>
        </NcAppContent>
    </NcContent>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import DoorOpen from 'vue-material-design-icons/DoorOpen.vue'
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
const settings = ref({ defaultAutoAccept: false, emailEnabled: true })
const settingsSaved = ref(false)
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
        showError('Failed to load rooms')
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
            showSuccess('Room created')
        } else {
            await updateRoom(selectedRoom.value.id, roomData)
            showSuccess('Room updated')
        }
        selectedRoom.value = null
        creatingRoom.value = false
        await loadRooms()
    } catch (e) {
        showError('Failed to save room: ' + (e.response?.data?.error || e.message))
    }
}

const onDeleteRoom = async (roomId) => {
    try {
        await deleteRoom(roomId)
        showSuccess('Room deleted')
        selectedRoom.value = null
        await loadRooms()
    } catch (e) {
        showError('Failed to delete room')
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
            showSuccess('Room group created')
        } else {
            await updateRoomGroup(selectedRoomGroup.value.id, groupData)
            showSuccess('Room group updated')
        }
        selectedRoomGroup.value = null
        creatingRoomGroup.value = false
        await loadRooms()
    } catch (e) {
        showError('Failed to save room group: ' + (e.response?.data?.error || e.message))
    }
}

const onDeleteRoomGroup = async (groupId) => {
    try {
        await deleteRoomGroup(groupId)
        showSuccess('Room group deleted')
        selectedRoomGroup.value = null
        await loadRooms()
    } catch (e) {
        showError('Failed to delete room group: ' + (e.response?.data?.error || e.message))
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
        showSuccess(groupId ? 'Room moved to group' : 'Room removed from group')
        await loadRooms()
    } catch (e) {
        showError('Failed to move room: ' + (e.response?.data?.error || e.message))
    }
}

const saveGlobalSettings = async () => {
    try {
        await saveSettings(settings.value)
        settingsSaved.value = true
        setTimeout(() => { settingsSaved.value = false }, 3000)
    } catch (e) {
        showError('Failed to save settings')
    }
}

const toggleTelemetry = async (enabled) => {
    try {
        // TODO: Implement API call to save telemetry setting
        // const response = await axios.post(generateUrl('/apps/roomvox/api/statistics/telemetry'), { enabled })
        telemetryEnabled.value = enabled
        if (enabled) {
            showSuccess('Thank you for helping improve RoomVox!')
        }
    } catch (e) {
        showError('Failed to update telemetry setting')
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
    height: 100%;
    display: flex;
    flex-direction: column;
}

/* Tab Navigation - IntraVox/FormVox style */
.tab-navigation {
    border-bottom: 1px solid var(--color-border);
    display: flex;
    gap: 10px;
    padding: 0 24px;
    background: var(--color-main-background);
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
    flex: 1;
    padding: 24px 32px;
    max-width: 1200px;
    overflow-y: auto;
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
