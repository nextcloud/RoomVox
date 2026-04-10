<!--
  - SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcModal
		v-if="calendarObjectInstance"
		v-model="showFullModal"
		class="calendar-edit-full"
		size="full"
		label-id="edit-full-modal"
		:name="modalTitle"
		:dark="false"
		@close="cancel(false)">
		<div class="calendar-edit-full__top-actions">
			<div v-if="!isLoading && !isError" class="calendar-edit-full__top-actions__menu">
				<NcActions>
					<NcActionLink v-if="!hideEventExport && hasDownloadURL && !isNew" :href="downloadURL">
						<template #icon>
							<Download :size="20" decorative />
						</template>
						{{ $t('calendar', 'Export') }}
					</NcActionLink>
					<NcActionButton v-if="!canCreateRecurrenceException && !isReadOnly && !isNew" type="tertiary" @click="duplicateEvent()">
						<template #icon>
							<ContentDuplicate :size="20" decorative />
						</template>
						{{ $t('calendar', 'Duplicate') }}
					</NcActionButton>
					<NcActionButton v-if="canDelete && !canCreateRecurrenceException && !isNew" type="tertiary" @click="deleteAndLeave(false)">
						<template #icon>
							<Delete :size="20" decorative />
						</template>
						{{ $t('calendar', 'Delete') }}
					</NcActionButton>
					<NcActionButton v-if="canDelete && canCreateRecurrenceException && !isNew" type="tertiary" @click="deleteAndLeave(false)">
						<template #icon>
							<Delete :size="20" decorative />
						</template>
						{{ $t('calendar', 'Delete this occurrence') }}
					</NcActionButton>
					<NcActionButton v-if="canDelete && canCreateRecurrenceException && !isNew" type="tertiary" @click="deleteAndLeave(true)">
						<template #icon>
							<Delete :size="20" decorative />
						</template>
						{{ $t('calendar', 'Delete this and all future') }}
					</NcActionButton>
				</NcActions>
			</div>
			<NcButton variant="tertiary" @click="cancel(false)">
				<template #icon>
					<Close :size="20" />
				</template>
			</NcButton>
		</div>
		<div class="app-full" :class="[{ 'app-full-readonly': isViewedByOrganizer === false }]">
			<template v-if="isLoading">
				<div class="app-full__loading-indicator">
					<div class="icon icon-loading app-full-tab-loading-indicator__icon" />
				</div>
			</template>
			<template v-else-if="isError">
				<NcEmptyContent :name="$t('calendar', 'Event does not exist')" :description="error">
					<template #icon>
						<CalendarBlank :size="20" decorative />
					</template>
				</NcEmptyContent>
			</template>

			<div v-if="!isLoading && !isError">
				<div class="app-full__modal-title-row">
					<h2 class="app-full__modal-title">{{ modalTitle }}</h2>
				</div>
				<div class="app-full__header__top">
					<div class="app-full__header__top__first">
						<div class="app-full__header__top-close-icon">
							<Close :size="20" @click="cancel(false)" />
						</div>

						<PropertyTitle
							:value="title"
							:is-read-only="isReadOnly || isViewedByOrganizer === false"
							@update:value="updateTitle" />
					</div>
				</div>

				<div class="app-full-attendees">
					<InviteesChipList
						v-if="!isLoading"
						:calendar="selectedCalendar"
						:calendar-object-instance="calendarObjectInstance"
						:is-read-only="isReadOnly || isViewedByOrganizer === false"
						:is-shared-with-me="isSharedWithMe"
						@update-dates="updateDates" />
				</div>

				<div class="app-full__header">
					<PropertyTitleTimePicker
						:start-date="startDate"
						:start-timezone="startTimezone"
						:end-date="endDate"
						:end-timezone="endTimezone"
						:is-all-day="isAllDay"
						:is-read-only="isReadOnly || isViewedByOrganizer === false"
						:can-modify-all-day="canModifyAllDay"
						:user-timezone="currentUserTimezone"
						:append-to-body="true"
						@update-start-date="updateStartDate"
						@update-start-time="updateStartTime"
						@update-start-timezone="updateStartTimezone"
						@update-end-date="updateEndDate"
						@update-end-time="updateEndTime"
						@update-end-timezone="updateEndTimezone" />

					<div class="app-full__header__details">
						<div class="app-full__header__details-time">
							<NcCheckboxRadioSwitch
								v-if="!isReadOnly && !isViewedByAttendee"
								:checked="isAllDay"
								:disabled="!canModifyAllDay"
								@update:checked="toggleAllDayPreliminary">
								{{ $t('calendar', 'All day') }}
							</NcCheckboxRadioSwitch>

							<!-- TODO: If not editing the master item, force updating this and all future   -->
							<!-- TODO: You can't edit recurrence-rule of no-range recurrence-exception -->
							<Repeat
								:calendar-object-instance="calendarObjectInstance"
								:recurrence-rule="calendarObjectInstance.recurrenceRule"
								:is-read-only="isReadOnly || isViewedByOrganizer === false"
								:is-editing-master-item="isEditingMasterItem"
								:is-recurrence-exception="isRecurrenceException"
								@force-this-and-all-future="forceModifyingFuture" />
						</div>

						<div class="app-full__header__details-calendar">
							<CalendarPickerHeader
								:value="selectedCalendar"
								:calendars="calendars"
								:is-read-only="isReadOnly || !canModifyCalendar"
								:is-viewed-by-attendee="isViewedByOrganizer === false"
								@update:value="changeCalendar" />
							<NcPopover v-if="isViewedByOrganizer === false" :no-focus-trap="true">
								<template #trigger>
									<NcButton variant="tertiary-no-background">
										<template #icon>
											<HelpCircleIcon :size="20" />
										</template>
									</NcButton>
								</template>
								<template #default>
									<p class="warning-text">
										{{ $t('calendar', 'Modifications wont get propagated to the organizer and other attendees') }}
									</p>
								</template>
							</NcPopover>
						</div>
					</div>

					<InvitationResponseButtons
						v-if="isViewedByAttendee"
						:attendee="userAsAttendee"
						:calendar-id="calendarId"
						:narrow="true"
						:grow-horizontally="true"
						@close="closeEditorAndSkipAction" />
				</div>

				<div class="app-full-body">
				<!-- Left column: Where + Details -->
				<div class="app-full-body__left">
					<!-- WHERE section -->
					<PropertyText
						class="property-location"
						:is-read-only="isReadOnly || isViewedByOrganizer === false"
						:prop-model="rfcProps.location"
						:value="location"
						:linkify-links="true"
						@update:value="updateLocation" />

					<!-- RoomVox: Meeting options with disclosure panels -->
					<div
						v-if="!isReadOnly && isViewedByOrganizer !== false"
						class="meeting-options">

						<!-- In-person section -->
						<div class="meeting-option">
							<div class="meeting-option__header">
								<MapMarker :size="20" class="meeting-option__icon" />
								<NcCheckboxRadioSwitch
									type="switch"
									:checked="isInPerson"
									@update:checked="handleInPersonToggle">
									{{ $t('calendar', 'In-person') }}
								</NcCheckboxRadioSwitch>
								<NcButton
									v-if="isInPerson"
									variant="tertiary"
									class="meeting-option__disclosure"
									@click="isRoomFinderExpanded = !isRoomFinderExpanded">
									<template #icon>
										<ChevronDown v-if="isRoomFinderExpanded" :size="20" />
										<ChevronRight v-else :size="20" />
									</template>
								</NcButton>
							</div>
							<div
								v-if="isInPerson && !isRoomFinderExpanded && selectedRoomSummary"
								class="meeting-option__summary"
								@click="isRoomFinderExpanded = true">
								{{ selectedRoomSummary }}
							</div>
							<div v-if="isInPerson && isRoomFinderExpanded" class="meeting-option__panel">
								<ResourceList
									v-if="!isLoading"
									:calendar-object-instance="calendarObjectInstance"
									:is-read-only="isReadOnly || isViewedByOrganizer === false"
									@add-room="handleRoomAdded" />
							</div>
						</div>

						<!-- Online (Talk) section -->
						<div v-if="isCreateTalkRoomButtonVisible" class="meeting-option">
							<div class="meeting-option__header">
								<IconVideo :size="20" class="meeting-option__icon" />
								<NcCheckboxRadioSwitch
									type="switch"
									:checked="isOnline"
									@update:checked="handleOnlineToggle">
									{{ $t('calendar', 'Online (Talk)') }}
								</NcCheckboxRadioSwitch>
								<NcButton
									v-if="isOnline && hasTalkUrl"
									variant="tertiary"
									class="meeting-option__disclosure"
									@click="isTalkPanelExpanded = !isTalkPanelExpanded">
									<template #icon>
										<ChevronDown v-if="isTalkPanelExpanded" :size="20" />
										<ChevronRight v-else :size="20" />
									</template>
								</NcButton>
							</div>
							<div
								v-if="isOnline && hasTalkUrl && !isTalkPanelExpanded"
								class="meeting-option__summary"
								@click="isTalkPanelExpanded = true">
								{{ talkRoomDisplayName }}
							</div>
							<div
								v-if="isOnline && hasTalkUrl && isTalkPanelExpanded"
								class="meeting-option__panel meeting-option__panel--talk">
								<div class="talk-room-info">
									<div class="talk-room-info__details">
										<span class="talk-room-info__name">{{ talkRoomDisplayName }}</span>
										<span class="talk-room-info__url">{{ talkUrl }}</span>
									</div>
									<NcButton variant="tertiary" @click="changeTalkRoom">
										{{ $t('calendar', 'Change') }}
									</NcButton>
								</div>
							</div>
						</div>
					</div>

					<AddTalkModal
						v-if="isModalOpen"
						:calendar-object-instance="calendarObjectInstance"
						@close="handleTalkModalClose"
						@update-location="updateLocation"
						@update-description="updateDescription" />

					<!-- DETAILS section -->
					<PropertyText
						class="property-description"
						:is-read-only="isReadOnly"
						:prop-model="rfcProps.description"
						:value="description"
						:is-description="true"
						:linkify-links="true"
						@update:value="updateDescription" />

					<AlarmList
						:calendar-object-instance="calendarObjectInstance"
						:is-read-only="isReadOnly" />

					<AttachmentsList
						v-if="!isLoading"
						:calendar-object-instance="calendarObjectInstance"
						:is-read-only="isReadOnly" />
				</div>

				<!-- Right column: Settings -->
				<div class="app-full-body__right">
					<PropertySelect
						:is-read-only="isReadOnly"
						:prop-model="rfcProps.status"
						:value="status"
						@update:value="updateStatus" />
					<PropertySelect
						:is-read-only="isReadOnly || isViewedByOrganizer === false"
						:prop-model="rfcProps.accessClass"
						:value="accessClass"
						@update:value="updateAccessClass" />
					<PropertySelect
						:is-read-only="isReadOnly"
						:prop-model="rfcProps.timeTransparency"
						:value="timeTransparency"
						@update:value="updateTimeTransparency" />
					<PropertySelectMultiple
						class="property-categories"
						:colored-options="true"
						:is-read-only="isReadOnly"
						:prop-model="rfcProps.categories"
						:value="categories"
						@add-single-value="addCategory"
						@remove-single-value="removeCategory" />
					<PropertyColor
						:calendar-color="selectedCalendarColor"
						:show-icon="!(isReadOnly && color === null)"
						:is-read-only="isReadOnly"
						:prop-model="rfcProps.color"
						:value="color"
						@update:value="updateColor" />
				</div>
			</div>

				<NcModal
					v-if="showModal && !isPrivate()"
					:name="t('calendar', 'Managing shared access')"
					@close="closeAttachmentsModal">
					<div class="modal-content">
						<div v-if="showPreloader" class="modal-content-preloader">
							<div :style="`width:${sharedProgress}%`" />
						</div>
						<div class="modal-h">
							{{ n('calendar', 'User requires access to your file', 'Users require access to your file', showModalUsers.length) }}
						</div>
						<div class="users">
							<NcListItemIcon
								v-for="attendee in showModalUsers"
								:key="attendee.uri"
								class="user-list-item"
								:name="attendee.commonName"
								:subtitle="emailWithoutMailto(attendee.uri)"
								:is-no-user="true" />
						</div>
						<div class="modal-subtitle">
							{{ n('calendar', 'Attachment requires shared access', 'Attachments requiring shared access', showModalNewAttachments.length) }}
						</div>
						<div class="attachments">
							<NcListItemIcon
								v-for="attachment in showModalNewAttachments"
								:key="attachment.xNcFileId"
								class="attachment-list-item"
								:name="getBaseName(attachment.fileName)"
								:url="getPreview(attachment)"
								:force-display-actions="false" />
						</div>
						<div class="modal-footer">
							<div class="modal-footer-checkbox">
								<NcCheckboxRadioSwitch v-if="!isPrivate()" :checked.sync="doNotShare">
									{{ t('calendar', 'Deny access') }}
								</NcCheckboxRadioSwitch>
							</div>
							<div class="modal-footer-buttons">
								<NcButton @click="closeAttachmentsModal">
									{{ t('calendar', 'Cancel') }}
								</NcButton>
								<NcButton
									variant="primary"
									:disabled="showPreloader"
									@click="acceptAttachmentsModal(thisAndAllFuture)">
									{{ t('calendar', 'Invite') }}
								</NcButton>
							</div>
						</div>
					</div>
				</NcModal>

				<!-- Actions footer (Save buttons per Nextcloud convention) -->
				<NcAppNavigationSpacer />
				<div class="event-form__actions">
					<NcButton
						variant="tertiary"
						@click="cancel(false)">
						{{ $t('calendar', 'Cancel') }}
					</NcButton>
					<SaveButtons
						v-if="showSaveButtons"
						:can-create-recurrence-exception="canCreateRecurrenceException"
						:is-new="isNew"
						:is-read-only="isReadOnly"
						:force-this-and-all-future="forceThisAndAllFuture"
						@save-this-only="prepareAccessForAttachments(false)"
						@save-this-and-all-future="prepareAccessForAttachments(true)" />
				</div>

			</div>
		</div>
		<NcDialog
			:open="showCancelDialog"
			:name="t('calendar', 'Discard changes?')"
			:message="t('calendar', 'Are you sure you want to discard the changes made to this event?')"
			:buttons="cancelButtons"
			@update:open="showCancelDialog = $event" />
	</NcModal>
