<template>
    <div class="roombooking-admin">
                <div class="tab-bar">
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        class="tab-bar__item"
                        :class="{ 'tab-bar__item--active': isTabActive(tab.id) }"
                        @click="onTabClick(tab.id)">
                        <component :is="tab.icon" :size="18" />
                        <span>{{ tab.label }}</span>
                    </button>
                </div>

                <div class="tab-content">
                    <RoomList
                        v-if="currentView === 'rooms' && !selectedRoom && !creatingRoom"
                        :rooms="rooms"
                        :loading="loadingRooms"
                        @select="onSelectRoom"
                        @create="creatingRoom = true"
                        @refresh="loadRooms" />

                    <RoomEditor
                        v-if="currentView === 'rooms' && (selectedRoom || creatingRoom)"
                        :room="selectedRoom"
                        :creating="creatingRoom"
                        @save="onSaveRoom"
                        @cancel="selectedRoom = null; creatingRoom = false"
                        @delete="onDeleteRoom"
                        @manage-permissions="onManagePermissions" />

                    <PermissionEditor
                        v-if="currentView === 'permissions' && selectedRoom"
                        :room="selectedRoom"
                        @back="currentView = 'rooms'" />

                    <BookingOverview
                        v-if="currentView === 'bookings'"
                        :rooms="rooms" />

                    <div v-if="currentView === 'settings'" class="roombooking-settings">
                        <div class="settings-card">
                            <h3>{{ $t('General') }}</h3>
                            <div class="settings-options">
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
                            </div>
                        </div>
                        <NcNoteCard v-if="settingsSaved" type="success">
                            {{ $t('Settings saved') }}
                        </NcNoteCard>
                    </div>
                </div>
    </div>
</template>

<script setup>
import { ref, onMounted, markRaw } from 'vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import DoorOpen from 'vue-material-design-icons/DoorOpen.vue'
import CalendarCheck from 'vue-material-design-icons/CalendarCheck.vue'
import Cog from 'vue-material-design-icons/Cog.vue'

import RoomList from './views/RoomList.vue'
import RoomEditor from './views/RoomEditor.vue'
import PermissionEditor from './views/PermissionEditor.vue'
import BookingOverview from './views/BookingOverview.vue'

import { getRooms, createRoom, updateRoom, deleteRoom, getSettings, saveSettings } from './services/api.js'

const tabs = [
    { id: 'rooms', label: 'Rooms', icon: markRaw(DoorOpen) },
    { id: 'bookings', label: 'Bookings', icon: markRaw(CalendarCheck) },
    { id: 'settings', label: 'Settings', icon: markRaw(Cog) },
]

const currentView = ref('rooms')
const rooms = ref([])
const selectedRoom = ref(null)
const creatingRoom = ref(false)
const loadingRooms = ref(true)
const settings = ref({ defaultAutoAccept: false, emailEnabled: true })
const settingsSaved = ref(false)

const isTabActive = (tabId) => {
    if (tabId === 'rooms') return currentView.value === 'rooms' || currentView.value === 'permissions'
    return currentView.value === tabId
}

const onTabClick = (tabId) => {
    currentView.value = tabId
    if (tabId === 'rooms') {
        selectedRoom.value = null
        creatingRoom.value = false
    }
}

const loadRooms = async () => {
    loadingRooms.value = true
    try {
        const response = await getRooms()
        rooms.value = response.data
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
    selectedRoom.value = room
    currentView.value = 'permissions'
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

onMounted(() => {
    loadRooms()
    loadSettings()
})
</script>

<style scoped>
.roombooking-admin {
    padding: 20px 0;
}

.tab-bar {
    display: flex;
    gap: 4px;
    border-bottom: 2px solid var(--color-border);
    margin-bottom: 24px;
}

.tab-bar__item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border: none;
    background: none;
    color: var(--color-text-maxcontrast);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    border-radius: var(--border-radius) var(--border-radius) 0 0;
    transition: color 0.15s, border-color 0.15s, background 0.15s;
}

.tab-bar__item:hover {
    color: var(--color-main-text);
    background: var(--color-background-hover);
}

.tab-bar__item--active {
    color: var(--color-primary-element);
    border-bottom-color: var(--color-primary-element);
    font-weight: 600;
}

.settings-card {
    background: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-large);
    padding: 20px;
    margin-bottom: 16px;
}

.settings-card h3 {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 16px;
}

.settings-options {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
</style>

