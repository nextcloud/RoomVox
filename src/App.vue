<template>
    <div class="roomvox-app">
        <!-- Sits above the tabs so it is visible on every tab, not only on
             Support. Deliberately not dismissible: it states a fact about this
             installation rather than interrupting a task, and a dismissal we
             did not remember would be worse than none at all. Left out on
             Support itself, which shows the same notice in its own pane. -->
        <NcNoteCard v-if="subscriptionBanner && currentView !== 'support'" type="info" class="subscription-banner">
            {{ subscriptionBanner }}
            <NcButton variant="tertiary"
                @click="onTabClick('support')">
                {{ t('roomvox', 'Learn more') }}
            </NcButton>
        </NcNoteCard>

        <PaneSwitcher v-model="activeTab"
            :label="t('roomvox', 'RoomVox administration')"
            :panes="tabs" />

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
                :read-only="false"
                @back="currentView = 'rooms'; permissionTarget = null" />

            <!-- Bookings -->
            <div v-if="currentView === 'bookings'" class="tab-content">
                <BookingOverview :rooms="rooms" :room-groups="roomGroups" :show-weekends="settings.showWeekends" />
            </div>

            <!-- Import / Export -->
            <div v-if="currentView === 'import-export'" class="tab-content">
                <NcSettingsSection :name="t('roomvox', 'Export rooms')"
                    :description="t('roomvox', 'Download all rooms as a CSV file. This file can be imported into another RoomVox instance or edited in Excel/LibreOffice.')">
                    <NcButton variant="secondary" @click="handleExport">
                        <template #icon>
                            <Download :size="20" />
                        </template>
                        {{ t('roomvox', 'Export CSV') }}
                    </NcButton>
                </NcSettingsSection>

                <NcSettingsSection :name="t('roomvox', 'Import rooms')"
                    :description="t('roomvox', 'Upload a CSV file to import rooms. RoomVox and MS365 formats are supported.')">
                    <!-- Upload area (step 1) -->
                    <div v-if="importStep === 'upload'" class="import-inline">
                        <div class="upload-area"
                             :class="{ 'upload-area--drag': isDraggingImport }"
                             @dragover.prevent="isDraggingImport = true"
                             @dragleave="isDraggingImport = false"
                             @drop.prevent="handleImportDrop">
                            <Upload :size="48" class="upload-icon" />
                            <p>{{ t('roomvox', 'Drag and drop a CSV file here') }}</p>
                            <p class="upload-or">{{ t('roomvox', 'or') }}</p>
                            <NcButton variant="secondary" @click="$refs.importFileInput.click()">
                                {{ t('roomvox', 'Choose file') }}
                            </NcButton>
                            <!-- Driven by the button above, so off the tab path
                                 but still named (design guidelines §8). -->
                            <input
                                ref="importFileInput"
                                type="file"
                                accept=".csv,text/csv"
                                class="hidden-input"
                                tabindex="-1"
                                :aria-label="t('roomvox', 'Choose a CSV file to import')"
                                @change="handleImportFileSelect" />
                        </div>

                        <NcNoteCard v-if="importError" type="error">
                            {{ importError }}
                        </NcNoteCard>

                        <div class="import-help">
                            <h3>{{ t('roomvox', 'Supported formats') }}</h3>
                            <ul>
                                <li><strong>RoomVox CSV</strong> — {{ t('roomvox', 'Exported from another RoomVox installation') }}</li>
                                <li><strong>Microsoft 365 / Exchange</strong> — {{ t('roomvox', 'Exported via PowerShell (Get-EXOMailbox | Get-Place | Export-Csv)') }}</li>
                            </ul>
                            <p class="import-help-note">{{ t('roomvox', 'Column names are automatically detected and mapped.') }}</p>
                            <div class="sample-download">
                                <NcButton variant="tertiary" @click="handleDownloadSample">
                                    <template #icon>
                                        <Download :size="20" />
                                    </template>
                                    {{ t('roomvox', 'Download sample CSV') }}
                                </NcButton>
                                <span class="sample-desc">{{ t('roomvox', 'Download an example file with headers and a sample row') }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Preview (step 2) -->
                    <div v-if="importStep === 'preview'" class="import-inline">
                        <div class="preview-info">
                            <p>
                                {{ t('roomvox', 'Detected format:') }}
                                <strong>{{ importFormatLabel }}</strong>
                            </p>
                            <p>
                                {{ t('roomvox', '{count} rooms found', { count: importPreviewData.rows.length }) }}
                                —
                                {{ t('roomvox', '{create} new, {update} existing, {errors} errors', {
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
                                        <th>{{ t('roomvox', 'Action') }}</th>
                                        <th>{{ t('roomvox', 'Name') }}</th>
                                        <th>{{ t('roomvox', 'Email') }}</th>
                                        <th>{{ t('roomvox', 'Capacity') }}</th>
                                        <th>{{ t('roomvox', 'Building') }}</th>
                                        <th>{{ t('roomvox', 'Facilities') }}</th>
                                        <th>{{ t('roomvox', 'Issues') }}</th>
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
                                                {{ t('roomvox', 'Matches: {name}', { name: row.matchedName }, asText) }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- A bare <label> names neither radio; a legend names both. -->
                        <fieldset class="import-mode">
                            <legend>{{ t('roomvox', 'Import mode:') }}</legend>
                            <div class="mode-options">
                                <NcCheckboxRadioSwitch
                                    v-model="importMode"
                                    value="create"
                                    name="import-mode"
                                    type="radio">
                                    {{ t('roomvox', 'Only create new rooms (skip existing)') }}
                                </NcCheckboxRadioSwitch>
                                <NcCheckboxRadioSwitch
                                    v-model="importMode"
                                    value="update"
                                    name="import-mode"
                                    type="radio">
                                    {{ t('roomvox', 'Create new + update existing rooms') }}
                                </NcCheckboxRadioSwitch>
                            </div>
                        </fieldset>

                        <div v-if="importPreviewData.detected_format === 'ms365' && importHasEmails" class="import-mode">
                            <NcCheckboxRadioSwitch
                                :model-value="importEnableExchangeSync"
                                @update:model-value="importEnableExchangeSync = $event">
                                {{ t('roomvox', 'Enable Exchange calendar sync for imported rooms') }}
                            </NcCheckboxRadioSwitch>
                            <p class="import-help-note">
                                {{ t('roomvox', 'Links each room to its MS365 mailbox for bidirectional calendar sync. Requires Exchange sync to be configured in settings.') }}
                            </p>
                        </div>

                        <div class="import-actions">
                            <NcButton variant="tertiary" @click="resetImport">
                                {{ t('roomvox', 'Back') }}
                            </NcButton>
                            <NcButton variant="primary"
                                      :disabled="importErrorCount === importPreviewData.rows.length || importing"
                                      @click="executeImport">
                                <template v-if="importing" #icon>
                                    <NcLoadingIcon :size="20" />
                                </template>
                                {{ importing ? t('roomvox', 'Importing …') : t('roomvox', 'Import') }}
                            </NcButton>
                        </div>
                    </div>

                    <!-- Result (step 3) -->
                    <div v-if="importStep === 'result'" class="import-inline">
                        <div class="result-summary">
                            <div class="result-stat result-stat--success">
                                <span class="result-stat__number">{{ importResult.created }}</span>
                                <span class="result-stat__label">{{ t('roomvox', 'Created') }}</span>
                            </div>
                            <div class="result-stat result-stat--info">
                                <span class="result-stat__number">{{ importResult.updated }}</span>
                                <span class="result-stat__label">{{ t('roomvox', 'Updated') }}</span>
                            </div>
                            <div class="result-stat result-stat--warning">
                                <span class="result-stat__number">{{ importResult.skipped }}</span>
                                <span class="result-stat__label">{{ t('roomvox', 'Skipped') }}</span>
                            </div>
                            <div v-if="importResult.errors.length > 0" class="result-stat result-stat--error">
                                <span class="result-stat__number">{{ importResult.errors.length }}</span>
                                <span class="result-stat__label">{{ t('roomvox', 'Errors') }}</span>
                            </div>
                        </div>

                        <div v-if="importResult.errors.length > 0" class="result-errors">
                            <h3>{{ t('roomvox', 'Errors') }}</h3>
                            <ul>
                                <li v-for="(err, idx) in importResult.errors" :key="idx">
                                    <strong>{{ t('roomvox', 'Line {line}', { line: err.line }) }}:</strong>
                                    {{ err.name }} — {{ err.errors.join(', ') }}
                                </li>
                            </ul>
                        </div>

                        <div class="import-actions">
                            <NcButton variant="primary" @click="resetImport(); loadRooms()">
                                {{ t('roomvox', 'Done') }}
                            </NcButton>
                        </div>
                    </div>
                </NcSettingsSection>
            </div>

            <!-- Statistics: local figures only, read-only. Nothing about the
                 subscription or usage statistics here; those are on Support
                 (design guidelines §7, "The Statistics and Support panes"). -->
            <NcSettingsSection v-if="currentView === 'statistics'"
                :name="t('roomvox', 'Room statistics')"
                :description="t('roomvox', 'Rooms and room groups in this RoomVox installation.')">
                <ul class="stat-tiles">
                    <li v-for="tile in statTiles" :key="tile.id" class="stat-tile">
                        <component :is="tile.icon" :size="20" class="stat-tile__icon" aria-hidden="true" />
                        <span class="stat-tile__value">{{ tile.value }}</span>
                        <span class="stat-tile__label">{{ tile.label }}</span>
                    </li>
                </ul>
            </NcSettingsSection>

            <!-- Support -->
            <SupportSettings v-if="currentView === 'support'" />

            <!-- Settings -->
            <div v-if="currentView === 'settings'" class="roomvox-settings">
                <NcSettingsSection :name="t('roomvox', 'API tokens')"
                    :description="t('roomvox', 'Manage API tokens for external integrations. Tokens allow external systems to access the RoomVox API.')">
                    <!-- Token list -->
                    <div v-if="apiTokens.length > 0" class="token-list">
                        <table class="token-table">
                            <thead>
                                <tr>
                                    <th>{{ t('roomvox', 'Name') }}</th>
                                    <th>{{ t('roomvox', 'Scope') }}</th>
                                    <th>{{ t('roomvox', 'Rooms') }}</th>
                                    <th>{{ t('roomvox', 'Created') }}</th>
                                    <th>{{ t('roomvox', 'Last used') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="tok in apiTokens" :key="tok.id">
                                    <td class="token-name">{{ tok.name }}</td>
                                    <td>
                                        <NcChip :text="tok.scope" no-close :variant="scopeVariant(tok.scope)" />
                                    </td>
                                    <td>{{ tok.roomIds && tok.roomIds.length > 0 ? tok.roomIds.join(', ') : t('roomvox', 'All rooms') }}</td>
                                    <td>{{ formatDate(tok.createdAt) }}</td>
                                    <td>{{ tok.lastUsedAt ? formatDate(tok.lastUsedAt) : '—' }}</td>
                                    <td>
                                        <NcButton variant="tertiary-no-background"
                                                  :aria-label="t('roomvox', 'Delete')"
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

                    <p v-else class="no-tokens">{{ t('roomvox', 'No API tokens created yet.') }}</p>

                    <!-- New token created: shown once, so it stays until the next one -->
                    <NcNoteCard v-if="newlyCreatedToken"
                        type="warning"
                        :heading="t('roomvox', 'Token created! Copy it now — it will not be shown again.')">
                        <div class="new-token-value">
                            <code>{{ newlyCreatedToken }}</code>
                            <NcButton variant="tertiary" @click="copyToken">
                                <template #icon>
                                    <ContentCopy :size="20" />
                                </template>
                                {{ tokenCopied ? t('roomvox', 'Copied!') : t('roomvox', 'Copy') }}
                            </NcButton>
                        </div>
                    </NcNoteCard>

                    <!-- Create token form -->
                    <div class="token-form-row">
                        <NcTextField v-model="newTokenName"
                            class="token-form-row__name"
                            :label="t('roomvox', 'Token name')"
                            :placeholder="t('roomvox', 'e.g. Lobby Display')" />
                        <!-- Plain string options: the select emits the string itself -->
                        <NcSelect v-model="newTokenScope"
                            class="token-form-row__scope"
                            input-id="roomvox-token-scope"
                            :input-label="t('roomvox', 'Scope')"
                            :options="TOKEN_SCOPES"
                            :clearable="false"
                            :searchable="false" />
                        <NcButton variant="secondary"
                                  :disabled="!newTokenName.trim() || creatingToken"
                                  @click="onCreateToken">
                            <template v-if="creatingToken" #icon>
                                <NcLoadingIcon :size="20" />
                            </template>
                            {{ t('roomvox', 'Create token') }}
                        </NcButton>
                    </div>

                    <div class="token-help">
                        <h3>{{ t('roomvox', 'Scopes') }}</h3>
                        <ul>
                            <li><strong>read</strong> — {{ t('roomvox', 'View rooms, availability, and calendar feed') }}</li>
                            <li><strong>book</strong> — {{ t('roomvox', 'Everything in read + create and cancel bookings') }}</li>
                            <li><strong>admin</strong> — {{ t('roomvox', 'Everything in book + manage rooms and view statistics') }}</li>
                        </ul>
                        <h3>{{ t('roomvox', 'Usage') }}</h3>
                        <code class="token-example">curl -H "Authorization: Bearer rvx_..." {{ apiBaseUrl }}/api/v1/rooms</code>
                    </div>
                </NcSettingsSection>

                <NcSettingsSection :name="t('roomvox', 'General')">
                    <NcCheckboxRadioSwitch
                        :model-value="settings.defaultAutoAccept"
                        @update:model-value="settings.defaultAutoAccept = $event; saveGlobalSettings()">
                        {{ t('roomvox', 'Auto-accept bookings by default for new rooms') }}
                    </NcCheckboxRadioSwitch>
                    <NcCheckboxRadioSwitch
                        :model-value="settings.emailEnabled"
                        @update:model-value="settings.emailEnabled = $event; saveGlobalSettings()">
                        {{ t('roomvox', 'Enable email notifications') }}
                    </NcCheckboxRadioSwitch>
                    <NcCheckboxRadioSwitch
                        :model-value="settings.showWeekends"
                        @update:model-value="settings.showWeekends = $event; saveGlobalSettings()">
                        {{ t('roomvox', 'Show weekends in calendar') }}
                    </NcCheckboxRadioSwitch>
                </NcSettingsSection>

                <NcSettingsSection :name="t('roomvox', 'Microsoft Exchange sync')"
                    :description="t('roomvox', 'Connect RoomVox to Microsoft 365 Exchange to sync room calendars. Requires an Azure AD app registration with Calendars.ReadWrite and User.Read.All application permissions.')">
                    <NcCheckboxRadioSwitch
                        :model-value="exchangeEnabled"
                        @update:model-value="exchangeEnabled = $event; saveExchangeSettings()">
                        {{ t('roomvox', 'Enable Exchange calendar sync') }}
                    </NcCheckboxRadioSwitch>

                    <div v-if="exchangeEnabled" class="exchange-fields">
                        <!-- Each field saves on change, as the raw inputs did -->
                        <div class="exchange-grid">
                            <NcTextField v-model="exchangeTenantId"
                                :label="t('roomvox', 'Azure AD Tenant ID')"
                                :placeholder="t('roomvox', 'e.g. 12345678-abcd-…')"
                                @change="saveExchangeSettings" />
                            <NcTextField v-model="exchangeClientId"
                                :label="t('roomvox', 'Client ID')"
                                :placeholder="t('roomvox', 'App registration client ID')"
                                @change="saveExchangeSettings" />
                            <NcPasswordField v-model="exchangeClientSecret"
                                :label="t('roomvox', 'Client secret')"
                                :placeholder="exchangeClientSecret === '***' ? t('roomvox', '(saved — enter new value to change)') : t('roomvox', 'App registration client secret')"
                                @change="saveExchangeSettings" />
                            <!-- Bound as text and cast back like v-model.number did,
                                 so an emptied field still saves '' rather than NaN -->
                            <NcTextField :model-value="String(exchangeWebhookMaxInlineSync)"
                                type="number"
                                min="0"
                                max="10"
                                :label="t('roomvox', 'Max inline sync per request')"
                                :placeholder="t('roomvox', 'Rooms per request (default: 1)')"
                                @update:model-value="exchangeWebhookMaxInlineSync = looseToNumber($event)"
                                @change="saveExchangeSettings" />
                            <NcTextField :model-value="String(exchangeWebhookRateLimit)"
                                type="number"
                                min="0"
                                max="100"
                                :label="t('roomvox', 'Rate limit (per 10 sec)')"
                                :placeholder="t('roomvox', 'Max inline syncs per 10 sec (default: 5)')"
                                @update:model-value="exchangeWebhookRateLimit = looseToNumber($event)"
                                @change="saveExchangeSettings" />
                        </div>

                        <div class="exchange-test">
                            <NcButton
                                variant="secondary"
                                :disabled="exchangeTesting || !exchangeTenantId || !exchangeClientId"
                                @click="testExchange">
                                <template v-if="exchangeTesting" #icon>
                                    <NcLoadingIcon :size="20" />
                                </template>
                                {{ exchangeTesting ? t('roomvox', 'Testing …') : t('roomvox', 'Test connection') }}
                            </NcButton>

                            <NcNoteCard v-if="exchangeTestResult" :type="exchangeTestResult.success ? 'success' : 'error'">
                                {{ exchangeTestResult.message }}
                            </NcNoteCard>
                        </div>
                    </div>
                </NcSettingsSection>

                <NcSettingsSection :name="t('roomvox', 'Room types')"
                    :description="t('roomvox', 'Configure the available room types. Types that are in use cannot be deleted.')">
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
                            <!-- One-way: the label is stored on change, as before -->
                            <NcTextField :model-value="type.label"
                                class="room-type-label"
                                label-outside
                                :aria-label="t('roomvox', 'Room type name')"
                                @change="updateRoomTypeLabel(index, $event.target.value)" />
                            <span class="room-type-id">{{ type.id }}</span>
                            <NcButton
                                variant="tertiary"
                                :aria-label="t('roomvox', 'Delete')"
                                :disabled="isRoomTypeInUse(type.id)"
                                @click="removeRoomType(index)">
                                <template #icon>
                                    <Close :size="20" />
                                </template>
                            </NcButton>
                        </li>
                    </ul>
                    <div class="room-type-add">
                        <NcTextField v-model="newRoomTypeLabel"
                            class="room-type-label"
                            :label="t('roomvox', 'New room type')"
                            @keyup.enter="addRoomType" />
                        <NcButton
                            variant="secondary"
                            :aria-label="t('roomvox', 'Add')"
                            :disabled="!newRoomTypeLabel.trim()"
                            @click="addRoomType">
                            <template #icon>
                                <Plus :size="20" />
                            </template>
                        </NcButton>
                    </div>
                </NcSettingsSection>

                <NcSettingsSection :name="t('roomvox', 'Facilities')"
                    :description="t('roomvox', 'Configure the available facilities for rooms. Facilities that are in use cannot be deleted.')">
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
                            <NcTextField :model-value="facility.label"
                                class="room-type-label"
                                label-outside
                                :aria-label="t('roomvox', 'Facility name')"
                                @change="updateFacilityLabel(index, $event.target.value)" />
                            <span class="room-type-id">{{ facility.id }}</span>
                            <NcButton
                                variant="tertiary"
                                :aria-label="t('roomvox', 'Delete')"
                                :disabled="isFacilityInUse(facility.id)"
                                @click="removeFacility(index)">
                                <template #icon>
                                    <Close :size="20" />
                                </template>
                            </NcButton>
                        </li>
                    </ul>
                    <div class="room-type-add">
                        <NcTextField v-model="newFacilityLabel"
                            class="room-type-label"
                            :label="t('roomvox', 'New facility')"
                            @keyup.enter="addFacility" />
                        <NcButton
                            variant="secondary"
                            :aria-label="t('roomvox', 'Add')"
                            :disabled="!newFacilityLabel.trim()"
                            @click="addFacility">
                            <template #icon>
                                <Plus :size="20" />
                            </template>
                        </NcButton>
                    </div>
                </NcSettingsSection>

                <NcNoteCard v-if="settingsSaved" type="success">
                    {{ t('roomvox', 'Settings saved') }}
                </NcNoteCard>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate, getLanguage } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { subscriptionNudge as buildSubscriptionNudge } from './composables/useSubscriptionNudge.js'
import NcButton from '@nextcloud/vue/components/NcButton'
import Door from 'vue-material-design-icons/Door.vue'
import DoorOpen from 'vue-material-design-icons/DoorOpen.vue'
import FolderMultiple from 'vue-material-design-icons/FolderMultiple.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import DragHorizontalVariant from 'vue-material-design-icons/DragHorizontalVariant.vue'
import CalendarCheck from 'vue-material-design-icons/CalendarCheck.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import FileArrowUpDownOutline from 'vue-material-design-icons/FileArrowUpDownOutline.vue'
import ChartBox from 'vue-material-design-icons/ChartBox.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Lifebuoy from 'vue-material-design-icons/Lifebuoy.vue'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import RoomList from './views/RoomList.vue'
import RoomEditor from './views/RoomEditor.vue'
import RoomGroupEditor from './views/RoomGroupEditor.vue'
import PermissionEditor from './views/PermissionEditor.vue'
import BookingOverview from './views/BookingOverview.vue'
import PaneSwitcher from './components/PaneSwitcher.vue'
import SupportSettings from './components/SupportSettings.vue'

import {
    getRooms, createRoom, updateRoom, deleteRoom,
    getRoomGroups, createRoomGroup, updateRoomGroup, deleteRoomGroup,
    getSettings, saveSettings,
    exportRoomsUrl, sampleCsvUrl, importPreview as apiImportPreview, importRooms as apiImportRooms,
    getApiTokens, createApiToken, deleteApiToken,
    testExchangeConnection,
    getLicenseStats,
} from './services/api.js'

// translate() HTML-escapes placeholder values and runs the result through
// DOMPurify by default, both for v-html. Vue escapes again when it renders
// text or binds an attribute, so user data such as a room name would show as
// "&amp;" or lose anything that looks like a tag. Text uses asText instead;
// nothing in the app renders translations with v-html.
const asText = { escape: false, sanitize: false }
const t = (app, text, vars = {}, options = undefined) => translate(app, text, vars, undefined, options)

// Anchors that open the Support pane. #support is the pane itself; the consent
// notification links to #usage-statistics, where SupportSettings opens the
// field list (lib/Notification/Notifier.php).
const SUPPORT_HASHES = ['#support', '#usage-statistics']
const currentView = ref(SUPPORT_HASHES.includes(window.location.hash) ? 'support' : 'rooms')
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

// API Token state
const apiTokens = ref([])
const newTokenName = ref('')
const newTokenScope = ref('read')
// Identifiers the API checks, so not translated (the Scopes list explains them)
const TOKEN_SCOPES = ['read', 'book', 'admin']
const creatingToken = ref(false)
const newlyCreatedToken = ref(null)
const tokenCopied = ref(false)
const apiBaseUrl = window.location.origin + generateUrl('/apps/roomvox')

// What v-model.number did on the raw number inputs: a number when the text
// parses as one, the text itself otherwise, so an emptied field stays ''.
const looseToNumber = (value) => {
    const n = parseFloat(value)
    return isNaN(n) ? value : n
}

// Exchange sync state
const exchangeEnabled = ref(false)
const exchangeTenantId = ref('')
const exchangeClientId = ref('')
const exchangeClientSecret = ref('')
const exchangeWebhookMaxInlineSync = ref(1)
const exchangeWebhookRateLimit = ref(5)
const exchangeTesting = ref(false)
const exchangeTestResult = ref(null)

// Import/Export state
const importStep = ref('upload')
const isDraggingImport = ref(false)
const importError = ref('')
const importPreviewData = ref({ columns: [], rows: [], detected_format: 'unknown' })
const importMode = ref('create')
const importEnableExchangeSync = ref(false)
const importing = ref(false)
const importResult = ref({ created: 0, updated: 0, skipped: 0, errors: [] })
const importCsvFile = ref(null)

const importFormatLabel = computed(() => {
    const labels = {
        roomvox: 'RoomVox CSV',
        ms365: 'Microsoft 365 / Exchange',
        unknown: t('roomvox', 'Unknown format'),
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
const importHasEmails = computed(() =>
    importPreviewData.value.rows.some(r => r.data.email)
)

const importActionLabel = (row) => {
    if (row.errors.length > 0) return t('roomvox', 'Error')
    return row.action === 'create' ? t('roomvox', 'New') : t('roomvox', 'Update')
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
        importError.value = t('roomvox', 'Please select a CSV file')
        return
    }

    importCsvFile.value = file

    const formData = new FormData()
    formData.append('file', file)

    try {
        const response = await apiImportPreview(formData)
        importPreviewData.value = response.data

        if (importPreviewData.value.rows.length === 0) {
            importError.value = t('roomvox', 'No rooms found in CSV file')
            return
        }

        importStep.value = 'preview'
    } catch (err) {
        importError.value = err.response?.data?.message || t('roomvox', 'Failed to parse CSV file')
    }
}

const executeImport = async () => {
    importing.value = true

    const formData = new FormData()
    formData.append('file', importCsvFile.value)
    formData.append('mode', importMode.value)
    if (importEnableExchangeSync.value) {
        formData.append('enableExchangeSync', '1')
    }

    try {
        const response = await apiImportRooms(formData)
        importResult.value = response.data
        importStep.value = 'result'
        // Refresh room list so newly imported rooms appear immediately
        await loadRooms()
    } catch (err) {
        importError.value = err.response?.data?.message || t('roomvox', 'Import failed')
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
    importEnableExchangeSync.value = false
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
        showSuccess(t('roomvox', 'API token created'))
    } catch (e) {
        showError(t('roomvox', 'Failed to create API token') + ': ' + (e.response?.data?.error || e.message))
    } finally {
        creatingToken.value = false
    }
}

const onDeleteToken = async (id) => {
    try {
        await deleteApiToken(id)
        await loadApiTokens()
        showSuccess(t('roomvox', 'API token deleted'))
    } catch (e) {
        showError(t('roomvox', 'Failed to delete API token'))
    }
}

const copyToken = async () => {
    if (newlyCreatedToken.value) {
        try {
            await navigator.clipboard.writeText(newlyCreatedToken.value)
            tokenCopied.value = true
            setTimeout(() => { tokenCopied.value = false }, 3000)
        } catch {
            showError(t('roomvox', 'Failed to copy token'))
        }
    }
}

const scopeVariant = (scope) => {
    return { read: 'primary', book: 'success', admin: 'error' }[scope] || 'primary'
}

const ncLocale = getLanguage().replace('_', '-')

const formatDate = (isoString) => {
    if (!isoString) return '—'
    const d = new Date(isoString)
    return d.toLocaleDateString(ncLocale) + ' ' + d.toLocaleTimeString(ncLocale, { hour: '2-digit', minute: '2-digit' })
}

// Icons follow what is behind each tab, as the design guidelines ask (§7)
const tabs = [
    { id: 'rooms', label: t('roomvox', 'Rooms'), icon: DoorOpen },
    { id: 'bookings', label: t('roomvox', 'Bookings'), icon: CalendarCheck },
    { id: 'import-export', label: t('roomvox', 'Import / export'), icon: FileArrowUpDownOutline },
    { id: 'settings', label: t('roomvox', 'Settings'), icon: Cog },
    { id: 'statistics', label: t('roomvox', 'Statistics'), icon: ChartBox },
    { id: 'support', label: t('roomvox', 'Support'), icon: Lifebuoy },
]

// Statistics: one tile shape for every figure, all counted on this server.
const statTiles = computed(() => [
    { id: 'rooms', icon: Door, value: rooms.value.length, label: t('roomvox', 'Total rooms') },
    { id: 'active', icon: DoorOpen, value: rooms.value.filter(r => r.active !== false).length, label: t('roomvox', 'Active rooms') },
    { id: 'groups', icon: FolderMultiple, value: roomGroups.value.length, label: t('roomvox', 'Room groups') },
])

// Editing a room's permissions happens under the Rooms tab
const activeTab = computed({
    get: () => currentView.value === 'permissions' ? 'rooms' : currentView.value,
    set: (tabId) => onTabClick(tabId),
})

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
        showError(t('roomvox', 'Failed to load rooms'))
    } finally {
        loadingRooms.value = false
    }
}

const loadSettings = async () => {
    try {
        const response = await getSettings()
        settings.value = response.data
        // Load Exchange settings
        if (response.data.exchangeEnabled !== undefined) {
            exchangeEnabled.value = response.data.exchangeEnabled
        }
        if (response.data.exchangeTenantId !== undefined) {
            exchangeTenantId.value = response.data.exchangeTenantId
        }
        if (response.data.exchangeClientId !== undefined) {
            exchangeClientId.value = response.data.exchangeClientId
        }
        if (response.data.exchangeClientSecret !== undefined) {
            exchangeClientSecret.value = response.data.exchangeClientSecret
        }
        if (response.data.exchangeWebhookMaxInlineSync !== undefined) {
            exchangeWebhookMaxInlineSync.value = response.data.exchangeWebhookMaxInlineSync
        }
        if (response.data.exchangeWebhookRateLimit !== undefined) {
            exchangeWebhookRateLimit.value = response.data.exchangeWebhookRateLimit
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
            showSuccess(t('roomvox', 'Room created'))
        } else {
            await updateRoom(selectedRoom.value.id, roomData)
            showSuccess(t('roomvox', 'Room updated'))
        }
        selectedRoom.value = null
        creatingRoom.value = false
        await loadRooms()
    } catch (e) {
        // A taken email address is a field error, not a failed save: keep the
        // editor open so the entered room is not lost.
        if (e.response?.status === 409) {
            const conflictingId = e.response?.data?.conflictingRoomId
            const conflicting = rooms.value.find(r => r.id === conflictingId)
            showError(t('roomvox', 'Room "{room}" already uses this email address', {
                room: conflicting?.name || conflictingId || '?',
            }, asText))
            return
        }
        showError(t('roomvox', 'Failed to save room') + ': ' + (e.response?.data?.error || e.message))
    }
}

const onDeleteRoom = async (roomId) => {
    try {
        await deleteRoom(roomId)
        showSuccess(t('roomvox', 'Room deleted'))
        selectedRoom.value = null
        await loadRooms()
    } catch (e) {
        showError(t('roomvox', 'Failed to delete room'))
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
            showSuccess(t('roomvox', 'Room group created'))
        } else {
            await updateRoomGroup(selectedRoomGroup.value.id, groupData)
            showSuccess(t('roomvox', 'Room group updated'))
        }
        selectedRoomGroup.value = null
        creatingRoomGroup.value = false
        await loadRooms()
    } catch (e) {
        showError(t('roomvox', 'Failed to save room group') + ': ' + (e.response?.data?.error || e.message))
    }
}

const onDeleteRoomGroup = async (groupId) => {
    try {
        await deleteRoomGroup(groupId)
        showSuccess(t('roomvox', 'Room group deleted'))
        selectedRoomGroup.value = null
        await loadRooms()
    } catch (e) {
        showError(t('roomvox', 'Failed to delete room group') + ': ' + (e.response?.data?.error || e.message))
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
        showSuccess(groupId ? t('roomvox', 'Room moved to group') : t('roomvox', 'Room removed from group'))
        await loadRooms()
    } catch (e) {
        showError(t('roomvox', 'Failed to move room') + ': ' + (e.response?.data?.error || e.message))
    }
}

// Exchange settings handlers
const saveExchangeSettings = async () => {
    try {
        await saveSettings({
            exchangeEnabled: exchangeEnabled.value,
            exchangeTenantId: exchangeTenantId.value,
            exchangeClientId: exchangeClientId.value,
            exchangeClientSecret: exchangeClientSecret.value,
            exchangeWebhookMaxInlineSync: exchangeWebhookMaxInlineSync.value,
            exchangeWebhookRateLimit: exchangeWebhookRateLimit.value,
        })
        settingsSaved.value = true
        setTimeout(() => { settingsSaved.value = false }, 3000)
    } catch (e) {
        showError(t('roomvox', 'Failed to save Exchange settings'))
    }
}

const testExchange = async () => {
    exchangeTesting.value = true
    exchangeTestResult.value = null
    try {
        // Save first to ensure latest credentials are used
        await saveSettings({
            exchangeEnabled: exchangeEnabled.value,
            exchangeTenantId: exchangeTenantId.value,
            exchangeClientId: exchangeClientId.value,
            exchangeClientSecret: exchangeClientSecret.value,
            exchangeWebhookMaxInlineSync: exchangeWebhookMaxInlineSync.value,
            exchangeWebhookRateLimit: exchangeWebhookRateLimit.value,
        })
        const response = await testExchangeConnection()
        exchangeTestResult.value = { success: true, message: response.data.message || t('roomvox', 'Connection successful') }
    } catch (e) {
        exchangeTestResult.value = { success: false, message: e.response?.data?.error || t('roomvox', 'Connection failed') }
    } finally {
        exchangeTesting.value = false
    }
}

const saveGlobalSettings = async () => {
    try {
        await saveSettings(settings.value)
        settingsSaved.value = true
        setTimeout(() => { settingsSaved.value = false }, 3000)
    } catch (e) {
        showError(t('roomvox', 'Failed to save settings'))
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
        showError(t('roomvox', 'Cannot delete: this room type is in use'))
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
        showError(t('roomvox', 'Cannot delete: this facility is in use'))
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

// Licence figures for the banner above the tabs. Failing quietly is deliberate:
// the banner is a courtesy, so a stats call that does not come back should leave
// the interface alone rather than show an error the administrator cannot act on.
const licenseStats = ref(null)
const subscriptionBanner = computed(() => buildSubscriptionNudge(licenseStats.value))

async function loadLicenseStats() {
    try {
        const { data } = await getLicenseStats()
        if (data.success) {
            licenseStats.value = data.stats
        }
    } catch (e) {
        // no banner
    }
}

// A notification link followed while this page is already open only changes
// the hash, so follow it here too.
const onHashChange = () => {
    if (SUPPORT_HASHES.includes(window.location.hash)) {
        onTabClick('support')
    }
}

onMounted(() => {
    window.addEventListener('hashchange', onHashChange)
    loadRooms()
    loadSettings()
    loadApiTokens()
    loadLicenseStats()
})

onBeforeUnmount(() => {
    window.removeEventListener('hashchange', onHashChange)
})
</script>

<style scoped>
.roomvox-app {
    padding: 20px;
}

/* Subscription banner — sits above the tabs, so it needs its own spacing to
   avoid crowding the tab row. */
.subscription-banner {
    margin-bottom: 12px;
}

/* Phones: every pixel of padding here pushes the content further down. */
@media (max-width: 500px) {
    .roomvox-app {
        padding: 12px;
    }
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

/* Statistics tiles: icon, number, label beneath — the same shape in every
   Vox app. Sizes and weights from the theme's tokens. */
.stat-tiles {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(160px, 100%), 1fr));
    gap: 16px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.stat-tile {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    padding: 16px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius-large);
}

.stat-tile__icon {
    color: var(--color-text-maxcontrast);
}

/* The icon component centres itself (align-self: center), which beat the
   tile's flex-start: icon centred, number and label left. One edge. */
.stat-tile .stat-tile__icon {
    align-self: flex-start;
}

.stat-tile__value {
    font-size: calc(var(--default-font-size) * 1.6);
    font-weight: var(--font-weight-heading);
    line-height: 1.2;
    color: var(--color-primary-element);
}

.stat-tile__label {
    color: var(--color-text-maxcontrast);
}

.roomvox-settings {
    display: flex;
    flex-direction: column;
    gap: 16px;
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
    border-radius: var(--border-radius-element);
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

/* The field takes the room the row leaves it; the section sets the measure. */
.room-type-label {
    flex: 1;
}

.room-type-id {
    font-size: var(--font-size-small);
    color: var(--color-text-maxcontrast);
    font-family: monospace;
    min-width: 120px;
}

.room-type-add {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    margin-top: 8px;
}

/* API Token management */
.token-list {
    margin-bottom: 20px;
    overflow-x: auto;
}

.token-table {
    width: 100%;
    border-collapse: collapse;
}

/* Column names read as headings by being smaller and quieter, not by weight
   or a fill (design guidelines §6, "Tables in a settings page"). */
.token-table th {
    text-align: start;
    padding: 8px 12px;
    font-size: var(--font-size-small);
    font-weight: var(--font-weight-element);
    color: var(--color-text-maxcontrast);
    border-bottom: 1px solid var(--color-border);
    white-space: nowrap;
}

.token-table td {
    padding: 8px 12px;
    border-bottom: 1px solid var(--color-border);
}

.token-name {
    font-weight: var(--font-weight-element);
}

.no-tokens {
    color: var(--color-text-maxcontrast);
    margin-bottom: 16px;
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
    border-radius: var(--border-radius-small);
    word-break: break-all;
    flex: 1;
}

.token-form-row {
    display: flex;
    align-items: flex-end;
    flex-wrap: wrap;
    gap: 8px;
    margin: 16px 0;
}

.token-form-row__name {
    flex: 1;
}

/* Three fixed options: the select needs no more than its own minimum. */
.token-form-row__scope {
    width: fit-content;
}

.token-help {
    margin-top: 20px;
    padding: 16px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius-element);
}

.token-help h3 {
    margin: 0 0 8px;
    font-size: var(--default-font-size);
    font-weight: var(--font-weight-heading);
}

.token-help h3:not(:first-child) {
    margin-top: 16px;
}

.token-help ul {
    margin: 0;
    padding-inline-start: 20px;
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
    border-radius: var(--border-radius-small);
    font-size: var(--font-size-small);
    word-break: break-all;
}

/* Import / Export tab */
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
    border-radius: var(--border-radius-element);
    text-align: center;
    transition: border-color 0.2s, background-color 0.2s;
}

.upload-area--drag {
    border-color: var(--color-primary-element);
    background-color: var(--color-primary-element-light);
}

.upload-icon {
    color: var(--color-text-maxcontrast);
}

.upload-or {
    color: var(--color-text-maxcontrast);
}

.hidden-input {
    display: none;
}

.import-help {
    margin-top: 24px;
    padding: 16px 20px;
    background: var(--color-background-hover);
    border-radius: var(--border-radius-element);
}

.import-help h3 {
    font-size: var(--default-font-size);
    font-weight: var(--font-weight-heading);
    margin: 0 0 12px;
}

.import-help ul {
    margin: 0;
    padding-inline-start: 20px;
}

.import-help ul li {
    margin-bottom: 4px;
    line-height: 1.5;
}

.import-help-note {
    margin-top: 12px;
    color: var(--color-text-maxcontrast);
}

.sample-download {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid var(--color-border);
}

.sample-desc {
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
    border-radius: var(--border-radius-small);
    margin-bottom: 20px;
}

.preview-table {
    width: 100%;
    border-collapse: collapse;
}

/* Sticky, so it needs the page surface behind it to hide the rows that scroll
   under it; quieter type rather than a grey band marks it as the header. */
.preview-table th {
    position: sticky;
    top: 0;
    background: var(--color-main-background);
    text-align: start;
    padding: 8px 12px;
    font-size: var(--font-size-small);
    font-weight: var(--font-weight-element);
    color: var(--color-text-maxcontrast);
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
    background: var(--color-error);
}

.error-text {
    color: var(--color-error-text);
    font-size: var(--font-size-small);
}

.match-text {
    color: var(--color-text-maxcontrast);
    font-size: var(--font-size-small);
}

.import-mode {
    margin: 0 0 20px;
    padding: 0;
    border: 0;
}

.import-mode legend {
    padding: 0;
    font-weight: var(--font-weight-heading);
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

/* Soft background with its matching text token, per tier (design guidelines §5) */
.result-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 16px 24px;
    border-radius: var(--border-radius-element);
    min-width: 100px;
}

.result-stat--success {
    background: var(--color-success);
    color: var(--color-success-text);
}

.result-stat--info {
    background: var(--color-info);
    color: var(--color-info-text);
}

.result-stat--warning {
    background: var(--color-warning);
    color: var(--color-warning-text);
}

.result-stat--error {
    background: var(--color-error);
    color: var(--color-error-text);
}

.result-stat__number {
    font-size: calc(var(--default-font-size) * 1.6);
    font-weight: var(--font-weight-heading);
    line-height: 1.2;
}

.result-stat__label {
    font-size: var(--font-size-small);
    margin-top: 4px;
}

.result-errors {
    margin-bottom: 20px;
}

.result-errors h3 {
    font-size: var(--default-font-size);
    font-weight: var(--font-weight-heading);
    margin-bottom: 8px;
}

.result-errors ul {
    list-style: none;
    padding: 0;
}

.result-errors li {
    padding: 8px 0;
    border-bottom: 1px solid var(--color-border);
}

/* Exchange settings */
.exchange-fields {
    margin-top: 16px;
}

.exchange-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}

.exchange-test {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    flex-wrap: wrap;
}

.exchange-test .notecard {
    flex: 1;
    min-width: 200px;
}
</style>