</template>

<script>
import IconCancel from '@mdi/svg/svg/cancel.svg?raw'
import IconDelete from '@mdi/svg/svg/delete.svg?raw'
import { Parameter } from '@nextcloud/calendar-js'
import moment from '@nextcloud/moment'
import { generateUrl } from '@nextcloud/router'
import {
	NcActionButton,
	NcActionLink,
	NcActions,
	NcAppNavigationSpacer,
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcEmptyContent,
	NcListItemIcon,
	NcModal,
	NcPopover,
} from '@nextcloud/vue'
import { mapState, mapStores } from 'pinia'
import CalendarBlank from 'vue-material-design-icons/CalendarBlank.vue'
import Close from 'vue-material-design-icons/Close.vue'
import ContentDuplicate from 'vue-material-design-icons/ContentDuplicate.vue'
import HelpCircleIcon from 'vue-material-design-icons/HelpCircleOutline.vue'
import Delete from 'vue-material-design-icons/TrashCanOutline.vue'
import Download from 'vue-material-design-icons/TrayArrowDown.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import MapMarker from 'vue-material-design-icons/MapMarker.vue'
import IconVideo from 'vue-material-design-icons/VideoOutline.vue'
import AddTalkModal from '../components/Editor/AddTalkModal.vue'
import AlarmList from '../components/Editor/Alarm/AlarmList.vue'
import AttachmentsList from '../components/Editor/Attachments/AttachmentsList.vue'
import CalendarPickerHeader from '../components/Editor/CalendarPickerHeader.vue'
import InvitationResponseButtons from '../components/Editor/InvitationResponseButtons.vue'
import InviteesChipList from '../components/Editor/Invitees/InviteesChipList.vue'
import PropertyColor from '../components/Editor/Properties/PropertyColor.vue'
import PropertySelect from '../components/Editor/Properties/PropertySelect.vue'
import PropertySelectMultiple from '../components/Editor/Properties/PropertySelectMultiple.vue'
import PropertyText from '../components/Editor/Properties/PropertyText.vue'
import PropertyTitle from '../components/Editor/Properties/PropertyTitle.vue'
import PropertyTitleTimePicker from '../components/Editor/Properties/PropertyTitleTimePicker.vue'
import Repeat from '../components/Editor/Repeat/Repeat.vue'
import ResourceList from '../components/Editor/Resources/ResourceList.vue'
import SaveButtons from '../components/Editor/SaveButtons.vue'
import EditorMixin from '../mixins/EditorMixin.js'
import { shareFile } from '../services/attachmentService.js'
import getTimezoneManager from '../services/timezoneDataProviderService.js'
import useCalendarObjectInstanceStore from '../store/calendarObjectInstance.js'
import usePrincipalsStore from '../store/principals.js'
import useSettingsStore from '../store/settings.js'
import logger from '../utils/logger.js'
import { containsRoomUrl, generateRoomUrl, extractRoomUrlToken, listRooms } from '@/services/talkService'

