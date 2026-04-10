<!--
  - SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<span
		class="attendee-chip"
		:class="chipClass"
		:title="statusTooltip"
		:draggable="!isReadOnly && !isOrganizer"
		@dragstart="onDragStart"
		@dragend="onDragEnd">
		<NcAvatar
			class="attendee-chip__avatar"
			:user="avatarUser"
			:display-name="avatarDisplayName"
			:is-no-user="!userId"
			:size="20"
			:disable-tooltip="true"
			:show-user-status="false" />
		<span
			class="attendee-chip__dot"
			:class="dotClass" />
		<span class="attendee-chip__name">{{ displayName }}</span>
		<button
			v-if="!isReadOnly && !isOrganizer"
			class="attendee-chip__close"
			:aria-label="$t('calendar', 'Remove')"
			@click.stop="$emit('remove-attendee', attendee)">
			<Close :size="14" />
		</button>
	</span>
</template>

<script>
import { NcAvatar } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import { removeMailtoPrefix } from '../../../utils/attendee.js'

export default {
	name: 'AttendeeChip',
	components: {
		NcAvatar,
		Close,
	},

	props: {
		attendee: {
			type: Object,
			required: true,
		},
		isOrganizer: {
			type: Boolean,
			default: false,
		},
		isReadOnly: {
			type: Boolean,
			default: false,
		},
		availability: {
			type: String,
			default: 'unknown',
			validator: (v) => ['free', 'busy', 'tentative', 'unknown'].includes(v),
		},
		userId: {
			type: String,
			default: null,
		},
	},

	emits: ['remove-attendee', 'drag-start', 'drag-end'],

	computed: {
		displayName() {
			if (this.isOrganizer) {
				const name = this.attendee.commonName || removeMailtoPrefix(this.attendee.uri)
				return `${name} (${this.$t('calendar', 'organizer')})`
			}
			return this.attendee.commonName || removeMailtoPrefix(this.attendee.uri)
		},

		avatarUser() {
			// Use Nextcloud userId for real avatar lookup, fallback to display name
			return this.userId || this.attendee.commonName || removeMailtoPrefix(this.attendee.uri)
		},

		avatarDisplayName() {
			return this.attendee.commonName || removeMailtoPrefix(this.attendee.uri)
		},

		participationStatus() {
			return this.attendee.participationStatus || 'NEEDS-ACTION'
		},

		isGroup() {
			return this.attendee.attendeeProperty?.userType === 'GROUP'
		},

		chipClass() {
			return {
				'attendee-chip--organizer': this.isOrganizer,
				'attendee-chip--group': this.isGroup,
				'attendee-chip--draggable': !this.isReadOnly && !this.isOrganizer,
			}
		},

		dotClass() {
			if (this.participationStatus === 'DECLINED') {
				return 'attendee-chip__dot--declined'
			}
			if (this.availability === 'busy') {
				return 'attendee-chip__dot--busy'
			}
			if (this.participationStatus === 'TENTATIVE' || this.availability === 'tentative') {
				return 'attendee-chip__dot--tentative'
			}
			if (this.participationStatus === 'ACCEPTED' || this.availability === 'free') {
				return 'attendee-chip__dot--accepted'
			}
			return 'attendee-chip__dot--unknown'
		},

		statusTooltip() {
			const name = this.attendee.commonName || removeMailtoPrefix(this.attendee.uri)
			const email = removeMailtoPrefix(this.attendee.uri)
			const parts = [name]
			if (email !== name) {
				parts.push(email)
			}
			if (this.participationStatus === 'DECLINED') {
				parts.push(this.$t('calendar', 'Declined'))
			} else if (this.availability === 'busy') {
				parts.push(this.$t('calendar', 'Busy at this time'))
			} else if (this.participationStatus === 'TENTATIVE') {
				parts.push(this.$t('calendar', 'Tentative'))
			} else if (this.participationStatus === 'ACCEPTED') {
				parts.push(this.$t('calendar', 'Accepted'))
			} else if (this.availability === 'free') {
				parts.push(this.$t('calendar', 'Available'))
			} else {
				parts.push(this.$t('calendar', 'Awaiting response'))
			}
			return parts.join(' — ')
		},
	},

	methods: {
		onDragStart(e) {
			e.dataTransfer.effectAllowed = 'move'
			e.dataTransfer.setData('text/plain', this.attendee.uri)
			this.$emit('drag-start', this.attendee)
		},
		onDragEnd(e) {
			this.$emit('drag-end', this.attendee)
		},
	},
}
</script>

<style lang="scss" scoped>
.attendee-chip {
	display: inline-flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 1);
	padding: 2px calc(var(--default-grid-baseline) * 2) 2px 2px;
	border-radius: var(--border-radius-pill, 1em);
	background-color: var(--color-background-dark);
	font-size: var(--default-font-size);
	line-height: 1.2;
	max-width: 220px;
	cursor: default;

	&--organizer {
		font-weight: 500;
	}

	&--draggable {
		cursor: grab;

		&:active {
			cursor: grabbing;
			opacity: 0.6;
		}
	}

	&__avatar {
		flex-shrink: 0;
	}

	&__dot {
		display: inline-block;
		width: 8px;
		height: 8px;
		border-radius: 50%;
		flex-shrink: 0;

		&--accepted {
			background-color: #2fb130;
		}

		&--declined,
		&--busy {
			background-color: #ff0000;
		}

		&--tentative {
			background-color: #ffa704;
		}

		&--unknown {
			background-color: var(--color-text-maxcontrast);
		}
	}

	&__name {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__close {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		background: none;
		border: none;
		padding: 0;
		margin: 0;
		cursor: pointer;
		color: var(--color-text-maxcontrast);
		flex-shrink: 0;
		border-radius: 50%;
		width: 20px;
		height: 20px;

		&:hover {
			color: var(--color-main-text);
			background-color: var(--color-background-hover);
		}
	}
}
</style>
