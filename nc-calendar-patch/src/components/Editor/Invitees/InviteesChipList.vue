<!--
  - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div v-if="!(invitees.length === 0 && isReadOnly)" class="invitees-chip-list">
		<!-- ═══ Required attendees section ═══ -->
		<div class="invitees-chip-list__section">
			<div class="invitees-chip-list__section-header">
				<div class="invitees-chip-list__section-header__title">
					<AccountMultipleIcon :size="20" />
					<span>{{ $t('calendar', 'Required') }}</span>
					<NcCounterBubble v-if="requiredCount > 0" :count="requiredCount" />
				</div>
				<NcButton
					v-if="!isReadOnly && isOrganizer"
					:disabled="isListEmpty"
					variant="tertiary"
					@click="toggleFreeBusy">
					{{ $t('calendar', 'Find a time') }}
				</NcButton>
			</div>

			<!-- Status subtitle (read-only mode) -->
			<div v-if="statusHeader" class="invitees-chip-list__subtitle">
				{{ statusHeader }}
			</div>

			<InviteesListSearch
				v-if="!isReadOnly && hasUserEmailAddress"
				:already-invited-emails="requiredInvitedEmails"
				:organizer="calendarObjectInstance.organizer"
				@add-attendee="addRequiredAttendee" />

			<div
				v-if="(hasOrganizer && !isOrganizer) || requiredInvitees.length > 0"
				class="invitees-chip-list__chips"
				:class="{
					'invitees-chip-list__chips--expanded': isRequiredExpanded,
					'invitees-chip-list__chips--drop-target': dragOverSection === 'required',
				}"
				@dragover.prevent="onDragOver('required')"
				@dragleave="onDragLeave"
				@drop.prevent="onDrop('REQ-PARTICIPANT')">
				<AttendeeChip
					v-if="hasOrganizer && !isOrganizer"
					:attendee="calendarObjectInstance.organizer"
					:is-organizer="true"
					:is-read-only="isReadOnly"
					:user-id="getUserId(calendarObjectInstance.organizer.uri)"
					:availability="getAvailability(calendarObjectInstance.organizer.uri)" />
				<AttendeeChip
					v-for="invitee in displayedRequired"
					:key="invitee.uri"
					:attendee="invitee"
					:is-read-only="isReadOnly"
					:user-id="getUserId(invitee.uri)"
					:availability="getAvailability(invitee.uri)"
					@remove-attendee="removeAttendee"
					@drag-start="onChipDragStart"
					@drag-end="onChipDragEnd" />
				<NcButton
					v-if="requiredOverflow > 0 && !isRequiredExpanded"
					variant="tertiary"
					class="invitees-chip-list__more-btn"
					@click="isRequiredExpanded = true">
					{{ n('calendar', '+%n more', '+%n more', requiredOverflow) }}
				</NcButton>
				<NcButton
					v-if="isRequiredExpanded && requiredOverflow > 0"
					variant="tertiary"
					class="invitees-chip-list__more-btn"
					@click="isRequiredExpanded = false">
					{{ $t('calendar', 'Show less') }}
				</NcButton>
			</div>
		</div>

		<!-- ═══ Optional attendees section ═══ -->
		<div class="invitees-chip-list__section">
			<div class="invitees-chip-list__section-header">
				<div class="invitees-chip-list__section-header__title">
					<AccountOutlineIcon :size="20" />
					<span>{{ $t('calendar', 'Optional') }}</span>
					<NcCounterBubble v-if="optionalInvitees.length > 0" :count="optionalInvitees.length" />
				</div>
			</div>

			<InviteesListSearch
				v-if="!isReadOnly && hasUserEmailAddress"
				:already-invited-emails="optionalInvitedEmails"
				:organizer="calendarObjectInstance.organizer"
				@add-attendee="addOptionalAttendee" />

			<div
				v-if="optionalInvitees.length > 0 || dragOverSection === 'optional'"
				class="invitees-chip-list__chips"
				:class="{
					'invitees-chip-list__chips--expanded': isOptionalExpanded,
					'invitees-chip-list__chips--drop-target': dragOverSection === 'optional',
				}"
				@dragover.prevent="onDragOver('optional')"
				@dragleave="onDragLeave"
				@drop.prevent="onDrop('OPT-PARTICIPANT')">
				<AttendeeChip
					v-for="invitee in displayedOptional"
					:key="invitee.uri"
					:attendee="invitee"
					:is-read-only="isReadOnly"
					:user-id="getUserId(invitee.uri)"
					:availability="getAvailability(invitee.uri)"
					@remove-attendee="removeAttendee"
					@drag-start="onChipDragStart"
					@drag-end="onChipDragEnd" />
				<NcButton
					v-if="optionalOverflow > 0 && !isOptionalExpanded"
					variant="tertiary"
					class="invitees-chip-list__more-btn"
					@click="isOptionalExpanded = true">
					{{ n('calendar', '+%n more', '+%n more', optionalOverflow) }}
				</NcButton>
				<NcButton
					v-if="isOptionalExpanded && optionalOverflow > 0"
					variant="tertiary"
					class="invitees-chip-list__more-btn"
					@click="isOptionalExpanded = false">
					{{ $t('calendar', 'Show less') }}
				</NcButton>
				<span v-if="optionalInvitees.length === 0 && dragOverSection === 'optional'" class="invitees-chip-list__drop-hint">
					{{ $t('calendar', 'Drop here to make optional') }}
				</span>
			</div>
		</div>

		<!-- Organizer email error -->
		<OrganizerNoEmailError v-if="!isReadOnly && isListEmpty && !hasUserEmailAddress" />

		<!-- FreeBusy modal -->
		<FreeBusy
			v-if="showFreeBusy"
			:attendees="calendarObjectInstance.attendees"
			:organizer="calendarObjectInstance.organizer"
			:start-date="calendarObjectInstance.startDate"
			:end-date="calendarObjectInstance.endDate"
			:event-title="calendarObjectInstance.title"
			:already-invited-emails="alreadyInvitedEmails"
			:show-done-button="true"
			:all-day="calendarObjectInstance.eventComponent.isAllDay()"
			@remove-attendee="removeAttendee"
			@add-attendee="addRequiredAttendee"
			@update-dates="saveNewDate"
			@close="closeFreeBusy" />
	</div>