export default {
	name: 'EditFull',
	components: {
		AddTalkModal,
		ResourceList,
		PropertyColor,
		PropertySelectMultiple,
		SaveButtons,
		AlarmList,
		NcActionButton,
		NcActionLink,
		NcAppNavigationSpacer,
		NcEmptyContent,
		NcModal,
		NcListItemIcon,
		NcButton,
		NcCheckboxRadioSwitch,
		NcPopover,
		NcDialog,
		InviteesChipList,
		PropertySelect,
		PropertyText,
		PropertyTitleTimePicker,
		Repeat,
		CalendarBlank,
		Delete,
		Download,
		ContentDuplicate,
		InvitationResponseButtons,
		AttachmentsList,
		CalendarPickerHeader,
		PropertyTitle,
		IconVideo,
		HelpCircleIcon,
		MapMarker,
		ChevronDown,
		ChevronRight,
		NcActions,
		Close,
	},

	mixins: [
		EditorMixin,
	],

	data() {
		return {
			thisAndAllFuture: false,
			doNotShare: false,
			showModal: false,
			showModalNewAttachments: [],
			showModalUsers: [],
			sharedProgress: 0,
			showPreloader: false,
			isModalOpen: false,
			cancelButtons: [
				{
					label: t('calendar', 'Discard changes'),
					icon: atob(IconDelete.split(',')[1]),
					callback: () => { this.cancel(true) },
				},
				{
					label: t('calendar', 'Cancel'),
					type: 'primary',
					icon: atob(IconCancel.split(',')[1]),
					callback: () => { this.closeCancelDialog() },
				},
			],

			showCancelDialog: false,
			showFullModal: true,
			isInPerson: false,
			isOnline: false,
			isRoomFinderExpanded: true,
			isTalkPanelExpanded: true,
			talkRoomDisplayName: '',
			conferenceUrl: '',
		}
	},

	computed: {
		...mapStores(usePrincipalsStore),
		...mapState(useSettingsStore, {
			locale: 'momentLocale',
			hideEventExport: 'hideEventExport',
			attachmentsFolder: 'attachmentsFolder',
			showResources: 'showResources',
		}),

		...mapState(useCalendarObjectInstanceStore, ['calendarObjectInstance']),
		...mapState(useSettingsStore, ['talkEnabled']),
		accessClass() {
			return this.calendarObjectInstance?.accessClass || null
		},

		categories() {
			return this.calendarObjectInstance?.categories || null
		},

		status() {
			return this.calendarObjectInstance?.status || null
		},

		timeTransparency() {
			return this.calendarObjectInstance?.timeTransparency || null
		},

		subTitle() {
			if (!this.calendarObjectInstance) {
				return ''
			}

			const userTimezone = getTimezoneManager().getTimezoneForId(this.currentUserTimezone)
			if (!userTimezone) {
				logger.warn(`User timezone not found: ${this.currentUserTimezone}`)
				return ''
			}

			const startDateInUserTz = this.calendarObjectInstance.eventComponent.startDate
				.getInTimezone(userTimezone)
				.jsDate
			return moment(startDateInUserTz).locale(this.locale).fromNow()
		},

		attachments() {
			return this.calendarObjectInstance?.attachments || null
		},

		currentUser() {
			return this.principalsStore.getCurrentUserPrincipal || null
		},

		isCreateTalkRoomButtonDisabled() {
			if (this.creatingTalkRoom) {
				return true
			}

			return containsRoomUrl(this.calendarObjectInstance.location) || containsRoomUrl(this.calendarObjectInstance.description)
		},

		isCreateTalkRoomButtonVisible() {
			return this.talkEnabled && this.isViewedByOrganizer !== false && this.isReadOnly !== true
		},

		resources() {
			return this.calendarObjectInstance.attendees.filter((attendee) => {
				return ['ROOM', 'RESOURCE'].includes(attendee.attendeeProperty.userType)
			})
		},

		hasTalkUrl() {
			// Reactieve check: conferenceUrl wordt bijgehouden als data property
			if (this.conferenceUrl) {
				return true
			}
			// Legacy fallback: check location and description
			return containsRoomUrl(this.calendarObjectInstance.location)
				|| containsRoomUrl(this.calendarObjectInstance.description)
		},

		talkUrl() {
			// Primary: reactieve conferenceUrl
			if (this.conferenceUrl) {
				return this.conferenceUrl
			}
			// Legacy fallback
			const loc = this.calendarObjectInstance.location || ''
			const desc = this.calendarObjectInstance.description || ''
			const baseUrl = generateRoomUrl('')
			const regex = new RegExp(baseUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '[^\\s]*')
			return (loc.match(regex) || desc.match(regex) || [''])[0]
		},

		selectedRoomSummary() {
			const rooms = this.calendarObjectInstance.attendees.filter(a =>
				['ROOM', 'RESOURCE'].includes(a.attendeeProperty.userType))
			if (!rooms.length) return ''
			return rooms.map(a => a.commonName || a.uri).join(', ')
		},

		modalTitle() {
			// New event
			if (this.isNew) {
				return this.$t('calendar', 'New event')
			}

			// Existing event: use event title (via EditorMixin computed property)
			const eventTitle = this.title?.trim()
			if (eventTitle) {
				return eventTitle
			}

			// Fallback for empty title
			return this.$t('calendar', 'Event')
		},
	},

	watch: {
		// RoomVox: Initialize toggles when calendarObjectInstance becomes available
		calendarObjectInstance: {
			handler(newValue) {
				// Only initialize once when data first becomes available
				if (!newValue || this._togglesInitialized) {
					return
				}

				// Read CONFERENCE property from eventComponent and cache it
				const conferences = newValue.eventComponent.getConferenceList()
				if (conferences.length > 0 && containsRoomUrl(conferences[0].uri)) {
					this.conferenceUrl = conferences[0].uri
				}

				// Initialize In-person toggle: check for room attendees
				const roomAttendees = newValue.attendees.filter(a => {
					const userType = a.attendeeProperty?.userType
					return ['ROOM', 'RESOURCE'].includes(userType)
				})
				this.isInPerson = roomAttendees.length > 0

				// Initialize Online toggle: check for Talk URL in CONFERENCE or LOCATION
				const hasTalk = !!(this.conferenceUrl
					|| containsRoomUrl(newValue.location)
					|| containsRoomUrl(newValue.description))
				this.isOnline = hasTalk

				if (hasTalk) {
					this.resolveTalkRoomName()
				}

				// Existing event: Room Finder starts collapsed, Talk panel expanded
				this.isRoomFinderExpanded = false
				this.isTalkPanelExpanded = true

				// Mark as initialized to prevent re-running
				this._togglesInitialized = true
			},
			immediate: true, // Run on mount if data already exists
		},
	},

	mounted() {
		window.addEventListener('keydown', this.keyboardCloseEditor)
		window.addEventListener('keydown', this.keyboardSaveEvent)
		window.addEventListener('keydown', this.keyboardDeleteEvent)
		window.addEventListener('keydown', this.keyboardDuplicateEvent)
	},

	beforeDestroy() {
		window.removeEventListener('keydown', this.keyboardCloseEditor)
		window.removeEventListener('keydown', this.keyboardSaveEvent)
		window.removeEventListener('keydown', this.keyboardDeleteEvent)
		window.removeEventListener('keydown', this.keyboardDuplicateEvent)
	},

	methods: {
		handleInPersonToggle(checked) {
			this.isInPerson = checked
			if (checked) {
				this.isRoomFinderExpanded = true
			} else {
				// Release all booked rooms
				this.removeAllRooms()
				// Clear the room address from location
				this.calendarObjectInstanceStore.changeLocation({
					calendarObjectInstance: this.calendarObjectInstance,
					location: '',
				})
				// Collapse room finder
				this.isRoomFinderExpanded = false
				// If there's an active Talk meeting, put its URL in the now-empty location
				if (this.hasTalkUrl) {
					this.calendarObjectInstanceStore.changeLocation({
						calendarObjectInstance: this.calendarObjectInstance,
						location: this.talkUrl,
					})
				}
			}
		},

		handleRoomAdded() {
			// Auto-collapse the room finder panel after a room is selected
			this.isRoomFinderExpanded = false
		},

		handleOnlineToggle(checked) {
			this.isOnline = checked
			if (checked) {
				if (!this.hasTalkUrl) {
					this.isModalOpen = true
				} else {
					this.isTalkPanelExpanded = true
				}
			} else {
				this.removeTalkUrl()
				this.talkRoomDisplayName = ''
			}
		},

		handleTalkModalClose() {
			this.isModalOpen = false
			// Sync conferenceUrl from eventComponent (addConference is not reactive)
			const conferences = this.calendarObjectInstance.eventComponent.getConferenceList()
			if (conferences.length > 0 && containsRoomUrl(conferences[0].uri)) {
				this.conferenceUrl = conferences[0].uri
			}
			if (!this.hasTalkUrl) {
				this.isOnline = false
			} else {
				this.isTalkPanelExpanded = true
				this.resolveTalkRoomName()
			}
		},

		changeTalkRoom() {
			this.removeTalkUrl()
			this.talkRoomDisplayName = ''
			this.isModalOpen = true
		},

		async resolveTalkRoomName() {
			const token = extractRoomUrlToken(this.talkUrl)
			if (!token) {
				this.talkRoomDisplayName = this.$t('calendar', 'Talk room')
				return
			}
			try {
				const rooms = await listRooms()
				const room = rooms.find(r => r.token === token)
				this.talkRoomDisplayName = room?.displayName || this.$t('calendar', 'Talk room')
			} catch {
				this.talkRoomDisplayName = this.$t('calendar', 'Talk room')
			}
		},

		removeTalkUrl() {
			// Remove CONFERENCE property (RFC 7986)
			this.calendarObjectInstance.eventComponent.deleteAllProperties('CONFERENCE')
			this.conferenceUrl = ''

			// Remove Talk URL from location (if present)
			if (containsRoomUrl(this.calendarObjectInstance.location)) {
				this.calendarObjectInstanceStore.changeLocation({
					calendarObjectInstance: this.calendarObjectInstance,
					location: '',
				})
			}

			// Remove Talk URL from description (legacy cleanup)
			if (containsRoomUrl(this.calendarObjectInstance.description)) {
				const baseUrl = generateRoomUrl('')
				const escapedBase = baseUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
				const desc = this.calendarObjectInstance.description || ''
				const cleaned = desc.replace(new RegExp('\\s*' + escapedBase + '[^\\s]*', 'g'), '').trim()
				this.calendarObjectInstanceStore.changeDescription({
					calendarObjectInstance: this.calendarObjectInstance,
					description: cleaned,
				})
			}
		},

		removeAllRooms() {
			const rooms = this.calendarObjectInstance.attendees.filter(
				(a) => ['ROOM', 'RESOURCE'].includes(a.attendeeProperty.userType),
			)
			for (const room of rooms) {
				this.calendarObjectInstanceStore.removeAttendee({
					calendarObjectInstance: this.calendarObjectInstance,
					attendee: room,
				})
			}
		},

		updateLocation(location) {
			this.calendarObjectInstanceStore.changeLocation({
				calendarObjectInstance: this.calendarObjectInstance,
				location,
			})
		},

		updateDescription(description) {
			this.calendarObjectInstanceStore.changeDescription({
				calendarObjectInstance: this.calendarObjectInstance,
				description,
			})
		},

		/**
		 * Update the start and end date of this event
		 *
		 * @param {object} dates The new start and end date
		 */
		updateDates(dates) {
			this.updateStartDate(dates.start)
			this.updateStartTime(dates.start)
			this.updateEndDate(dates.end)
			this.updateEndTime(dates.end)
		},

		/**
		 * Updates the access-class of this event
		 *
		 * @param {string} accessClass The new access class
		 */
		updateAccessClass(accessClass) {
			this.calendarObjectInstanceStore.changeAccessClass({
				calendarObjectInstance: this.calendarObjectInstance,
				accessClass,
			})
		},

		/**
		 * Updates the status of the event
		 *
		 * @param {string} status The new status
		 */
		updateStatus(status) {
			this.calendarObjectInstanceStore.changeStatus({
				calendarObjectInstance: this.calendarObjectInstance,
				status,
			})
		},

		/**
		 * Updates the time-transparency of the event
		 *
		 * @param {string} timeTransparency The new time-transparency
		 */
		updateTimeTransparency(timeTransparency) {
			this.calendarObjectInstanceStore.changeTimeTransparency({
				calendarObjectInstance: this.calendarObjectInstance,
				timeTransparency,
			})
		},

		/**
		 * Adds a category to the event
		 *
		 * @param {string} category Category to add
		 */
		addCategory(category) {
			this.calendarObjectInstanceStore.addCategory({
				calendarObjectInstance: this.calendarObjectInstance,
				category,
			})
		},

		/**
		 * Removes a category from the event
		 *
		 * @param {string} category Category to remove
		 */
		removeCategory(category) {
			this.calendarObjectInstanceStore.removeCategory({
				calendarObjectInstance: this.calendarObjectInstance,
				category,
			})
		},

		/**
		 * Updates the color of the event
		 *
		 * @param {string} customColor The new color
		 */
		updateColor(customColor) {
			this.calendarObjectInstanceStore.changeCustomColor({
				calendarObjectInstance: this.calendarObjectInstance,
				customColor,
			})
		},

		/**
		 * Checks is the calendar event has attendees, but organizer or not
		 *
		 * @return {boolean}
		 */
		isPrivate() {
			return this.calendarObjectInstance.attendees.filter((attendee) => {
				if (this.currentUser.emailAddress.toLowerCase() !== (
					attendee.uri.split('mailto:').length === 2
						? attendee.uri.split('mailto:')[1].toLowerCase()
						: attendee.uri.toLowerCase()
				)) {
					return attendee
				}
				return false
			}).length === 0
		},

		getPreview(attachment) {
			if (attachment.xNcHasPreview) {
				return generateUrl(`/core/preview?fileId=${attachment.xNcFileId}&x=100&y=100&a=0`)
			}
			return attachment.formatType
				? OC.MimeType.getIconUrl(attachment.formatType)
				: OC.MimeType.getIconUrl('folder')
		},

		acceptAttachmentsModal() {
			if (!this.doNotShare) {
				const total = this.showModalNewAttachments.length
				this.showPreloader = true
				if (!this.isPrivate()) {
					this.showModalNewAttachments.map(async (attachment, i) => {
						this.sharedProgress = Math.ceil(100 * (i + 1) / total)

						// add share + change attachment
						try {
							const data = await shareFile(`${this.attachmentsFolder}${attachment.fileName}`)
							attachment.shareTypes = data?.share_type?.toString()
							if (typeof attachment.attachmentProperty.getParameter('X-NC-SHARED-TYPES') === 'undefined') {
								const xNcSharedTypes = new Parameter('X-NC-SHARED-TYPES', attachment.shareTypes)
								attachment.attachmentProperty.setParameter(xNcSharedTypes)
							}
							attachment.attachmentProperty.uri = data?.url
							attachment.uri = data?.url
							// toastify success
						} catch (e) {
							// toastify err
							console.error(e)
						}
						return attachment
					})
				} else {
					// TODO it is not possible to delete shares, because share ID needed
					/* this.showModalNewAttachments.map((attachment, i) => {
						this.sharedProgress += Math.ceil(100 * (i + 1) / total)
						return attachment
					}) */
				}
			}
			setTimeout(() => {
				this.showPreloader = false
				this.sharedProgress = 0
				this.showModal = false
				this.showModalNewAttachments = []
				this.showModalUsers = []
				this.saveEvent(this.thisAndAllFuture)
			}, 500)
			// trigger save event after make each attachment access
			// 1) if !isPrivate get attachments NOT SHARED  and SharedType is empry -> API ADD SHARE
			// 2) if isPrivate get attachments SHARED  and SharedType is not empty -> API DELETE SHARE
			// 3) update calendarObject while pending access change
			// 4) after all access changes, save Event trigger
			// 5) done
		},

		closeAttachmentsModal() {
			this.showModal = false
		},

		emailWithoutMailto(mailto) {
			return mailto.split('mailto:').length === 2
				? mailto.split('mailto:')[1].toLowerCase()
				: mailto.toLowerCase()
		},

		getBaseName(name) {
			return name.split('/').pop()
		},

		prepareAccessForAttachments(thisAndAllFuture = false) {
			this.thisAndAllFuture = thisAndAllFuture
			const newAttachments = this.calendarObjectInstance.attachments.filter((attachment) => {
				// get only new attachments
				// TODO get NOT only new attachments =) Maybe we should filter all attachments without share-type, 'cause event can be private and AFTER save owner could add new participant
				return !this.isPrivate() ? attachment.isNew && attachment.shareTypes === null : attachment.isNew && attachment.shareTypes !== null
			})
			// if there are new attachment and event not saved
			if (newAttachments.length > 0 && !this.isPrivate()) {
				// and is event NOT private,
				// then add share to each attachment
				// only if attachment['share-types'] is null or empty
				this.showModal = true
				this.showModalNewAttachments = newAttachments
				this.showModalUsers = this.calendarObjectInstance.attendees.filter((attendee) => {
					if (this.currentUser.emailAddress.toLowerCase() !== this.emailWithoutMailto(attendee.uri)) {
						return attendee
					}
					return false
				})
			} else {
				this.saveEvent(thisAndAllFuture)
			}
		},

		saveEvent(thisAndAllFuture = false) {
			// if there is new attachments and !private, then make modal with users and files/
			// maybe check shared access before add file
			this.saveAndLeave(thisAndAllFuture)
			this.calendarObjectInstance.attachments = this.calendarObjectInstance.attachments.map((attachment) => {
				if (attachment.isNew) {
					delete attachment.isNew
				}
				return attachment
			})
		},

		/**
		 * Toggles the all-day state of an event
		 */
		toggleAllDayPreliminary() {
			if (!this.canModifyAllDay) {
				return
			}

			this.toggleAllDay()
		},
	},
}
</script>

