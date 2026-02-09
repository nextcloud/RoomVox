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
import PermissionEditor from './views/PermissionEditor.vue'
import BookingOverview from './views/BookingOverview.vue'

import { getRooms, createRoom, updateRoom, deleteRoom, getSettings, saveSettings } from './services/api.js'

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
