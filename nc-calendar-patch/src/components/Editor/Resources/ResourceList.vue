<!--
  - SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="room-finder">
		<!-- Filters -->
		<div
			v-if="!isReadOnly && hasUserEmailAddress && resourceBookingEnabled"
			class="room-finder__filters">

			<!-- Search + Clear -->
			<div class="room-finder__search-row">
				<NcTextField
					:value="filterText"
					:placeholder="$t('calendar', 'Search rooms...')"
					:show-trailing-button="filterText.length > 0"
					trailing-button-icon="close"
					@update:value="filterText = $event"
					@trailing-button-click="filterText = ''" />
				<button
					v-if="hasActiveFilters"
					class="room-finder__clear"
					@click="clearFilters">
					{{ $t('calendar', 'Reset') }}
				</button>
			</div>

			<!-- Building -->
			<div v-if="buildingOptions.length > 1" class="room-finder__field">
				<label class="room-finder__label">{{ $t('calendar', 'Building') }}</label>
				<NcSelect
					v-model="selectedBuilding"
					:options="buildingOptions"
					:placeholder="$t('calendar', 'Select a building')"
					:clearable="true"
					input-id="room-finder-building" />
			</div>

			<!-- Capacity + Floor -->
			<div class="room-finder__row">
				<div class="room-finder__field room-finder__field--half">
					<label class="room-finder__label">{{ $t('calendar', 'Capacity') }}</label>
					<NcSelect
						v-model="selectedCapacity"
						:options="capacityOptions"
						:placeholder="$t('calendar', 'Any')"
						:clearable="true"
						label="label"
						:reduce="opt => opt.value"
						input-id="room-finder-capacity" />
				</div>
				<div v-if="floorOptions.length > 0" class="room-finder__field room-finder__field--half">
					<label class="room-finder__label">{{ $t('calendar', 'Floor') }}</label>
					<NcSelect
						v-model="selectedFloor"
						:options="floorOptions"
						:placeholder="$t('calendar', 'Any')"
						:clearable="true"
						input-id="room-finder-floor" />
				</div>
			</div>

			<!-- Features -->
			<div v-if="featureOptions.length > 0" class="room-finder__field">
				<label class="room-finder__label">{{ $t('calendar', 'Features') }}</label>
				<NcSelect
					v-model="selectedFeatures"
					:options="featureOptions"
					:placeholder="$t('calendar', 'No features available')"
					:multiple="true"
					:close-on-select="false"
					label="label"
					:reduce="opt => opt.id"
					input-id="room-finder-features" />
			</div>
		</div>

		<!-- Loading state -->
		<div v-if="isLoadingAvailability" class="room-finder__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<!-- Results -->
		<template v-else>
			<div class="room-finder__divider" />

			<div class="room-finder__results-header">
				<span class="room-finder__results-label">
					{{ $t('calendar', 'Suggested conference rooms') }}
				</span>
				<NcCheckboxRadioSwitch
					type="switch"
					:checked="showUnavailable"
					@update:checked="showUnavailable = $event">
					{{ $t('calendar', 'Show unavailable') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div class="room-finder__list">
				<ResourceRoomCard
					v-for="room in visibleRooms"
					:key="room.id"
					:room="room"
					:is-added="isRoomAdded(room)"
					:is-read-only="isReadOnly"
					:is-viewed-by-organizer="isViewedByOrganizer"
					:has-room-selected="resources.length > 0"
					@add-room="addResource"
					@remove-room="removeRoomByPrincipal" />

				<button
					v-if="hasMoreRooms"
					class="room-finder__show-more"
					@click="visibleCount += 8">
					{{ $t('calendar', 'Show {count} more', { count: Math.min(remainingCount, 8) }) }}
				</button>

				<p
					v-if="sortedRooms.length === 0 && allRooms.length > 0"
					class="room-finder__empty">
					{{ $t('calendar', 'No rooms found') }}
				</p>
				<p
					v-else-if="allRooms.length === 0 && !isLoadingAvailability"
					class="room-finder__empty">
					{{ $t('calendar', 'No rooms available') }}
				</p>
			</div>
		</template>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import debounce from 'debounce'
import { mapStores } from 'pinia'
import Vue from 'vue'
import ResourceRoomCard from './ResourceRoomCard.vue'
import { formatFacility } from '../../../models/resourceProps.js'
import { checkResourceAvailability } from '../../../services/freeBusyService.js'
import useCalendarObjectInstanceStore from '../../../store/calendarObjectInstance.js'
import usePrincipalsStore from '../../../store/principals.js'
import { organizerDisplayName, removeMailtoPrefix } from '../../../utils/attendee.js'
import logger from '../../../utils/logger.js'

export default {
	name: 'ResourceList',
	components: {
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSelect,
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
			showUnavailable: false,
			visibleCount: 8,
			filterText: '',
			selectedBuilding: null,
			selectedCapacity: null,
			selectedFloor: null,
			selectedFeatures: [],
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

		// ── Filter options ──────────────────────────────────────

		buildingOptions() {
			const buildings = new Set()
			for (const room of this.allRooms) {
				const name = room.roomBuildingName
				if (name) buildings.add(name)
			}
			return [...buildings].sort()
		},

		capacityOptions() {
			return [
				{ label: '2+', value: 2 },
				{ label: '4+', value: 4 },
				{ label: '8+', value: 8 },
				{ label: '12+', value: 12 },
				{ label: '20+', value: 20 },
				{ label: '50+', value: 50 },
			]
		},

		floorOptions() {
			const floors = new Set()
			for (const room of this.allRooms) {
				const floor = this.extractFloor(room.roomNumber)
				if (floor !== null) floors.add(floor)
			}
			return [...floors].sort((a, b) => a.localeCompare(b, undefined, { numeric: true }))
		},

		featureOptions() {
			const facilitySet = new Set()
			for (const room of this.allRooms) {
				const features = room.roomFeatures?.split(',') ?? []
				for (const f of features) {
					const trimmed = f.trim()
					if (trimmed) facilitySet.add(trimmed)
				}
			}
			return [...facilitySet].sort().map((id) => ({
				id,
				label: formatFacility(id),
			}))
		},

		hasActiveFilters() {
			return this.filterText !== ''
				|| this.selectedBuilding !== null
				|| this.selectedCapacity !== null
				|| this.selectedFloor !== null
				|| this.selectedFeatures.length > 0
		},

		// ── Filtered and sorted rooms ───────────────────────────

		filteredRooms() {
			return this.allRooms.filter((room) => {
				// Always show rooms that are already added to the event
				if (this.isRoomAdded(room)) {
					return true
				}
				// Availability filter
				if (!this.showUnavailable && !room.isAvailable) {
					return false
				}
				// Text filter
				if (this.filterText) {
					const q = this.filterText.toLowerCase()
					if (!room.displayname?.toLowerCase().includes(q)
						&& !room.roomAddress?.toLowerCase().includes(q)
						&& !room.roomBuildingAddress?.toLowerCase().includes(q)
						&& !room.roomBuildingName?.toLowerCase().includes(q)
						&& !room.roomNumber?.toLowerCase().includes(q)) {
						return false
					}
				}
				// Building filter
				if (this.selectedBuilding) {
					const building = room.roomBuildingName || ''
					if (building !== this.selectedBuilding) {
						return false
					}
				}
				// Capacity filter
				if (this.selectedCapacity) {
					const cap = parseInt(room.roomSeatingCapacity) || 0
					if (cap < this.selectedCapacity) {
						return false
					}
				}
				// Floor filter
				if (this.selectedFloor) {
					const floor = this.extractFloor(room.roomNumber)
					if (floor !== this.selectedFloor) {
						return false
					}
				}
				// Feature filters
				if (this.selectedFeatures.length > 0) {
					const features = room.roomFeatures?.split(',').map((f) => f.trim()) ?? []
					for (const required of this.selectedFeatures) {
						if (!features.includes(required)) {
							return false
						}
					}
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

		visibleRooms() {
			return this.sortedRooms.slice(0, this.visibleCount)
		},

		hasMoreRooms() {
			return this.sortedRooms.length > this.visibleCount
		},

		remainingCount() {
			return this.sortedRooms.length - this.visibleCount
		},
	},

	watch: {
		'calendarObjectInstance.startDate': 'debouncedLoadAvailability',
		'calendarObjectInstance.endDate': 'debouncedLoadAvailability',
		filterText() { this.visibleCount = 8 },
		selectedBuilding() { this.visibleCount = 8 },
		selectedCapacity() { this.visibleCount = 8 },
		selectedFloor() { this.visibleCount = 8 },
		selectedFeatures() { this.visibleCount = 8 },
		showUnavailable() { this.visibleCount = 8 },
	},

	created() {
		this.debouncedLoadAvailability = debounce(this.loadAvailability, 500)
	},

	async mounted() {
		if (this.resourceBookingEnabled && this.isViewedByOrganizer) {
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

		/**
		 * Extract floor number from roomNumber (e.g. "3.14" → "3", "B2" → "B2")
		 */
		extractFloor(roomNumber) {
			if (!roomNumber) return null
			const match = roomNumber.match(/^([A-Za-z]?\d+)/)
			return match ? match[1] : null
		},

		clearFilters() {
			this.filterText = ''
			this.selectedBuilding = null
			this.selectedCapacity = null
			this.selectedFloor = null
			this.selectedFeatures = []
		},

		addResource({ commonName, email, calendarUserType, language, timezoneId, roomAddress, roomBuildingAddress, roomBuildingName, roomNumber, roomSeatingCapacity, roomFeatures }) {
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
			// Build location: "Room Name, Building Address, Room X.XX"
			const location = this.buildLocationString({ commonName, roomAddress, roomBuildingAddress, roomBuildingName, roomNumber })
			this.updateLocation(location)

			// Set filters to match the selected room's properties
			if (roomBuildingName && this.buildingOptions.includes(roomBuildingName)) {
				this.selectedBuilding = roomBuildingName
			}
			if (roomNumber) {
				const floor = this.extractFloor(roomNumber)
				if (floor && this.floorOptions.includes(floor)) {
					this.selectedFloor = floor
				}
			}
			if (roomSeatingCapacity) {
				const cap = parseInt(roomSeatingCapacity) || 0
				// Find the highest capacity option that fits
				const match = [...this.capacityOptions].reverse().find((o) => cap >= o.value)
				if (match) {
					this.selectedCapacity = match.value
				}
			}
			if (roomFeatures) {
				const features = roomFeatures.split(',').map((f) => f.trim()).filter(Boolean)
				const validFeatures = features.filter((f) => this.featureOptions.some((o) => o.id === f))
				if (validFeatures.length > 0) {
					this.selectedFeatures = validFeatures
				}
			}
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
				// Clear location when room is removed
				this.calendarObjectInstanceStore.changeLocation({
					calendarObjectInstance: this.calendarObjectInstance,
					location: '',
				})
				// Clear filters
				this.clearFilters()
			}
		},

		buildLocationString({ commonName, roomAddress, roomBuildingAddress, roomBuildingName, roomNumber }) {
			// Build: "Room Name, Building Address, Room X.XX"
			const parts = []
			if (commonName) parts.push(commonName)
			if (roomBuildingAddress) parts.push(roomBuildingAddress)
			if (roomNumber) parts.push('Room ' + roomNumber)
			return parts.join(', ') || commonName || ''
		},

		updateLocation(location) {
			if (!location) {
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
.room-finder {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 4);

	&__search-row {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__clear {
		background: none;
		border: none;
		color: var(--color-primary-element);
		font-size: calc(var(--default-font-size) * 0.85);
		cursor: pointer;
		padding: 0;
		white-space: nowrap;
		flex-shrink: 0;

		&:hover {
			text-decoration: underline;
		}
	}

	&__filters {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__field {
		display: flex;
		flex-direction: column;
		gap: 2px;

		&--half {
			flex: 1;
			min-width: 0;
		}
	}

	&__row {
		display: flex;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__label {
		font-size: calc(var(--default-font-size) * 0.85);
		font-weight: 600;
		color: var(--color-text-maxcontrast);
	}

	&__divider {
		border-top: 1px solid var(--color-border);
		margin: calc(var(--default-grid-baseline) * 1) 0;
	}

	&__results-header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	&__results-label {
		font-size: calc(var(--default-font-size) * 0.9);
		font-weight: 600;
		color: var(--color-text-maxcontrast);
	}

	&__loading {
		display: flex;
		justify-content: center;
		padding: calc(var(--default-grid-baseline) * 4);
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 1);
	}

	&__show-more {
		background: none;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		color: var(--color-primary-element);
		font-size: calc(var(--default-font-size) * 0.9);
		padding: calc(var(--default-grid-baseline) * 2);
		cursor: pointer;
		text-align: center;
		width: 100%;

		&:hover {
			background: var(--color-background-hover);
			border-color: var(--color-primary-element);
		}
	}

	&__empty {
		text-align: center;
		color: var(--color-text-maxcontrast);
		padding: calc(var(--default-grid-baseline) * 4);
		font-size: calc(var(--default-font-size) * 0.9);
	}
}
</style>