<style lang="scss" scoped>
.calendar-edit-full {
	&__top-actions {
		z-index: 1;
		position: absolute !important;
		top: var(--default-grid-baseline);
		inset-inline-end: var(--default-grid-baseline);
		display: flex;
		align-items: center;
		gap: 0;
	}

	@media screen and (max-width: 785px) {
		.app-full__header__top {
			gap: calc(var(--default-grid-baseline) * 2) !important;

			&-close-icon {
				display: none;  // Hide spacer on mobile — top-actions stays top-right
			}
		}

		:deep(.modal-container__close) {
			visibility: hidden;
		}
	}

	:deep() {
		// Modal container: fit content height, cap at viewport
		.modal-wrapper--full > .modal-container {
			--header-height: 50px !important;
			width: 1000px !important;
			max-width: 95vw !important;
			height: auto !important;
			max-height: calc(100vh - 100px) !important;  // Leave space for NC header + breathing room
			top: 50% !important;
			transform: translateY(-50%) !important;  // Vertically center
			overflow: hidden !important;
			display: flex !important;
			flex-direction: column !important;
		}

		// Header sits on modal-mask level (outside container) — hide it
		.modal-header {
			display: none !important;
		}

		// Content area: scrollable when content exceeds max-height
		.modal-container__content {
			flex: 1 1 auto !important;
			overflow-y: auto !important;
			overflow-x: hidden !important;
			min-height: 0 !important;
		}
	}
}