</template>

<script>
import { showWarning } from '@nextcloud/dialogs'
import { NcButton, NcCounterBubble } from '@nextcloud/vue'
import { mapState, mapStores } from 'pinia'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultipleOutline.vue'
import AccountOutlineIcon from 'vue-material-design-icons/AccountOutline.vue'
import AttendeeChip from './AttendeeChip.vue'
import FreeBusy from '../FreeBusy/FreeBusy.vue'
import InviteesListSearch from './InviteesListSearch.vue'
import OrganizerNoEmailError from '../OrganizerNoEmailError.vue'
import useCalendarObjectInstanceStore from '../../../store/calendarObjectInstance.js'
import useCalendarsStore from '../../../store/calendars.js'
import usePrincipalsStore from '../../../store/principals.js'
import useSettingsStore from '../../../store/settings.js'
import { organizerDisplayName, removeMailtoPrefix } from '../../../utils/attendee.js'
import { getBusySlots } from '../../../services/freeBusySlotService.js'
import { principalPropertySearchByDisplaynameOrEmail } from '../../../services/caldavService.js'
import debounce from 'debounce'

const MAX_VISIBLE_CHIPS = 12

export default {
	name: 'InviteesChipList',
	components: {
		NcButton,
		NcCounterBubble,
		AccountMultipleIcon,
		AccountOutlineIcon,
		AttendeeChip,
		FreeBusy,
		InviteesListSearch,
		OrganizerNoEmailError,
	},

	props: {
		isReadOnly: {
			type: Boolean,
			required: true,
		},
		isSharedWithMe: {
			type: Boolean,
			required: true,
		},
		calendar: {
			type: Object,
			required: true,
		},
		calendarObjectInstance: {
			type: Object,
			required: true,
		},
	},

	emits: ['update-dates'],

	data() {
		return {
			showFreeBusy: false,
			isRequiredExpanded: false,
			isOptionalExpanded: false,
			recentAttendees: [],
			availabilityMap: {},
			userIdMap: {},
			loadingAvailability: false,
			dragOverSection: null,
			draggingAttendee: null,
		}
	},

	computed: {
		...mapStores(usePrincipalsStore, useCalendarsStore, useCalendarObjectInstanceStore),
		...mapState(useSettingsStore, ['talkEnabled']),

		invitees() {
			return this.calendarObjectInstance.attendees.filter((attendee) => {
				return !['RESOURCE', 'ROOM'].includes(attendee.attendeeProperty.userType)
			})
		},

		groups() {
			return this.invitees.filter((attendee) => {
				if (attendee.attendeeProperty.userType === 'GROUP') {
					attendee.members = this.invitees.filter((invitee) => {
						return invitee.attendeeProperty.member
							&& invitee.attendeeProperty.member.includes(attendee.uri)
							&& attendee.attendeeProperty.userType === 'GROUP'
					})
					return attendee.members.length > 0
				}
				return false
			})
		},

		inviteesWithoutOrganizer() {
			if (!this.calendarObjectInstance.organizer) {
				return this.invitees
			}

			return this.invitees.filter((attendee) => {
				if (this.groups.some((group) => {
					return attendee.attendeeProperty.member
						&& attendee.attendeeProperty.member.includes(group.uri)
						&& attendee.attendeeProperty.userType === 'INDIVIDUAL'
				})) {
					return false
				}

				if (attendee.attendeeProperty.userType === 'GROUP') {
					return attendee.members.length > 0
				}

				return attendee.uri !== this.calendarObjectInstance.organizer.uri
			})
		},

		// Split invitees by role
		requiredInvitees() {
			return this.inviteesWithoutOrganizer.filter((a) => {
				const role = a.role || 'REQ-PARTICIPANT'
				return ['REQ-PARTICIPANT', 'CHAIR'].includes(role)
			})
		},

		optionalInvitees() {
			return this.inviteesWithoutOrganizer.filter((a) => {
				const role = a.role || 'REQ-PARTICIPANT'
				return ['OPT-PARTICIPANT', 'NON-PARTICIPANT'].includes(role)
			})
		},

		requiredCount() {
			// Only count the organizer if we're not the organizer (OWA style)
			const includeOrganizer = this.hasOrganizer && !this.isOrganizer ? 1 : 0
			return this.requiredInvitees.length + includeOrganizer
		},

		displayedRequired() {
			if (this.isRequiredExpanded) {
				return this.requiredInvitees
			}
			return this.requiredInvitees.slice(0, MAX_VISIBLE_CHIPS)
		},

		displayedOptional() {
			if (this.isOptionalExpanded) {
				return this.optionalInvitees
			}
			return this.optionalInvitees.slice(0, MAX_VISIBLE_CHIPS)
		},

		requiredOverflow() {
			return Math.max(0, this.requiredInvitees.length - MAX_VISIBLE_CHIPS)
		},

		optionalOverflow() {
			return Math.max(0, this.optionalInvitees.length - MAX_VISIBLE_CHIPS)
		},

		isOrganizer() {
			return this.calendarObjectInstance.organizer !== null
				&& this.principalsStore.getCurrentUserPrincipal !== null
				&& removeMailtoPrefix(this.calendarObjectInstance.organizer.uri) === this.principalsStore.getCurrentUserPrincipal.emailAddress
		},

		hasOrganizer() {
			return this.calendarObjectInstance.organizer !== null
		},

		organizerDisplayName() {
			return organizerDisplayName(this.calendarObjectInstance.organizer)
		},

		isListEmpty() {
			return !this.calendarObjectInstance.organizer && this.invitees.length === 0
		},

		// All invited emails (for FreeBusy modal)
		alreadyInvitedEmails() {
			const emails = this.invitees.map((attendee) => removeMailtoPrefix(attendee.uri))
			const principal = this.principalsStore.getCurrentUserPrincipal
			const organizerUri = this.calendarObjectInstance.organizer?.uri
			if (organizerUri) {
				emails.push(removeMailtoPrefix(organizerUri))
			} else if (principal) {
				emails.push(principal.emailAddress)
			}
			return emails
		},

		// For Required search: exclude only required attendees + organizer
		// (allows selecting someone already in Optional — will move them)
		requiredInvitedEmails() {
			const emails = this.requiredInvitees.map((a) => removeMailtoPrefix(a.uri))
			const organizerUri = this.calendarObjectInstance.organizer?.uri
			if (organizerUri) {
				emails.push(removeMailtoPrefix(organizerUri))
			}
			return emails
		},

		// For Optional search: exclude only optional attendees + organizer
		// (allows selecting someone already in Required — will move them)
		optionalInvitedEmails() {
			const emails = this.optionalInvitees.map((a) => removeMailtoPrefix(a.uri))
			const organizerUri = this.calendarObjectInstance.organizer?.uri
			if (organizerUri) {
				emails.push(removeMailtoPrefix(organizerUri))
			}
			return emails
		},

		hasUserEmailAddress() {
			const principal = this.principalsStore.getCurrentUserPrincipal
			if (!principal) {
				return false
			}
			return !!principal.emailAddress
		},

		statusHeader() {
			if (!this.isReadOnly) {
				return ''
			}
			return this.t('calendar', '{confirmedCount} confirmed, {waitingCount} awaiting response', {
				confirmedCount: this.invitees
					.filter((a) => a.participationStatus === 'ACCEPTED').length + 1,
				waitingCount: this.invitees
					.filter((a) => a.participationStatus === 'NEEDS-ACTION').length,
			})
		},

		isViewedByOrganizer() {
			if (!this.calendarObjectInstance.organizer) return false
			const organizerEmail = removeMailtoPrefix(this.calendarObjectInstance.organizer.uri)
			return organizerEmail === this.principalsStore.getCurrentUserPrincipalEmail
		},
	},

	watch: {
		'calendarObjectInstance.attendees': {
			handler() {
				this.debouncedFetchAvailability()
				this.fetchUserIds()
			},
			deep: true,
		},
		'calendarObjectInstance.startDate': 'debouncedFetchAvailability',
		'calendarObjectInstance.endDate': 'debouncedFetchAvailability',
	},

	mounted() {
		this.debouncedFetchAvailability()
		this.fetchUserIds()
	},

	methods: {
		debouncedFetchAvailability: debounce(function() {
			this.fetchAvailability()
		}, 800),

		async fetchAvailability() {
			if (!this.calendarObjectInstance.startDate || !this.calendarObjectInstance.endDate) {
				return
			}
			if (this.invitees.length === 0 || !this.calendarObjectInstance.organizer) {
				return
			}

			this.loadingAvailability = true
			try {
				const organizer = this.calendarObjectInstance.organizer
				const attendees = this.calendarObjectInstance.attendees
				const startDate = this.calendarObjectInstance.startDate
				const endDate = this.calendarObjectInstance.endDate

				// getBusySlots expects attendeeProperty objects
				const result = await getBusySlots(
					organizer.attendeeProperty || organizer,
					attendees.map((a) => a.attendeeProperty || a),
					startDate,
					endDate,
					this.calendarObjectInstance.eventComponent?.startDate?.timezoneId || 'UTC',
					true, // bulk mode
				)

				const newMap = {}
				if (result && result.events) {
					const eventStart = startDate.getTime()
					const eventEnd = endDate.getTime()

					for (const invitee of this.invitees) {
						const uri = invitee.uri
						const busyEvents = result.events.filter(
							(e) => e.resourceId === uri || e.resourceId === removeMailtoPrefix(uri),
						)
						const isBusy = busyEvents.some((e) => {
							const busyStart = new Date(e.start).getTime()
							const busyEnd = new Date(e.end).getTime()
							return busyStart < eventEnd && busyEnd > eventStart
						})
						newMap[uri] = isBusy ? 'busy' : 'free'
					}
				}
				this.availabilityMap = newMap
			} catch (error) {
				console.debug('Failed to fetch availability:', error)
			} finally {
				this.loadingAvailability = false
			}
		},

		getAvailability(uri) {
			return this.availabilityMap[uri] || 'unknown'
		},

		getUserId(uri) {
			return this.userIdMap[uri] || null
		},

		async fetchUserIds() {
			for (const invitee of this.invitees) {
				const email = removeMailtoPrefix(invitee.uri)
				if (this.userIdMap[invitee.uri]) continue

				try {
					const principals = await principalPropertySearchByDisplaynameOrEmail(email)
					if (principals.length > 0 && principals[0].userId) {
						this.userIdMap = { ...this.userIdMap, [invitee.uri]: principals[0].userId }
					}
				} catch (error) {
					// Not a Nextcloud user, skip
				}
			}
		},

		addRequiredAttendee(attendeeData) {
			this.addAttendeeWithRole(attendeeData, 'REQ-PARTICIPANT')
		},

		addOptionalAttendee(attendeeData) {
			this.addAttendeeWithRole(attendeeData, 'OPT-PARTICIPANT')
		},

		addAttendeeWithRole({ commonName, email, calendarUserType, language, timezoneId, member }, role) {
			// Deduplication: if attendee already exists with a different role, change role instead
			const existingAttendee = this.inviteesWithoutOrganizer.find(
				(a) => removeMailtoPrefix(a.uri) === removeMailtoPrefix(email),
			)
			if (existingAttendee) {
				this.calendarObjectInstanceStore.changeAttendeesRole({
					attendee: existingAttendee,
					role,
				})
				return
			}

			let modifiedMember = null
			if (calendarUserType === 'INDIVIDUAL' && member) {
				const modifiedMemberIndex = this.calendarObjectInstance.attendees.findIndex(
					(attendee) => attendee.uri === email,
				)
				modifiedMember = this.calendarObjectInstance.attendees[modifiedMemberIndex]
			}

			if (modifiedMember) {
				const group = modifiedMember.attendeeProperty.member
				this.calendarObjectInstanceStore.removeAttendee({
					calendarObjectInstance: this.calendarObjectInstance,
					attendee: modifiedMember,
				})
				member = member.split(',')
				member.push(group)
			}

			this.calendarObjectInstanceStore.addAttendee({
				calendarObjectInstance: this.calendarObjectInstance,
				commonName,
				uri: email,
				calendarUserType,
				participationStatus: 'NEEDS-ACTION',
				role,
				rsvp: true,
				language,
				timezoneId,
				organizer: this.principalsStore.getCurrentUserPrincipal,
				member,
			})
			this.recentAttendees.push(email)
		},

		removeAttendee(attendee) {
			if (attendee.member) {
				this.groups.forEach((group) => {
					if (attendee.member.includes(group.uri)) {
						group.members = group.members.filter((m) => m.uri !== attendee.uri)
					}
				})
			}
			this.calendarObjectInstanceStore.removeAttendee({
				calendarObjectInstance: this.calendarObjectInstance,
				attendee,
			})
		},

		// ═══ Drag and drop ═══
		onChipDragStart(attendee) {
			this.draggingAttendee = attendee
		},

		onChipDragEnd() {
			this.draggingAttendee = null
			this.dragOverSection = null
		},

		onDragOver(section) {
			if (!this.draggingAttendee) return
			this.dragOverSection = section
		},

		onDragLeave() {
			this.dragOverSection = null
		},

		onDrop(newRole) {
			if (!this.draggingAttendee) return

			const attendee = this.draggingAttendee
			const currentRole = attendee.role || 'REQ-PARTICIPANT'

			// Only change if actually moving to a different section
			if (currentRole !== newRole) {
				this.calendarObjectInstanceStore.changeAttendeesRole({
					attendee,
					role: newRole,
				})
			}

			this.draggingAttendee = null
			this.dragOverSection = null
		},

		toggleFreeBusy() {
			if (this.isListEmpty) {
				return
			}
			this.showFreeBusy = !this.showFreeBusy
		},

		closeFreeBusy(showNoAttendeesToast = false) {
			if (showNoAttendeesToast) {
				showWarning(this.$t('calendar', 'Please add at least one attendee to use the "Find a time" feature.'))
			}
			this.showFreeBusy = false
		},

		saveNewDate(dates) {
			this.$emit('update-dates', dates)
			this.showFreeBusy = false
		},
	},
}
</script>

