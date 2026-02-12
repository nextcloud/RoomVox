<!--
  - SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="resource-picker">
		<span class="app-full-subtitle">
			<MapMarker :size="20" />
			{{ $t('calendar', 'Rooms') }}
			<span v-if="resources.length" class="resource-picker__count">
				({{ resources.length }})
			</span>
		</span>

		<!-- Search and filter -->
		<div
			v-if="!isReadOnly && hasUserEmailAddress && resourceBookingEnabled"
			class="resource-picker__filters">
			<NcTextField
				:value="filterText"
				:placeholder="$t('calendar', 'Search rooms...')"
				:show-trailing-button="filterText.length > 0"
				trailing-button-icon="close"
				@update:value="filterText = $event"
				@trailing-button-click="filterText = ''" />
			<div class="resource-picker__filters__toggles">
				<NcCheckboxRadioSwitch
					:checked.sync="filterAvailableOnly"
					type="switch">
					{{ $t('calendar', 'Available only') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked.sync="filterAccessible">
					{{ $t('calendar', 'Wheelchair accessible') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked.sync="filterProjector">
					{{ $t('calendar', 'Projector') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:checked.sync="filterWhiteboard">
					{{ $t('calendar', 'Whiteboard') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<!-- Loading state -->
		<div v-if="isLoadingAvailability" class="resource-picker__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<!-- Room cards list -->
		<div v-else class="resource-picker__list">
			<ResourceRoomCard
				v-for="room in sortedRooms"
				:key="room.id"
				:room="room"
				:is-added="isRoomAdded(room)"
				:is-read-only="isReadOnly"
				:is-viewed-by-organizer="isViewedByOrganizer"
				@add-room="addResource"
				@remove-room="removeRoomByPrincipal" />

			<p
				v-if="sortedRooms.length === 0 && allRooms.length > 0"
				class="resource-picker__empty">
				{{ $t('calendar', 'No rooms found') }}
			</p>
			<p
				v-else-if="allRooms.length === 0 && !isLoadingAvailability"
				class="resource-picker__empty">
				{{ $t('calendar', 'No rooms available') }}
			</p>
		</div>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import debounce from 'debounce'
import { mapStores } from 'pinia'
import Vue from 'vue'
import MapMarker from 'vue-material-design-icons/MapMarker.vue'
import ResourceRoomCard from './ResourceRoomCard.vue'
import { checkResourceAvailability } from '../../../services/freeBusyService.js'
import useCalendarObjectInstanceStore from '../../../store/calendarObjectInstance.js'
import usePrincipalsStore from '../../../store/principals.js'
import { organizerDisplayName, removeMailtoPrefix } from '../../../utils/attendee.js'
import logger from '../../../utils/logger.js'

export default {
	name: 'ResourceList',
	components: {
		MapMarker,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcTextField,
		ResourceRoomCard,
	},

	props: {
		isReadOnly: {
			type: Boolean,
			required: true,
		},
		calendarObjectInstance: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			allRooms: [],
			isLoadingAvailability: false,
			filterText: '',
			filterAvailableOnly: true,
			filterAccessible: false,
			filterProjector: false,
			filterWhiteboard: false,
		}
	},

	computed: {
		...mapStores(usePrincipalsStore, useCalendarObjectInstanceStore),

		resources() {
			return this.calendarObjectInstance.attendees.filter((attendee) => {
				return ['ROOM', 'RESOURCE'].includes(attendee.attendeeProperty.userType)
			})
		},

		alreadyInvitedEmails() {
			return this.resources.map((attendee) => removeMailtoPrefix(attendee.uri))
		},

		organizerDisplayName() {
			return organizerDisplayName(this.calendarObjectInstance.organizer)
		},

		hasUserEmailAddress() {
			const emailAddress = this.principalsStore.getCurrentUserPrincipal?.emailAddress
			return !!emailAddress
		},

		isViewedByOrganizer() {
			if (!this.calendarObjectInstance.organizer) {
				return true
			}
			const organizerEmail = removeMailtoPrefix(this.calendarObjectInstance.organizer.uri)
			return organizerEmail === this.principalsStore.getCurrentUserPrincipalEmail
		},

		resourceBookingEnabled() {
			return loadState('calendar', 'resource_booking_enabled')
		},

		filteredRooms() {
			return this.allRooms.filter((room) => {
				// Text filter
				if (this.filterText) {
					const q = this.filterText.toLowerCase()
					if (!room.displayname?.toLowerCase().includes(q)
						&& !room.roomAddress?.toLowerCase().includes(q)) {
						return false
					}
				}
				// Available filter
				if (this.filterAvailableOnly && !room.isAvailable) {
					return false
				}
				// Feature filters
				const features = room.roomFeatures?.split(',') ?? []
				if (this.filterAccessible && !features.includes('WHEELCHAIR-ACCESSIBLE')) {
					return false
				}
				if (this.filterProjector && !features.includes('PROJECTOR')) {
					return false
				}
				if (this.filterWhiteboard && !features.includes('WHITEBOARD')) {
					return false
				}
				return true
			})
		},

		sortedRooms() {
			return [...this.filteredRooms].sort((a, b) => {
				// Booked rooms first
				const aAdded = this.isRoomAdded(a) ? 0 : 1
				const bAdded = this.isRoomAdded(b) ? 0 : 1
				if (aAdded !== bAdded) return aAdded - bAdded

				// Available before unavailable
				const aAvail = a.isAvailable ? 0 : 1
				const bAvail = b.isAvailable ? 0 : 1
				if (aAvail !== bAvail) return aAvail - bAvail

				// Alphabetically
				return (a.displayname || '').localeCompare(b.displayname || '')
			})
		},
	},

	watch: {
		'calendarObjectInstance.startDate': 'debouncedLoadAvailability',
		'calendarObjectInstance.endDate': 'debouncedLoadAvailability',
	},

	created() {
		this.debouncedLoadAvailability = debounce(this.loadAvailability, 500)
	},

	async mounted() {
		if (this.resourceBookingEnabled) {
			await this.loadAllRooms()
		}
	},

	methods: {
		async loadAllRooms() {
			this.isLoadingAvailability = true

			const roomPrincipals = this.principalsStore.getRoomPrincipals || []
			this.allRooms = roomPrincipals.map((p) => ({
				...p,
				isAvailable: true,
			}))

			await this.loadAvailability()
			this.isLoadingAvailability = false
		},

		async loadAvailability() {
			if (this.allRooms.length === 0) {
				return
			}

			const options = this.allRooms.map((r) => ({
				email: r.emailAddress,
				isAvailable: true,
			}))

			try {
				await checkResourceAvailability(
					options,
					this.principalsStore.getCurrentUserPrincipalEmail,
					this.calendarObjectInstance.eventComponent.startDate,
					this.calendarObjectInstance.eventComponent.endDate,
				)

				for (let i = 0; i < this.allRooms.length; i++) {
					const opt = options.find((o) => o.email === this.allRooms[i].emailAddress)
					if (opt) {
						Vue.set(this.allRooms, i, {
							...this.allRooms[i],
							isAvailable: opt.isAvailable,
						})
					}
				}
			} catch (error) {
				logger.error('Could not check room availability', { error })
			}
		},

		isRoomAdded(room) {
			return this.alreadyInvitedEmails.includes(room.emailAddress)
		},

		addResource({ commonName, email, calendarUserType, language, timezoneId, roomAddress }) {
			this.calendarObjectInstanceStore.addAttendee({
				calendarObjectInstance: this.calendarObjectInstance,
				commonName,
				uri: email,
				calendarUserType: calendarUserType || 'ROOM',
				participationStatus: 'NEEDS-ACTION',
				role: 'REQ-PARTICIPANT',
				rsvp: true,
				language,
				timezoneId,
				organizer: this.principalsStore.getCurrentUserPrincipal,
			})
			this.updateLocation(roomAddress)
		},

		removeResource(resource) {
			this.calendarObjectInstanceStore.removeAttendee({
				calendarObjectInstance: this.calendarObjectInstance,
				attendee: resource,
			})
		},

		removeRoomByPrincipal(room) {
			const attendee = this.resources.find(
				(a) => removeMailtoPrefix(a.uri) === room.emailAddress,
			)
			if (attendee) {
				this.removeResource(attendee)
			}
		},

		updateLocation(location) {
			if (this.calendarObjectInstance.location || this.calendarObjectInstance.eventComponent.location) {
				return
			}
			if (this.resources.length !== 1) {
				return
			}
			this.calendarObjectInstanceStore.changeLocation({
				calendarObjectInstance: this.calendarObjectInstance,
				location,
			})
		},
	},
}
</script>

<style lang="scss" scoped>
.resource-picker {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);

	&__count {
		font-size: var(--default-font-size);
		color: var(--color-text-maxcontrast);
		font-weight: normal;
	}

	&__filters {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);

		&__toggles {
			display: flex;
			flex-wrap: wrap;
			gap: calc(var(--default-grid-baseline) * 1) calc(var(--default-grid-baseline) * 3);
		}
	}

	&__loading {
		display: flex;
		justify-content: center;
		padding: calc(var(--default-grid-baseline) * 4);
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
		max-height: 400px;
		overflow-y: auto;
	}

	&__empty {
		text-align: center;
		color: var(--color-text-maxcontrast);
		padding: calc(var(--default-grid-baseline) * 4);
	}
}

.app-full-subtitle {
	font-size: calc(var(--default-font-size) * 1.2);
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