.app-full {
	--total-width: 900px;
	--column-gap: calc(var(--default-grid-baseline) * 3);
	width: 100%;
	max-width: 960px;
	padding: 12px 24px;
	margin: 0 auto;
	box-sizing: border-box;

	&__modal-title-row {
		display: flex;
		align-items: center;
		margin: 0 0 8px 0;
		padding-inline-end: 80px;  // Reserve space for top-right actions + close button
	}

	&__modal-title {
		font-size: 18px;
		font-weight: 600;
		margin: 0;
		padding: 0;
		color: var(--color-main-text);
	}

	@media (max-width: 1200px) {
		padding: 12px;  // Reduced padding on small screens
	}

	// Time closer to date: shrink pickers and remove stretch
	:deep(.property-title-time-picker__time-pickers-from-inner),
	:deep(.property-title-time-picker__time-pickers-to-inner) {
		justify-content: flex-start !important;
		gap: 16px !important;
	}

	:deep([class*="time-pickers-from-inner__selector"]),
	:deep([class*="time-pickers-to-inner__selector"]) {
		justify-content: flex-start !important;
		gap: 12px !important;
		flex: 0 1 auto !important;
		width: auto !important;

		.native-datetime-picker {
			flex: 0 0 auto !important;
			width: auto !important;
		}
	}

	:deep(.avatar-participation-status__text) {
		bottom: -2px !important;
		position: absolute !important;
		max-width: min(calc(100vw - 130px), 500px) !important;
		min-width: unset !important;
	}

	&__header__top {
		position: sticky;
		top: 0;
		display: flex;
		flex-wrap: wrap-reverse;
		gap: 32px;
		padding: 16px 0;
		background-color: var(--color-main-background);
		align-items: center;
		z-index: 10000;

		&__first {
			display: flex;
			flex-grow: 1;
			min-width: 0;  // Allow flex shrinking

			.property-title {
				width: 100%;
				flex-grow: 1;

				:deep(input) {
					width: 100%;
				}
			}
		}

		&-close-icon {
			width: 53px;  // Explicit width
			display: flex;
			justify-content: flex-start;
			visibility: hidden;
		}
	}

	&__header {
		display: flex;
		flex-direction: column;
		gap: 16px;
		padding-bottom: 16px;
		z-index: 10;
		margin-top: 16px;

		&__details {
			display: flex;
			flex-wrap: wrap;
			gap: 32px;
			justify-content: space-between;
			padding-inline-start: 72px;  // Icon + label space

			&-time {
				display: flex;
				gap: 16px;  // Fixed gap between date and time
				flex: 1 1 auto;
				min-width: 0;
			}

			&-calendar {
				display: flex;
				flex-direction: row;
				width: min(480px, 40%);
				flex-shrink: 0;
			}
		}
	}

	// ── RoomVox: Room Finder modal ─────────────

	&__loading-indicator {
		display: flex;
		justify-content: center;
		align-items: center;
		height: 100%;
		width: 100%;
		flex-direction: column;
	}
}