<style lang="scss" scoped>
.invitees-chip-list {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);

	&__section {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 1);
	}

	&__section-header {
		display: flex;
		justify-content: space-between;
		align-items: center;

		&__title {
			display: flex;
			gap: calc(var(--default-grid-baseline) * 2);
			font-size: var(--default-font-size);
			align-items: center;
			font-weight: 500;
			color: var(--color-text-maxcontrast);
		}
	}

	&__subtitle {
		color: var(--color-text-maxcontrast);
		font-size: calc(var(--default-font-size) * 0.9);
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		gap: calc(var(--default-grid-baseline) * 1);
		max-height: 120px;
		overflow-y: auto;
		padding: calc(var(--default-grid-baseline) * 1) 0;
		transition: max-height 0.3s ease, background-color 0.15s ease, border-color 0.15s ease;
		border: 2px solid transparent;
		border-radius: var(--border-radius-large, 10px);
		min-height: 32px;

		&--expanded {
			max-height: none;
		}

		&--drop-target {
			background-color: var(--color-primary-element-light);
			border-color: var(--color-primary-element);
		}
	}

	&__more-btn {
		align-self: flex-start;
	}

	&__drop-hint {
		color: var(--color-primary-element);
		font-size: calc(var(--default-font-size) * 0.85);
		padding: 4px 8px;
		font-style: italic;
	}
}
</style>
