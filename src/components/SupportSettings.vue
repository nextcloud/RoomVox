<template>
	<!-- Four sections in the fixed order of the design guidelines (§7, "The
	     Statistics and Support panes"): About, Subscription, Usage statistics,
	     Help. Installation figures other than named users live on Statistics. -->
	<div class="support-settings">
		<NcSettingsSection :name="t('roomvox', 'About RoomVox')"
			:description="t('roomvox', 'RoomVox is free and open source (AGPL-3.0). You can use all features without a subscription — no limits, no restrictions, no catch.')" />

		<NcSettingsSection :name="t('roomvox', 'Subscription')"
			:description="t('roomvox', 'If RoomVox is valuable to your organization, consider subscribing. Your subscription funds active development, guaranteed Nextcloud compatibility, and email support.')">
			<h3 class="support-heading">
				{{ t('roomvox', 'What a subscription includes') }}
			</h3>
			<ul class="includes-list">
				<li v-for="item in includes" :key="item.label" class="includes-item">
					<component :is="item.icon" :size="20" class="includes-icon" aria-hidden="true" />
					<div class="includes-text">
						<span class="includes-label">{{ item.label }}</span>
						<span class="includes-desc">{{ item.description }}</span>
					</div>
				</li>
			</ul>

			<p class="support-line">
				{{ t('roomvox', 'Subscriptions are sold through Nextcloud. Contact your Nextcloud account manager, or') }}
				<a href="mailto:sales@nextcloud.com">sales@nextcloud.com</a>
			</p>

			<!-- Every account on the server, disabled ones included: nothing is
			     gated, so RoomVox runs in full for all of them. The one figure a
			     licence is measured by; the other counts are on Statistics. -->
			<p v-if="licenseStats" class="support-line">
				{{ n('roomvox', '%n named user on this server', '%n named users on this server', licenseStats.totalUsers || 0) }}
			</p>

			<!-- The same notice as the banner above the tabs, which App.vue hides
			     on this pane: on screen once (design guidelines §7). -->
			<NcNoteCard v-if="subscriptionNudge" type="info">
				{{ subscriptionNudge }}
			</NcNoteCard>

			<div class="license-key">
				<NcTextField v-model="licenseKey"
					:label="t('roomvox', 'Subscription key')"
					:placeholder="t('roomvox', 'e.g. RVOX-XXXX-XXXX-XXXX-XXXX')"
					:error="!!licenseKeyError"
					:helper-text="licenseKeyError"
					@update:model-value="onLicenseKeyInput" />
				<div class="license-key-actions">
					<NcButton variant="primary"
						:disabled="savingLicense"
						@click="saveLicenseKey">
						{{ savingLicense ? t('roomvox', 'Saving …') : t('roomvox', 'Save & activate') }}
					</NcButton>
					<NcButton v-if="licenseStats && licenseStats.hasLicense"
						variant="tertiary"
						:disabled="savingLicense"
						@click="removeLicenseKey">
						{{ t('roomvox', 'Remove subscription key') }}
					</NcButton>
				</div>
			</div>

			<NcNoteCard v-if="licenseStats && licenseStats.hasLicense && licenseStats.licenseValid" type="success">
				{{ t('roomvox', 'Subscription active — thank you for supporting RoomVox!') }}
			</NcNoteCard>
			<NcNoteCard v-if="licenseStats && licenseStats.hasLicense && !licenseStats.licenseValid" type="warning">
				{{ t('roomvox', 'Subscription key is invalid or expired.') }}
			</NcNoteCard>
		</NcSettingsSection>

		<!-- The one switch for usage statistics, next to the subscription key
		     (TELEMETRY.md §6). The field list and each field's purpose come from
		     the server, from the definition the report itself is built from. -->
		<NcSettingsSection :name="t('roomvox', 'Usage statistics')"
			:description="t('roomvox', 'With your permission, RoomVox sends usage statistics about this installation to licenses.voxcloud.nl, run by VoxCloud, once a day. No personal data, content or names are sent. Nothing is sent until you switch this on.')">
			<NcCheckboxRadioSwitch type="switch"
				:model-value="telemetryEnabled"
				:loading="savingTelemetry"
				:disabled="!telemetry"
				@update:model-value="toggleTelemetry">
				{{ t('roomvox', 'Share usage statistics') }}
			</NcCheckboxRadioSwitch>

			<template v-if="telemetry">
				<!-- Closed on load; opened by the consent notification's link
				     (#usage-statistics). The count stays visible while closed. -->
				<!-- Closed on load (design guidelines §7), except when a later
				     version added fields that wait for agreement: the button
				     below asks about them, so they are shown. -->
				<details id="usage-statistics"
					ref="fieldList"
					class="telemetry-disclosure"
					:open="telemetryHasWithheld || undefined">
					<summary>
						<ChevronRight :size="20" class="disclosure__chevron" aria-hidden="true" />
						{{ n('roomvox', 'What is sent, and what it is used for (%n field)', 'What is sent, and what it is used for (%n fields)', telemetry.fields.length) }}
					</summary>
					<dl class="telemetry-fields">
						<div v-for="field in telemetry.fields" :key="field.key" class="telemetry-field">
							<dt>
								{{ field.label }}
								<span v-if="field.withheld" class="telemetry-withheld">
									{{ t('roomvox', 'Not sent until you agree to it') }}
								</span>
							</dt>
							<dd>{{ field.purpose }}</dd>
						</div>
					</dl>
				</details>
				<!-- Only when a later version added fields to an existing yes:
				     those stay on this server until agreed to (TELEMETRY.md §2). -->
				<NcButton v-if="telemetryHasWithheld"
					variant="secondary"
					:disabled="savingTelemetry"
					@click="toggleTelemetry(true)">
					{{ t('roomvox', 'Agree to the added fields') }}
				</NcButton>
			</template>

			<div v-if="telemetryEnabled" class="telemetry-actions">
				<NcButton variant="secondary"
					:disabled="sendingTelemetry"
					@click="sendTelemetryNow">
					{{ sendingTelemetry ? t('roomvox', 'Sending …') : t('roomvox', 'Send report now') }}
				</NcButton>
				<span v-if="telemetryLastReport" class="telemetry-last-report">
					{{ t('roomvox', 'Last report: {date}', { date: formatDate(telemetryLastReport) }) }}
				</span>
				<span v-else class="telemetry-last-report">
					{{ t('roomvox', 'No report sent yet') }}
				</span>
			</div>

			<NcNoteCard v-if="telemetryMessage" :type="telemetryMessageType">
				{{ telemetryMessage }}
			</NcNoteCard>

			<!-- The licence-usage report is not governed by this switch; it is
			     named here so it does not come as a surprise (TELEMETRY.md §7). -->
			<NcNoteCard type="info">
				{{ t('roomvox', 'Separate from this switch: while a subscription key is entered, RoomVox also reports the key, the installation identifier and the number of rooms, room groups, user accounts and disabled accounts to licenses.voxcloud.nl, so the subscription can be checked and seats counted. That report is part of the subscription and stops when the key is removed.') }}
			</NcNoteCard>
		</NcSettingsSection>

		<NcSettingsSection :name="t('roomvox', 'Help')">
			<p class="support-line">
				{{ t('roomvox', 'Questions or feedback?') }}
				<a href="mailto:info@voxcloud.nl">info@voxcloud.nl</a>
			</p>
			<div class="help-links">
				<NcButton variant="secondary"
					:href="docsUrl"
					target="_blank">
					<template #icon>
						<BookOpenVariant :size="20" />
					</template>
					{{ t('roomvox', 'Documentation') }}
				</NcButton>
				<NcButton variant="secondary"
					href="https://github.com/nextcloud/RoomVox/issues"
					target="_blank">
					<template #icon>
						<BugOutline :size="20" />
					</template>
					{{ t('roomvox', 'Report an issue') }}
				</NcButton>
				<NcButton variant="secondary"
					href="https://voxcloud.nl"
					target="_blank">
					<template #icon>
						<Apps :size="20" />
					</template>
					{{ t('roomvox', 'Other Vox apps') }}
				</NcButton>
			</div>
		</NcSettingsSection>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Apps from 'vue-material-design-icons/Apps.vue'
import BookOpenVariant from 'vue-material-design-icons/BookOpenVariant.vue'
import BugOutline from 'vue-material-design-icons/BugOutline.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import RocketLaunchOutline from 'vue-material-design-icons/RocketLaunchOutline.vue'
import ShieldCheckOutline from 'vue-material-design-icons/ShieldCheckOutline.vue'
import { subscriptionNudge as buildSubscriptionNudge } from '../composables/useSubscriptionNudge.js'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { getLanguage, translate, translatePlural } from '@nextcloud/l10n'

const t = (app, text, vars = {}) => translate(app, text, vars)
const n = (app, singular, plural, count, vars = {}) => translatePlural(app, singular, plural, count, vars)

// The anchor the consent notification links to (lib/Notification/Notifier.php).
const FIELD_LIST_HASH = '#usage-statistics'

export default {
	name: 'SupportSettings',

	components: {
		Apps,
		BookOpenVariant,
		BugOutline,
		ChevronRight,
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSettingsSection,
		NcTextField,
	},

	data() {
		return {
			licenseStats: null,
			licenseKey: '',
			licenseKeyError: '',
			savingLicense: false,
			// Once the administrator types, a reload of the stats must not put
			// the masked key back over their input.
			userEditedLicenseKey: false,
			// Off until the server says otherwise: a missing answer is a no.
			telemetry: null,
			savingTelemetry: false,
			sendingTelemetry: false,
			telemetryMessage: '',
			telemetryMessageType: 'success',
		}
	},

	computed: {
		/**
		 * Delegates to the shared helper so the banner above the tabs and this
		 * tab can never disagree about the same server.
		 */
		subscriptionNudge() {
			return buildSubscriptionNudge(this.licenseStats)
		},

		includes() {
			return [
				{ icon: ShieldCheckOutline, label: t('roomvox', 'Guaranteed compatibility'), description: t('roomvox', 'Tested with every new Nextcloud release') },
				{ icon: EmailOutline, label: t('roomvox', 'Email support'), description: t('roomvox', 'Direct support from the developers') },
				{ icon: BugOutline, label: t('roomvox', 'Priority bug fixes'), description: t('roomvox', 'Your issues get priority attention') },
				{ icon: RocketLaunchOutline, label: t('roomvox', 'Active development'), description: t('roomvox', 'New features and improvements') },
			]
		},

		// The site publishes Dutch at /docs/roomvox/ and English at /docs/en/roomvox/.
		docsUrl() {
			return getLanguage().startsWith('nl')
				? 'https://voxcloud.nl/docs/roomvox/'
				: 'https://voxcloud.nl/docs/en/roomvox/'
		},

		telemetryEnabled() {
			return this.telemetry?.enabled ?? false
		},

		telemetryLastReport() {
			return this.telemetry?.lastReport ?? null
		},

		telemetryHasWithheld() {
			return (this.telemetry?.fields ?? []).some(field => field.withheld)
		},
	},

	async mounted() {
		window.addEventListener('hashchange', this.openFieldListFromHash)
		await this.loadLicenseStats()
		this.openFieldListFromHash()
	},

	beforeUnmount() {
		window.removeEventListener('hashchange', this.openFieldListFromHash)
	},

	methods: {
		// Exposed so the template can use the same t('roomvox', …) and
		// n('roomvox', …) forms the Nextcloud translation bot extracts.
		t,
		n,

		/**
		 * An administrator who followed "what is sent" from the notification
		 * sees the list without a second click (design guidelines §7).
		 */
		openFieldListFromHash() {
			if (window.location.hash !== FIELD_LIST_HASH) {
				return
			}
			this.$nextTick(() => {
				const list = this.$refs.fieldList
				if (!list) {
					return
				}
				list.open = true
				list.scrollIntoView({ block: 'start' })
			})
		},

		onLicenseKeyInput() {
			this.userEditedLicenseKey = true
			this.licenseKeyError = ''
		},

		async loadLicenseStats() {
			try {
				const response = await axios.get(generateUrl('/apps/roomvox/api/license/stats'))
				if (response.data.success) {
					this.licenseStats = response.data.stats
					this.telemetry = response.data.stats.telemetry ?? null
					// Show masked key only on initial load, never overwrite user input
					if (this.licenseStats.hasLicense && !this.userEditedLicenseKey) {
						this.licenseKey = this.licenseStats.licenseKeyMasked || ''
					}
				}
			} catch (error) {
				console.error('Failed to load license stats:', error)
			}
		},

		async saveLicenseKey() {
			const key = this.licenseKey.trim()
			if (!key) {
				this.licenseKeyError = t('roomvox', 'Please enter a subscription key')
				return
			}
			this.savingLicense = true
			try {
				const saveRes = await axios.post(generateUrl('/apps/roomvox/api/settings/license'), {
					licenseKey: key,
				})
				if (!saveRes.data.success) {
					showError(t('roomvox', 'Failed to save subscription key'))
					return
				}

				// Immediately validate
				const valRes = await axios.post(generateUrl('/apps/roomvox/api/license/validate'))
				if (valRes.data.success && valRes.data.validation?.valid) {
					// Report usage to bind instance to license
					await axios.post(generateUrl('/apps/roomvox/api/license/update-usage'))
					showSuccess(t('roomvox', 'Subscription activated!'))
				} else {
					showError(t('roomvox', 'Subscription key saved but validation failed.'))
				}

				await this.loadLicenseStats()
			} catch (error) {
				console.error('Failed to save/validate license key:', error)
				showError(t('roomvox', 'Failed to save subscription key'))
			} finally {
				this.savingLicense = false
			}
		},

		async removeLicenseKey() {
			this.savingLicense = true
			try {
				await axios.post(generateUrl('/apps/roomvox/api/settings/license'), {
					licenseKey: '',
				})
				this.licenseKey = ''
				this.licenseKeyError = ''
				this.userEditedLicenseKey = false
				await this.loadLicenseStats()
				showSuccess(t('roomvox', 'Subscription key removed.'))
			} catch (error) {
				showError(t('roomvox', 'Failed to remove subscription key'))
			} finally {
				this.savingLicense = false
			}
		},

		async toggleTelemetry(enabled) {
			this.savingTelemetry = true
			this.telemetryMessage = ''
			try {
				const response = await axios.put(generateUrl('/apps/roomvox/api/license/telemetry'), {
					enabled: enabled === true,
				})
				if (response.data.success) {
					this.telemetry = response.data.telemetry
				}
			} catch (error) {
				console.error('Failed to save usage statistics setting:', error)
				this.telemetryMessage = t('roomvox', 'Could not save the setting. Please try again.')
				this.telemetryMessageType = 'error'
			} finally {
				this.savingTelemetry = false
			}
		},

		async sendTelemetryNow() {
			this.sendingTelemetry = true
			this.telemetryMessage = ''
			try {
				const response = await axios.post(generateUrl('/apps/roomvox/api/license/telemetry'))
				if (response.data.success) {
					if (this.telemetry) {
						this.telemetry.lastReport = response.data.lastReport
					}
					this.telemetryMessage = t('roomvox', 'Report sent')
					this.telemetryMessageType = 'success'
				} else if (response.data.reason === 'recently_sent') {
					// The server accepts one report per hour. Not an error.
					this.telemetryMessage = t('roomvox', 'Already sent recently')
					this.telemetryMessageType = 'info'
				} else if (response.data.reason === 'disabled') {
					this.telemetryMessage = t('roomvox', 'Usage statistics are switched off, so no report was sent.')
					this.telemetryMessageType = 'info'
				} else {
					const serverMsg = response.data.message || ''
					this.telemetryMessage = serverMsg
						? t('roomvox', 'The statistics server returned an error: {message}', { message: serverMsg })
						: t('roomvox', 'Failed to send report')
					this.telemetryMessageType = 'warning'
				}
			} catch (error) {
				console.error('Failed to send usage statistics:', error)
				this.telemetryMessage = t('roomvox', 'Could not reach the statistics server. Please try again later.')
				this.telemetryMessageType = 'warning'
			} finally {
				this.sendingTelemetry = false
			}
		},

		formatDate(timestamp) {
			if (!timestamp) return ''
			return new Date(timestamp * 1000).toLocaleString(getLanguage().replace('_', '-'))
		},
	},
}
</script>

<style lang="scss" scoped>
.support-heading {
	font-size: var(--default-font-size);
	font-weight: var(--font-weight-heading);
	margin: 0 0 8px;
}

/* Contact routing is content: body size in the main text colour (§7). */
.support-line {
	margin: 0 0 12px;
	color: var(--color-main-text);

	a {
		color: var(--color-primary-element);
		text-decoration: underline;
	}
}

/* What a subscription includes */
.includes-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin: 0 0 16px;
	padding: 0;
	list-style: none;
}

.includes-item {
	display: flex;
	align-items: flex-start;
	gap: 12px;
}

.includes-icon {
	flex-shrink: 0;
	color: var(--color-primary-element);
}

.includes-text {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.includes-label {
	font-weight: var(--font-weight-element);
	color: var(--color-main-text);
}

.includes-desc {
	color: var(--color-text-maxcontrast);
}

/* Subscription key */
.license-key {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-block: 16px;
}

.license-key-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

/* Usage statistics: the field list behind a disclosure. A flex <summary>
   loses its native marker, so it carries its own rotating chevron
   (design guidelines §7, "A disclosure needs a marker"). */
.telemetry-disclosure {
	margin-block: 12px;

	summary {
		display: flex;
		align-items: center;
		gap: 4px;
		min-height: var(--default-clickable-area);
		padding-inline-end: 8px;
		border-radius: var(--border-radius-element);
		cursor: pointer;
		list-style: none;
		font-weight: var(--font-weight-element);

		&::-webkit-details-marker {
			display: none;
		}

		&:hover {
			background-color: var(--color-background-hover);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: -2px;
		}
	}
}

.disclosure__chevron {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
	transition: transform var(--animation-quick) ease-in-out;
}

.telemetry-disclosure[open] .disclosure__chevron {
	transform: rotate(90deg);
}

@media (prefers-reduced-motion: reduce) {
	.disclosure__chevron {
		transition: none;
	}
}

.telemetry-fields {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin: 8px 0 0;
	padding-inline-start: 24px;
}

.telemetry-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

/* Nextcloud core styles every dt/dd globally (server.css): inline-block,
   12px padding, and a dt of 130px that does not wrap and right-aligns.
   Long labels then ran over their purpose and short ones looked indented.
   Reset all of it: label above its purpose, both from the left edge
   (design guidelines §6, detail lists). */
.telemetry-field dt,
.telemetry-field dd {
	display: block;
	width: auto;
	margin: 0;
	padding: 0;
	white-space: normal;
	text-align: start;
}

.telemetry-field dt {
	font-weight: var(--font-weight-element);
	color: var(--color-main-text);
}

.telemetry-field dd {
	color: var(--color-text-maxcontrast);
}

.telemetry-withheld {
	font-weight: var(--font-weight-default);
	color: var(--color-text-maxcontrast);
	margin-inline-start: 8px;
}

.telemetry-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	margin-block: 12px;
}

.telemetry-last-report {
	color: var(--color-text-maxcontrast);
}

/* Help */
.help-links {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