.app-full-readonly {
	.app-full__header__details {
		padding-inline-start: 0;
	}

	:deep(.does-not-repeat) {
		display: none;
	}
}

.app-full-body {
	display: flex;
	flex-direction: row;
	gap: 24px;  // RoomVox: Reduced gap for tighter layout
	justify-content: flex-start;  // RoomVox: Don't push columns apart
	flex-wrap: nowrap;

	&__right {
		flex: 0 0 auto;
		width: 320px;  // RoomVox: Fixed width for right column
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 4);

		// Constrain all selects to reasonable width
		:deep(.property-select__input),
		:deep(.v-select),
		:deep(.property-select-multiple__input) {
			max-width: 100%;
		}

		.multiselect__tag {
			padding: var(--default-grid-baseline);
			border-radius: var(--border-radius-element);
		}

		.property-select-multiple-colored-tag__color-indicator {
			width: 12px;
			height: 12px;
			border-radius: 50%;
		}

		.property-color__icon--hidden {
			visibility: hidden;
		}
	}

	&__left {
		flex: 1 1 auto;
		min-width: 0;  // Allow flex shrinking
		max-width: none;  // Remove max-width constraint
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 4);

		// Constrain text inputs
		:deep(.property-text input[type="text"]) {
			max-width: 100%;
		}

		:deep(.property-text textarea) {
			max-width: 100%;
		}
	}

	// Mobile: stack columns only on very small screens
	@media (max-width: 600px) {
		flex-direction: column;

		&__right,
		&__left {
			width: 100% !important;
			max-width: 100% !important;
		}
	}

	.v-select.select {
		min-width: unset !important;
	}

	.property-alarm-item {
		margin-inline-start: calc(var(--default-grid-baseline) * 5);
	}
}

