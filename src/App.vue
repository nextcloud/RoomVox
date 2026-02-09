<template>
    <NcContent app-name="resavox">
        <NcAppNavigation>
            <template #list>
                <NcAppNavigationItem
                    :name="'Rooms'"
                    :class="{ active: isTabActive('rooms') }"
                    @click="onTabClick('rooms')">
                    <template #icon>
                        <DoorOpen :size="20" />
                    </template>
                    <template #counter>
                        <NcCounterBubble v-if="rooms.length > 0">
                            {{ rooms.length }}
                        </NcCounterBubble>
                    </template>
                </NcAppNavigationItem>
                <NcAppNavigationItem
                    :name="'Bookings'"
                    :class="{ active: isTabActive('bookings') }"
                    @click="onTabClick('bookings')">
                    <template #icon>
                        <CalendarCheck :size="20" />
                    </template>
                </NcAppNavigationItem>
            </template>
            <template #footer>
                <NcAppNavigationItem
                    :name="'Settings'"
                    :class="{ active: isTabActive('settings') }"
                    @click="onTabClick('settings')">
                    <template #icon>
                        <Cog :size="20" />
                    </template>
                </NcAppNavigationItem>
            </template>
        </NcAppNavigation>

        <NcAppContent>
            <div class="resavox-content">
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
                    @refresh="loadRooms" />

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
                <BookingOverview
                    v-if="currentView === 'bookings'"
                    :rooms="rooms" />

                <!-- Settings -->
                <div v-if="currentView === 'settings'" class="resavox-settings">
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
        </NcAppContent>
    </NcContent>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import DoorOpen from 'vue-material-design-icons/DoorOpen.vue'
import CalendarCheck from 'vue-material-design-icons/CalendarCheck.vue'
import Cog from 'vue-material-design-icons/Cog.vue'

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
.resavox-content {
    padding: 24px 32px;
    max-width: 900px;
}

.resavox-settings {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
</style>
