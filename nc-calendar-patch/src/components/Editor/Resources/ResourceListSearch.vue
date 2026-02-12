<!--
  - SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="resource-search">
		<NcButton
			type="tertiary"
			@click="showRoomAvailabilityModal = true">
			<template #icon>
				<CalendarClock :size="20" />
			</template>
			{{ $t('calendar', 'Timeline view') }}
		</NcButton>

		<RoomAvailabilityList
			v-if="showRoomAvailabilityModal"
			:show-dialog.sync="showRoomAvailabilityModal"
			:calendar-object-instance="calendarObjectInstance"
			:start-date="calendarObjectInstance.startDate"
			:end-date="calendarObjectInstance.endDate" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import RoomAvailabilityList from '../FreeBusy/RoomAvailabilityList.vue'

export default {
	name: 'ResourceListSearch',
	components: {
		NcButton,
		CalendarClock,
		RoomAvailabilityList,
	},

	props: {
		alreadyInvitedEmails: {
			type: Array,
			required: true,
		},
		calendarObjectInstance: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			showRoomAvailabilityModal: false,
		}
	},
}
</script>