// RoomVox: Meeting options with disclosure panels
.meeting-options {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.meeting-option {
	&__header {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 1);
		padding-inline-start: calc(var(--default-grid-baseline) * 9);
	}

	&__icon {
		color: var(--color-text-maxcontrast);
		flex-shrink: 0;
	}

	&__disclosure {
		margin-inline-start: auto;
	}

	&__summary {
		padding-inline-start: calc(var(--default-grid-baseline) * 9);
		padding-block: calc(var(--default-grid-baseline) * 1);
		color: var(--color-text-maxcontrast);
		font-size: calc(var(--default-font-size) * 0.9);
		cursor: pointer;

		&:hover {
			color: var(--color-main-text);
		}
	}

	&__panel {
		margin-inline-start: calc(var(--default-grid-baseline) * 9);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		overflow: hidden;

		&--talk {
			padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3);
		}
	}
}

.talk-room-info {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);

	&__details {
		flex: 1;
		min-width: 0;
	}

	&__name {
		display: block;
		font-weight: 500;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__url {
		display: block;
		font-size: calc(var(--default-font-size) * 0.85);
		color: var(--color-text-maxcontrast);
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}
}

.app-full-attendees {
	width: calc(100% - 53px);  // Account for margin to prevent overflow
	margin-inline-start: 53px;
	padding-bottom: 16px;
	box-sizing: border-box;

	:deep(.invitees-search__vselect) {
		margin-inline-start: 0;
		max-width: 100%;
	}

	:deep(.invitees-list__subtitle) {
		margin-inline-start: 0;
	}
}

