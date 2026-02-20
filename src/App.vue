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
                :class="['tab-button', { active: isTabActive('import-export') }]"
                @click="onTabClick('import-export')">
                <SwapHorizontal :size="16" />
                {{ $t('Import / Export') }}
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
                :facilities="settings.facilities"
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

            <!-- Import / Export -->
            <div v-if="currentView === 'import-export'" class="tab-content">
                <div class="import-export-tab">
                    <div class="settings-section">
                        <h2>{{ $t('Export Rooms') }}</h2>
                        <p class="settings-section-desc">{{ $t('Download all rooms as a CSV file. This file can be imported into another RoomVox instance or edited in Excel/LibreOffice.') }}</p>
                        <NcButton type="secondary" @click="handleExport">
                            <template #icon>
                                <Download :size="20" />
                            </template>
                            {{ $t('Export CSV') }}
                        </NcButton>
                    </div>

                    <div class="settings-section">
                        <h2>{{ $t('Import Rooms') }}</h2>
                        <p class="settings-section-desc">{{ $t('Upload a CSV file to import rooms. RoomVox and MS365 formats are supported.') }}</p>

                        <!-- Upload area (step 1) -->
                        <div v-if="importStep === 'upload'" class="import-inline">
                            <div class="upload-area"
                                 :class="{ 'upload-area--drag': isDraggingImport }"
                                 @dragover.prevent="isDraggingImport = true"
                                 @dragleave="isDraggingImport = false"
                                 @drop.prevent="handleImportDrop">
                                <Upload :size="48" class="upload-icon" />
                                <p>{{ $t('Drag and drop a CSV file here') }}</p>
                                <p class="upload-or">{{ $t('or') }}</p>
                                <NcButton type="secondary" @click="$refs.importFileInput.click()">
                                    {{ $t('Choose file') }}
                                </NcButton>
                                <input
                                    ref="importFileInput"
                                    type="file"
                                    accept=".csv,text/csv"
                                    class="hidden-input"
                                    @change="handleImportFileSelect" />
                            </div>

                            <div v-if="importError" class="import-error">
                                <AlertCircle :size="16" />
                                {{ importError }}
                            </div>

                            <div class="import-help">
                                <h3>{{ $t('Supported formats') }}</h3>
                                <ul>
                                    <li><strong>RoomVox CSV</strong> — {{ $t('Exported from another RoomVox installation') }}</li>
                                    <li><strong>Microsoft 365 / Exchange</strong> — {{ $t('Exported via PowerShell (Get-EXOMailbox | Get-Place | Export-Csv)') }}</li>
                                </ul>
                                <p class="import-help-note">{{ $t('Column names are automatically detected and mapped.') }}</p>
                                <div class="sample-download">
                                    <NcButton type="tertiary" @click="handleDownloadSample">
                                        <template #icon>
                                            <Download :size="20" />
                                        </template>
                                        {{ $t('Download sample CSV') }}
                                    </NcButton>
                                    <span class="sample-desc">{{ $t('Download an example file with headers and a sample row') }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Preview (step 2) -->
                        <div v-if="importStep === 'preview'" class="import-inline">
                            <div class="preview-info">
                                <p>
                                    {{ $t('Detected format:') }}
                                    <strong>{{ importFormatLabel }}</strong>
                                </p>
                                <p>
                                    {{ $t('{count} rooms found', { count: importPreviewData.rows.length }) }}
                                    —
                                    {{ $t('{create} new, {update} existing, {errors} errors', {
                                        create: importCreateCount,
                                        update: importUpdateCount,
                                        errors: importErrorCount
                                    }) }}
                                </p>
                            </div>

                            <div class="preview-table-wrap">
                                <table class="preview-table">
                                    <thead>
                                        <tr>
                                            <th>{{ $t('Action') }}</th>
                                            <th>{{ $t('Name') }}</th>
                                            <th>{{ $t('Email') }}</th>
                                            <th>{{ $t('Capacity') }}</th>
                                            <th>{{ $t('Building') }}</th>
                                            <th>{{ $t('Facilities') }}</th>
                                            <th>{{ $t('Issues') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="row in importPreviewData.rows"
                                            :key="row.line"
                                            :class="{ 'row-error': row.errors.length > 0 }">
                                            <td>
                                                <NcChip
                                                    :text="importActionLabel(row)"
                                                    :variant="importActionVariant(row)"
                                                    no-close />
                                            </td>
                                            <td>{{ row.data.name || '—' }}</td>
                                            <td>{{ row.data.email || '—' }}</td>
                                            <td>{{ row.data.capacity || '—' }}</td>
                                            <td>{{ row.data.building || '—' }}</td>
                                            <td>{{ row.data.facilities || '—' }}</td>
                                            <td>
                                                <span v-if="row.errors.length > 0" class="error-text">
                                                    {{ row.errors.join(', ') }}
                                                </span>
                                                <span v-else-if="row.action === 'update'" class="match-text">
                                                    {{ $t('Matches: {name}', { name: row.matchedName }) }}
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="import-mode">
                                <label>{{ $t('Import mode:') }}</label>
                                <div class="mode-options">
                                    <NcCheckboxRadioSwitch
                                        v-model="importMode"
                                        value="create"
                                        name="import-mode"
                                        type="radio">
                                        {{ $t('Only create new rooms (skip existing)') }}
                                    </NcCheckboxRadioSwitch>
                                    <NcCheckboxRadioSwitch
                                        v-model="importMode"
                                        value="update"
                                        name="import-mode"
                                        type="radio">
                                        {{ $t('Create new + update existing rooms') }}
                                    </NcCheckboxRadioSwitch>
                                </div>
                            </div>

                            <div class="import-actions">
                                <NcButton type="tertiary" @click="resetImport">
                                    {{ $t('Back') }}
                                </NcButton>
                                <NcButton type="primary"
                                          :disabled="importErrorCount === importPreviewData.rows.length || importing"
                                          @click="executeImport">
                                    <template v-if="importing" #icon>
                                        <NcLoadingIcon :size="20" />
                                    </template>
                                    {{ importing ? $t('Importing...') : $t('Import') }}
                                </NcButton>
                            </div>
                        </div>

                        <!-- Result (step 3) -->
                        <div v-if="importStep === 'result'" class="import-inline">
                            <div class="result-summary">
                                <div class="result-stat result-stat--success">
                                    <span class="result-stat__number">{{ importResult.created }}</span>
                                    <span class="result-stat__label">{{ $t('Created') }}</span>
                                </div>
                                <div class="result-stat result-stat--info">
                                    <span class="result-stat__number">{{ importResult.updated }}</span>
                                    <span class="result-stat__label">{{ $t('Updated') }}</span>
                                </div>
                                <div class="result-stat result-stat--warning">
                                    <span class="result-stat__number">{{ importResult.skipped }}</span>
                                    <span class="result-stat__label">{{ $t('Skipped') }}</span>
                                </div>
                                <div v-if="importResult.errors.length > 0" class="result-stat result-stat--error">
                                    <span class="result-stat__number">{{ importResult.errors.length }}</span>
                                    <span class="result-stat__label">{{ $t('Errors') }}</span>
                                </div>
                            </div>

                            <div v-if="importResult.errors.length > 0" class="result-errors">
                                <h3>{{ $t('Errors') }}</h3>
                                <ul>
                                    <li v-for="(err, idx) in importResult.errors" :key="idx">
                                        <strong>{{ $t('Line {line}', { line: err.line }) }}:</strong>
                                        {{ err.name }} — {{ err.errors.join(', ') }}
                                    </li>
                                </ul>
                            </div>

                            <div class="import-actions">
                                <NcButton type="primary" @click="resetImport(); loadRooms()">
                                    {{ $t('Done') }}
                                </NcButton>
                            </div>
                        </div>
                    </div>
                </div>
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
                <NcSettingsSection :name="$t('API Tokens')">
                    <p class="section-description">
                        {{ $t('Manage API tokens for external integrations. Tokens allow external systems to access the RoomVox API.') }}
                    </p>

                    <!-- Token list -->
                    <div v-if="apiTokens.length > 0" class="token-list">
                        <table class="token-table">
                            <thead>
                                <tr>
                                    <th>{{ $t('Name') }}</th>
                                    <th>{{ $t('Scope') }}</th>
                                    <th>{{ $t('Rooms') }}</th>
                                    <th>{{ $t('Created') }}</th>
                                    <th>{{ $t('Last used') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="tok in apiTokens" :key="tok.id">
                                    <td class="token-name">{{ tok.name }}</td>
                                    <td>
                                        <NcChip :text="tok.scope" no-close :variant="scopeVariant(tok.scope)" />
                                    </td>
                                    <td>{{ tok.roomIds && tok.roomIds.length > 0 ? tok.roomIds.join(', ') : $t('All rooms') }}</td>
                                    <td>{{ formatDate(tok.createdAt) }}</td>
                                    <td>{{ tok.lastUsedAt ? formatDate(tok.lastUsedAt) : '—' }}</td>
                                    <td>
                                        <NcButton type="tertiary-no-background"
                                                  :aria-label="$t('Delete')"
                                                  @click="onDeleteToken(tok.id)">
                                            <template #icon>
                                                <Close :size="20" />
                                            </template>
                                        </NcButton>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p v-else class="no-tokens">{{ $t('No API tokens created yet.') }}</p>

                    <!-- New token created banner -->
                    <div v-if="newlyCreatedToken" class="new-token-banner">
                        <AlertCircle :size="20" />
                        <div class="new-token-info">
                            <strong>{{ $t('Token created! Copy it now — it will not be shown again.') }}</strong>
                            <div class="new-token-value">
                                <code>{{ newlyCreatedToken }}</code>
                                <NcButton type="tertiary" @click="copyToken">
                                    <template #icon>
                                        <ContentCopy :size="20" />
                                    </template>
                                    {{ tokenCopied ? $t('Copied!') : $t('Copy') }}
                                </NcButton>
                            </div>
                        </div>
                    </div>

                    <!-- Create token form -->
                    <div class="create-token-form">
                        <div class="token-form-row">
                            <input
                                v-model="newTokenName"
                                type="text"
                                class="room-type-input"
                                :placeholder="$t('Token name (e.g. Lobby Display)')" />
                            <select v-model="newTokenScope" class="token-scope-select">
                                <option value="read">read</option>
                                <option value="book">book</option>
                                <option value="admin">admin</option>
                            </select>
                            <NcButton type="secondary"
                                      :disabled="!newTokenName.trim() || creatingToken"
                                      @click="onCreateToken">
                                <template v-if="creatingToken" #icon>
                                    <NcLoadingIcon :size="20" />
                                </template>
                                {{ $t('Create token') }}
                            </NcButton>
                        </div>
                    </div>

                    <div class="token-help">
                        <h4>{{ $t('Scopes') }}</h4>
                        <ul>
                            <li><strong>read</strong> — {{ $t('View rooms, availability, and calendar feed') }}</li>
                            <li><strong>book</strong> — {{ $t('Everything in read + create and cancel bookings') }}</li>
                            <li><strong>admin</strong> — {{ $t('Everything in book + manage rooms and view statistics') }}</li>
                        </ul>
                        <h4>{{ $t('Usage') }}</h4>
                        <code class="token-example">curl -H "Authorization: Bearer rvx_..." {{ apiBaseUrl }}/api/v1/rooms</code>
                    </div>
                </NcSettingsSection>

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

                <NcSettingsSection :name="'Facilities'">
                    <p class="section-description">
                        {{ $t('Configure the available facilities for rooms. Facilities that are in use cannot be deleted.') }}
                    </p>
                    <ul class="room-type-list">
                        <li v-for="(facility, index) in settings.facilities"
                            :key="facility.id"
                            :class="['room-type-item', { 'room-type-item--dragging': facilityDragIndex === index, 'room-type-item--over': facilityDragOverIndex === index && facilityDragIndex !== index }]"
                            draggable="true"
                            @dragstart="onFacilityDragStart(index, $event)"
                            @dragover.prevent="onFacilityDragOver(index)"
                            @dragend="onFacilityDragEnd">
                            <span class="room-type-handle">
                                <DragHorizontalVariant :size="20" />
                            </span>
                            <input
                                type="text"
                                :value="facility.label"
                                class="room-type-input"
                                @change="updateFacilityLabel(index, $event.target.value)" />
                            <span class="room-type-id">{{ facility.id }}</span>
                            <NcButton
                                type="tertiary"
                                :aria-label="$t('Delete')"
                                :disabled="isFacilityInUse(facility.id)"
                                @click="removeFacility(index)">
                                <template #icon>
                                    <Close :size="20" />
                                </template>
                            </NcButton>
                        </li>
                    </ul>
                    <div class="room-type-add">
                        <input
                            type="text"
                            v-model="newFacilityLabel"
                            class="room-type-input"
                            :placeholder="$t('New facility...')"
                            @keyup.enter="addFacility" />
                        <NcButton
                            type="secondary"
                            :aria-label="$t('Add')"
                            :disabled="!newFacilityLabel.trim()"
                            @click="addFacility">
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
import { generateUrl } from '@nextcloud/router'
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
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import RoomList from './views/RoomList.vue'
import RoomEditor from './views/RoomEditor.vue'
import RoomGroupEditor from './views/RoomGroupEditor.vue'
import PermissionEditor from './views/PermissionEditor.vue'
import BookingOverview from './views/BookingOverview.vue'

import {
    getRooms, createRoom, updateRoom, deleteRoom,
    getRoomGroups, createRoomGroup, updateRoomGroup, deleteRoomGroup,
    getSettings, saveSettings,
    exportRoomsUrl, sampleCsvUrl, importPreview as apiImportPreview, importRooms as apiImportRooms,
    getApiTokens, createApiToken, deleteApiToken,
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
const settings = ref({ defaultAutoAccept: false, emailEnabled: true, roomTypes: [], facilities: [] })
const settingsSaved = ref(false)
const newRoomTypeLabel = ref('')
const dragIndex = ref(null)
const dragOverIndex = ref(null)
const newFacilityLabel = ref('')
const facilityDragIndex = ref(null)
const facilityDragOverIndex = ref(null)
const telemetryEnabled = ref(true)
const telemetryLastReport = ref(null)

// API Token state
const apiTokens = ref([])
const newTokenName = ref('')
const newTokenScope = ref('read')
const creatingToken = ref(false)
const newlyCreatedToken = ref(null)
const tokenCopied = ref(false)
const apiBaseUrl = window.location.origin + generateUrl('/apps/roomvox')

// Import/Export state
const importStep = ref('upload')
const isDraggingImport = ref(false)
const importError = ref('')
const importPreviewData = ref({ columns: [], rows: [], detected_format: 'unknown' })
const importMode = ref('create')
const importing = ref(false)
const importResult = ref({ created: 0, updated: 0, skipped: 0, errors: [] })
const importCsvFile = ref(null)

const importFormatLabel = computed(() => {
    const labels = {
        roomvox: 'RoomVox CSV',
        ms365: 'Microsoft 365 / Exchange',
        unknown: t('Unknown format'),
    }
    return labels[importPreviewData.value.detected_format] || importPreviewData.value.detected_format
})

const importCreateCount = computed(() =>
    importPreviewData.value.rows.filter(r => r.action === 'create' && r.errors.length === 0).length
)
const importUpdateCount = computed(() =>
    importPreviewData.value.rows.filter(r => r.action === 'update' && r.errors.length === 0).length
)
const importErrorCount = computed(() =>
    importPreviewData.value.rows.filter(r => r.errors.length > 0).length
)

const importActionLabel = (row) => {
    if (row.errors.length > 0) return t('Error')
    return row.action === 'create' ? t('New') : t('Update')
}

const importActionVariant = (row) => {
    if (row.errors.length > 0) return 'error'
    return row.action === 'create' ? 'success' : 'primary'
}

const handleExport = () => {
    window.location.href = exportRoomsUrl()
}

const handleDownloadSample = () => {
    window.location.href = sampleCsvUrl()
}

const handleImportFileSelect = (event) => {
    const file = event.target.files[0]
    if (file) uploadImportFile(file)
}

const handleImportDrop = (event) => {
    isDraggingImport.value = false
    const file = event.dataTransfer.files[0]
    if (file) uploadImportFile(file)
}

const uploadImportFile = async (file) => {
    importError.value = ''

    if (!file.name.endsWith('.csv') && file.type !== 'text/csv') {
        importError.value = t('Please select a CSV file')
        return
    }

    importCsvFile.value = file

    const formData = new FormData()
    formData.append('file', file)

    try {
        const response = await apiImportPreview(formData)
        importPreviewData.value = response.data

        if (importPreviewData.value.rows.length === 0) {
            importError.value = t('No rooms found in CSV file')
            return
        }

        importStep.value = 'preview'
    } catch (err) {
        importError.value = err.response?.data?.message || t('Failed to parse CSV file')
    }
}

const executeImport = async () => {
    importing.value = true

    const formData = new FormData()
    formData.append('file', importCsvFile.value)
    formData.append('mode', importMode.value)

    try {
        const response = await apiImportRooms(formData)
        importResult.value = response.data
        importStep.value = 'result'
    } catch (err) {
        importError.value = err.response?.data?.message || t('Import failed')
        importStep.value = 'upload'
    } finally {
        importing.value = false
    }
}

const resetImport = () => {
    importStep.value = 'upload'
    importError.value = ''
    importPreviewData.value = { columns: [], rows: [], detected_format: 'unknown' }
    importCsvFile.value = null
    importMode.value = 'create'
}

// API Token handlers
const loadApiTokens = async () => {
    try {
        const response = await getApiTokens()
        apiTokens.value = response.data
    } catch (e) {
        // Tokens only accessible for admins
    }
}

const onCreateToken = async () => {
    creatingToken.value = true
    newlyCreatedToken.value = null
    tokenCopied.value = false
    try {
        const response = await createApiToken({
            name: newTokenName.value.trim(),
            scope: newTokenScope.value,
        })
        newlyCreatedToken.value = response.data.token
        newTokenName.value = ''
        newTokenScope.value = 'read'
        await loadApiTokens()
        showSuccess(t('API token created'))
    } catch (e) {
        showError(t('Failed to create API token') + ': ' + (e.response?.data?.error || e.message))
    } finally {
        creatingToken.value = false
    }
}

const onDeleteToken = async (id) => {
    try {
        await deleteApiToken(id)
        await loadApiTokens()
        showSuccess(t('API token deleted'))
    } catch (e) {
        showError(t('Failed to delete API token'))
    }
}

const copyToken = async () => {
    if (newlyCreatedToken.value) {
        try {
            await navigator.clipboard.writeText(newlyCreatedToken.value)
            tokenCopied.value = true
            setTimeout(() => { tokenCopied.value = false }, 3000)
        } catch {
            showError(t('Failed to copy token'))
        }
    }
}

const scopeVariant = (scope) => {
    return { read: 'primary', book: 'success', admin: 'error' }[scope] || 'primary'
}

const formatDate = (isoString) => {
    if (!isoString) return '—'
    const d = new Date(isoString)
    return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

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
        if (response.data.telemetryEnabled !== undefined) {
            telemetryEnabled.value = response.data.telemetryEnabled
        }
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

// Facility helpers
const isFacilityInUse = (facilityId) => {
    return rooms.value.some(r => (r.facilities || []).includes(facilityId))
}

const addFacility = () => {
    const label = newFacilityLabel.value.trim()
    if (!label) return

    let id = slugify(label)
    const existingIds = settings.value.facilities.map(f => f.id)
    if (existingIds.includes(id)) {
        let i = 2
        while (existingIds.includes(id + '-' + i)) i++
        id = id + '-' + i
    }

    settings.value.facilities.push({ id, label })
    newFacilityLabel.value = ''
    saveGlobalSettings()
}

const removeFacility = (index) => {
    const facility = settings.value.facilities[index]
    if (isFacilityInUse(facility.id)) {
        showError(t('Cannot delete: this facility is in use'))
        return
    }
    settings.value.facilities.splice(index, 1)
    saveGlobalSettings()
}

const updateFacilityLabel = (index, newLabel) => {
    settings.value.facilities[index].label = newLabel
    saveGlobalSettings()
}

const onFacilityDragStart = (index, event) => {
    facilityDragIndex.value = index
    event.dataTransfer.effectAllowed = 'move'
}

const onFacilityDragOver = (index) => {
    facilityDragOverIndex.value = index
}

const onFacilityDragEnd = () => {
    if (facilityDragIndex.value !== null && facilityDragOverIndex.value !== null && facilityDragIndex.value !== facilityDragOverIndex.value) {
        const items = settings.value.facilities
        const [moved] = items.splice(facilityDragIndex.value, 1)
        items.splice(facilityDragOverIndex.value, 0, moved)
        saveGlobalSettings()
    }
    facilityDragIndex.value = null
    facilityDragOverIndex.value = null
}

const toggleTelemetry = async (enabled) => {
    try {
        await saveSettings({ telemetryEnabled: enabled })
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
    loadApiTokens()
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

/* API Token management */
.token-list {
    margin-bottom: 20px;
}

.token-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.token-table th {
    text-align: left;
    padding: 8px 12px;
    font-weight: 600;
    border-bottom: 2px solid var(--color-border);
    white-space: nowrap;
}

.token-table td {
    padding: 8px 12px;
    border-bottom: 1px solid var(--color-border);
}

.token-name {
    font-weight: 500;
}

.no-tokens {
    color: var(--color-text-maxcontrast);
    font-style: italic;
    margin-bottom: 16px;
}

.new-token-banner {
    display: flex;
    gap: 12px;
    padding: 16px;
    margin: 16px 0;
    background: var(--color-warning-hover, #fff3e0);
    border-radius: var(--border-radius-large);
    border-left: 4px solid var(--color-warning, #e65100);
}

.new-token-info {
    flex: 1;
}

.new-token-value {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
}

.new-token-value code {
    padding: 8px 12px;
    background: var(--color-background-dark);
    border-radius: var(--border-radius);
    font-size: 13px;
    word-break: break-all;
    flex: 1;
}

.create-token-form {
    margin: 16px 0;
}

.token-form-row {
    display: flex;
    align-items: center;
    gap: 8px;
}

.token-scope-select {
    padding: 8px 12px;
    border: 2px solid var(--color-border-maxcontrast);
    border-radius: var(--border-radius-large);
    background: var(--color-main-background);
    color: var(--color-main-text);
    font-size: 14px;
}

.token-help {
    margin-top: 20px;
    padding: 16px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius-large);
}

.token-help h4 {
    margin: 0 0 8px 0;
    font-size: 14px;
    font-weight: 600;
}

.token-help h4:not(:first-child) {
    margin-top: 16px;
}

.token-help ul {
    margin: 0;
    padding-left: 20px;
}

.token-help ul li {
    margin-bottom: 4px;
    line-height: 1.5;
}

.token-example {
    display: block;
    margin-top: 8px;
    padding: 8px 12px;
    background: var(--color-background-dark);
    border-radius: var(--border-radius);
    font-size: 12px;
    word-break: break-all;
}

/* Import / Export tab */
.import-export-tab {
    max-width: 900px;
}

.import-inline {
    margin-top: 16px;
}

.upload-area {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 48px 24px;
    border: 2px dashed var(--color-border);
    border-radius: var(--border-radius-large);
    text-align: center;
    transition: border-color 0.2s, background-color 0.2s;
}

.upload-area--drag {
    border-color: var(--color-primary);
    background-color: var(--color-primary-element-light);
}

.upload-icon {
    color: var(--color-text-maxcontrast);
}

.upload-or {
    color: var(--color-text-maxcontrast);
    font-size: 13px;
}

.hidden-input {
    display: none;
}

.import-error {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 16px;
    padding: 12px;
    background: var(--color-error-hover);
    border-radius: var(--border-radius);
    color: var(--color-error-text);
}

.import-help {
    margin-top: 24px;
    padding: 16px 20px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius-large);
}

.import-help h3 {
    font-size: 15px;
    font-weight: 600;
    margin: 0 0 12px 0;
}

.import-help ul {
    margin: 0;
    padding-left: 20px;
}

.import-help ul li {
    margin-bottom: 6px;
    line-height: 1.5;
}

.import-help-note {
    margin-top: 12px;
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.sample-download {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--color-border);
}

.sample-desc {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
}

.preview-info {
    margin-bottom: 16px;
    color: var(--color-text-maxcontrast);
}

.preview-info p {
    margin: 4px 0;
}

.preview-table-wrap {
    max-height: 400px;
    overflow: auto;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius);
    margin-bottom: 20px;
}

.preview-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.preview-table th {
    position: sticky;
    top: 0;
    background: var(--color-background-dark);
    text-align: left;
    padding: 8px 12px;
    font-weight: 600;
    border-bottom: 1px solid var(--color-border);
    white-space: nowrap;
}

.preview-table td {
    padding: 8px 12px;
    border-bottom: 1px solid var(--color-border);
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.row-error {
    background: var(--color-error-hover);
}

.error-text {
    color: var(--color-error);
    font-size: 12px;
}

.match-text {
    color: var(--color-text-maxcontrast);
    font-size: 12px;
}

.import-mode {
    margin-bottom: 20px;
}

.import-mode label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
}

.mode-options {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.import-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

.result-summary {
    display: flex;
    gap: 16px;
    margin: 24px 0;
    flex-wrap: wrap;
}

.result-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 16px 24px;
    border-radius: var(--border-radius-large);
    min-width: 100px;
}

.result-stat--success {
    background: var(--color-success-hover, #e8f5e9);
}

.result-stat--info {
    background: var(--color-info-hover, #e3f2fd);
}

.result-stat--warning {
    background: var(--color-warning-hover, #fff3e0);
}

.result-stat--error {
    background: var(--color-error-hover, #fce4ec);
}

.result-stat__number {
    font-size: 28px;
    font-weight: 700;
}

.result-stat__label {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    margin-top: 4px;
}

.result-errors {
    margin-bottom: 20px;
}

.result-errors h3 {
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 8px;
}

.result-errors ul {
    list-style: none;
    padding: 0;
}

.result-errors li {
    padding: 6px 0;
    border-bottom: 1px solid var(--color-border);
    font-size: 13px;
}
</style>