// One column layout for mobile/tablet screens
@media screen and (max-width: 900px) {
	.app-full-body {
		flex-direction: column;
	}

	.app-full-body__left,
	.app-full-body__right {
		width: 100% !important;
	}

	.app-full-body__right {
		.property-select__input {
			max-width: 100% !important;
		}
	}
}

// Mobile: full-width attendees when close-icon spacer is hidden
@media screen and (max-width: 785px) {
	.app-full-attendees {
		width: 100%;
		margin-inline-start: 0;
	}
}

.modal-mask {
	height: calc(100vh - var(--header-height));
	top: var(--header-height);
	// OWA-style semi-transparent backdrop to show calendar behind modal
	background-color: rgba(0, 0, 0, 0.3) !important;
	backdrop-filter: blur(2px);
}

.modal-content {
	padding: 16px;
	position: relative;

	.modal-content-preloader {
		position: absolute;
		top:0;
		inset-inline:0;
		height: 6px;

		div {
			position: absolute;
			top:0;
			inset-inline-start: 0;
			background: var(--color-primary-element);
			height: 6px;
			transition: width 0.3s linear;
		}
	}
}

.modal-subtitle {
	font-weight: bold;
	font-size: 16px;
	margin-top: 16px;
}

.modal-h {
	font-size: 24px;
	font-weight: bold;
	margin: 10px 0;
}

.modal-footer {
	display: flex;
	align-items: center;
	justify-content: space-between;

	.modal-footer-buttons {
		display: flex;

		:first-child {
			margin-inline-end: 6px;
		}
	}
}

.attachments, .users {
	display: flex;
	flex-wrap: wrap;
}

.attachment-list-item, .user-list-item {
	width: 50%
}

.attachment-icon {
	width: 40px;
	height: auto;
	border-radius: var(--border-radius);
}

// Actions footer (Save buttons per Nextcloud convention)
.event-form__actions {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	gap: var(--default-grid-baseline);
	padding-top: calc(var(--default-grid-baseline) * 2);
	margin-top: calc(var(--default-grid-baseline) * 3);
	border-top: 1px solid var(--color-border);
}
</style>

<!-- Modal width override moved to css/global.scss for proper compilation -->
