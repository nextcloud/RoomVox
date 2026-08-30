<template>
	<div class="support-settings">
		<!-- Section 1: About RoomVox -->
		<div class="settings-section">
			<h2>{{ t('roomvox', 'Support RoomVox') }}</h2>
			<p class="settings-section-desc">
				{{ t('roomvox', 'RoomVox is free and open source (AGPL-3.0). You can use all features without a subscription — no limits, no restrictions, no catch.') }}
			</p>
			<p class="settings-section-desc">
				{{ t('roomvox', 'If RoomVox is valuable to your organization, consider subscribing. Your subscription funds active development, guaranteed Nextcloud compatibility, and email support.') }}
			</p>
		</div>

		<!-- Section 2: What's included -->
		<div class="settings-section">
			<h2>{{ t('roomvox', 'What a subscription includes') }}</h2>

			<div class="includes-list">
				<div class="includes-item">
					<span class="includes-check">&#x2705;</span>
					<div class="includes-text">
						<span class="includes-label">{{ t('roomvox', 'Guaranteed compatibility') }}</span>
						<span class="includes-desc">{{ t('roomvox', 'Tested with every new Nextcloud release') }}</span>
					</div>
				</div>
				<div class="includes-item">
					<span class="includes-check">&#x2705;</span>
					<div class="includes-text">
						<span class="includes-label">{{ t('roomvox', 'Email support') }}</span>
						<span class="includes-desc">{{ t('roomvox', 'Direct support from the developers') }}</span>
					</div>
				</div>
				<div class="includes-item">
					<span class="includes-check">&#x2705;</span>
					<div class="includes-text">
						<span class="includes-label">{{ t('roomvox', 'Priority bug fixes') }}</span>
						<span class="includes-desc">{{ t('roomvox', 'Your issues get priority attention') }}</span>
					</div>
				</div>
				<div class="includes-item">
					<span class="includes-check">&#x2705;</span>
					<div class="includes-text">
						<span class="includes-label">{{ t('roomvox', 'Active development') }}</span>
						<span class="includes-desc">{{ t('roomvox', 'New features and improvements') }}</span>
					</div>
				</div>
			</div>
		</div>

		<!-- Section 3: Pricing CTA -->
		<div class="settings-section">
			<div class="cta-block">
				<NcButton type="primary"
					:href="pricingUrl"
					target="_blank"
					rel="noopener noreferrer">
					{{ t('roomvox', 'View pricing & plans') }}
				</NcButton>
				<p class="cta-contact">
					{{ t('roomvox', 'Questions?') }}
					<a href="mailto:info@voxcloud.nl">info@voxcloud.nl</a>
				</p>
			</div>
		</div>

		<!-- Section 4: Your installation -->
		<div class="settings-section">
			<h2>{{ t('roomvox', 'Your installation') }}</h2>

			<div v-if="licenseStats" class="stats-overview">
				<div class="stat-row">
					<div class="stat-info">
						<span class="stat-icon">🚪</span>
						<span class="stat-label">{{ t('roomvox', 'Rooms') }}</span>
					</div>
					<span class="stat-value">{{ licenseStats.totalRooms }}</span>
				</div>
				<div class="stat-row">
					<div class="stat-info">
						<span class="stat-icon">📁</span>
						<span class="stat-label">{{ t('roomvox', 'Room groups') }}</span>
					</div>
					<span class="stat-value">{{ licenseStats.totalRoomGroups }}</span>
				</div>
				<div class="stat-row">
					<div class="stat-info">
						<span class="stat-icon">👥</span>
						<span class="stat-label">{{ t('roomvox', 'Named users') }}</span>
					</div>
					<!-- Every account on the server, disabled ones included: nothing
					     is gated, so RoomVox runs in full for all of them. -->
					<span class="stat-value">{{ licenseStats.totalUsers || 0 }}</span>
				</div>
			</div>

			<NcNoteCard v-if="licenseStats && licenseStats.hasLicense && licenseStats.licenseValid" type="success">
				{{ t('roomvox', 'Subscription active — thank you for supporting RoomVox!') }}
			</NcNoteCard>

			<NcNoteCard v-if="licenseStats && licenseStats.hasLicense && !licenseStats.licenseValid" type="warning">
				{{ t('roomvox', 'Subscription key is invalid or expired.') }}
			</NcNoteCard>

			<!-- One card at most. An Enterprise instance with 400 users matches both
			     conditions, and two cards both asking to get in touch reads as nagging. -->
			<NcNoteCard v-if="subscriptionNudge" type="info">
				{{ subscriptionNudge }}
			</NcNoteCard>

			<div class="telemetry-section">
				<NcCheckboxRadioSwitch
					:model-value="telemetryEnabled"
					@update:model-value="toggleTelemetry">
					{{ t('roomvox', 'Send anonymous usage statistics to help improve RoomVox') }}
				</NcCheckboxRadioSwitch>

				<div v-if="telemetryEnabled" class="telemetry-actions">
					<NcButton type="secondary"
						:disabled="sendingTelemetry"
						@click="sendTelemetryNow">
						{{ sendingTelemetry ? t('roomvox', 'Sending …') : t('roomvox', 'Send report now') }}
					</NcButton>
					<span v-if="telemetryLastReport" class="telemetry-last-report">
						{{ t('roomvox', 'Last report') }}: {{ formatDate(telemetryLastReport) }}
					</span>
					<span v-else class="telemetry-last-report">
						{{ t('roomvox', 'No report sent yet') }}
					</span>
				</div>

				<NcNoteCard v-if="telemetryMessage" :type="telemetryMessageType" class="telemetry-feedback">
					{{ telemetryMessage }}
				</NcNoteCard>
			</div>
		</div>

		<!-- Section 6: Subscription key -->
		<div class="settings-section">
			<h2>{{ t('roomvox', 'Subscription key') }}</h2>

			<div class="field-row">
				<input id="license-key"
					v-model="licenseKey"
					type="text"
					:placeholder="t('roomvox', 'e.g. RVOX-XXXX-XXXX-XXXX-XXXX')"
					class="contact-input"
					@input="_userEditedLicenseKey = true">
			</div>
			<div class="license-key-actions">
				<NcButton type="primary"
					:disabled="savingLicense"
					@click="saveLicenseKey">
					{{ savingLicense ? t('roomvox', 'Saving …') : t('roomvox', 'Save & activate') }}
				</NcButton>
				<NcButton v-if="licenseStats && licenseStats.hasLicense"
					type="tertiary"
					:disabled="savingLicense"
					@click="removeLicenseKey">
					{{ t('roomvox', 'Remove subscription key') }}
				</NcButton>
			</div>
		</div>

		<!-- Section 7: Contact -->
		<div class="settings-section">
			<div class="contact-info-block">
				<p>
					{{ t('roomvox', 'Learn more about RoomVox') }}:
					<a href="https://voxcloud.nl" target="_blank" rel="noopener noreferrer">voxcloud.nl</a>
				</p>
				<p>
					{{ t('roomvox', 'Questions or feedback?') }}
					<a href="mailto:info@voxcloud.nl">info@voxcloud.nl</a>
				</p>
			</div>
		</div>

		<div v-if="message" :class="['message', messageType]">
			{{ message }}
		</div>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getLanguage, translate } from '@nextcloud/l10n'

const t = (app, text, vars = {}) => translate(app, text, vars)

export default {
	name: 'SupportSettings',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
	},

	data() {
		return {
			licenseStats: null,
			licenseKey: '',
			savingLicense: false,
			_userEditedLicenseKey: false,
			telemetryEnabled: true,
			telemetryLastReport: null,
			sendingTelemetry: false,
			telemetryMessage: '',
			telemetryMessageType: 'success',
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		/**
		 * The single suggestion shown to an instance without a subscription, or
		 * null when there is nothing worth saying.
		 *
		 * Enterprise wins when both apply: an organisation already paying
		 * Nextcloud for a subscription is a stronger signal than headcount
		 * alone, and repeating the point in a second card underneath adds
		 * nothing.
		 *
		 * Neither branch is a limit. RoomVox behaves identically above and
		 * below the threshold — the wording leads with that, because a message
		 * appearing at 100 users otherwise reads as a cap being approached.
		 * The number is not arbitrary: paid subscriptions start at 100 users
		 * in the price list, so below it there is nothing to suggest.
		 *
		 * Both texts point at Nextcloud, not at us. Subscriptions are sold and
		 * invoiced by Nextcloud GmbH under the ISV agreement and first-line
		 * support runs through them, so a VoxCloud address here would send an
		 * administrator down the wrong path and bypass their account manager.
		 */
		subscriptionNudge() {
			const s = this.licenseStats
			if (!s || s.hasLicense) return null

			if (s.hasValidSubscription || s.hasExtendedSupport) {
				return t('roomvox', 'Nextcloud Enterprise subscription detected on this instance. RoomVox subscriptions are sold through Nextcloud — contact your Nextcloud account manager or sales@nextcloud.com.')
			}

			// Absent threshold means an older backend; say nothing rather than
			// guessing a number the server did not send.
			const threshold = s.supportNudgeUserThreshold
			// The same figure a subscription is priced on: every account.
			const users = s.totalUsers
			if (typeof threshold !== 'number' || typeof users !== 'number' || users <= threshold) {
				return null
			}

			return t('roomvox', 'RoomVox is running for {count} users here. It stays free and fully functional under the AGPL — a subscription adds maintenance and support, and is sold through Nextcloud. Contact your Nextcloud account manager or sales@nextcloud.com.', { count: users })
		},

		pricingUrl() {
			const lang = (window.document?.documentElement?.lang || '').split('-')[0]
			return lang === 'nl' ? 'https://voxcloud.nl/pricing/#roomvox' : 'https://voxcloud.nl/en/pricing/#roomvox'
		},
	},

	mounted() {
		this.loadLicenseStats()
	},

	methods: {
		// Exposed so the template can use the same t('roomvox', …) form the
		// Nextcloud translation bot extracts.
		t,

		async loadLicenseStats() {
			try {
				const response = await axios.get(generateUrl('/apps/roomvox/api/license/stats'))
				if (response.data.success) {
					this.licenseStats = response.data.stats
					this.telemetryEnabled = response.data.stats.telemetryEnabled ?? true
					this.telemetryLastReport = response.data.stats.telemetryLastReport ?? null
					// Show masked key only on initial load, never overwrite user input
					if (this.licenseStats.hasLicense && !this._userEditedLicenseKey) {
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
				this.showMessage(t('roomvox', 'Please enter a subscription key'), 'error')
				return
			}
			this.savingLicense = true
			try {
				// Save the key
				const saveRes = await axios.post(generateUrl('/apps/roomvox/api/settings/license'), {
					licenseKey: key,
				})
				if (!saveRes.data.success) {
					this.showMessage(t('roomvox', 'Failed to save subscription key'), 'error')
					return
				}

				// Immediately validate
				const valRes = await axios.post(generateUrl('/apps/roomvox/api/license/validate'))
				if (valRes.data.success && valRes.data.validation?.valid) {
					// Report usage to bind instance to license
					await axios.post(generateUrl('/apps/roomvox/api/license/update-usage'))
					this.showMessage(t('roomvox', 'Subscription activated!'), 'success')
				} else {
					this.showMessage(t('roomvox', 'Subscription key saved but validation failed.'), 'error')
				}

				await this.loadLicenseStats()
			} catch (error) {
				console.error('Failed to save/validate license key:', error)
				this.showMessage(t('roomvox', 'Failed to save subscription key'), 'error')
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
				this._userEditedLicenseKey = false
				await this.loadLicenseStats()
				this.showMessage(t('roomvox', 'Subscription key removed.'), 'success')
			} catch (error) {
				this.showMessage(t('roomvox', 'Failed to remove subscription key'), 'error')
			} finally {
				this.savingLicense = false
			}
		},

		async toggleTelemetry(enabled) {
			this.telemetryEnabled = enabled
			try {
				await axios.put(generateUrl('/apps/roomvox/api/settings'), {
					telemetryEnabled: enabled,
				})
			} catch (error) {
				console.error('Failed to save telemetry setting:', error)
			}
		},

		async sendTelemetryNow() {
			this.sendingTelemetry = true
			this.telemetryMessage = ''
			try {
				const response = await axios.post(generateUrl('/apps/roomvox/api/license/telemetry'))
				if (response.data.success) {
					this.telemetryLastReport = response.data.lastReport
					this.telemetryMessage = t('roomvox', 'Report sent successfully')
					this.telemetryMessageType = 'success'
				} else {
					const serverMsg = response.data.message || ''
					this.telemetryMessage = serverMsg
						? t('roomvox', 'The telemetry server returned an error:') + ' ' + serverMsg
						: t('roomvox', 'Failed to send report')
					this.telemetryMessageType = 'warning'
				}
			} catch (error) {
				console.error('Failed to send telemetry:', error)
				this.telemetryMessage = t('roomvox', 'Could not reach the telemetry server. Please try again later.')
				this.telemetryMessageType = 'warning'
			} finally {
				this.sendingTelemetry = false
			}
		},

		formatDate(timestamp) {
			if (!timestamp) return ''
			return new Date(timestamp * 1000).toLocaleString(getLanguage().replace('_', '-'))
		},

		showMessage(text, type) {
			this.message = text
			this.messageType = type
			setTimeout(() => {
				this.message = ''
			}, 5000)
		},
	},
}
</script>

<style lang="scss" scoped>
.support-settings {
	max-width: 800px;
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

/* What's included list */
.includes-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-bottom: 24px;
}

.includes-item {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 12px 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.includes-check {
	font-size: 1.2em;
	flex-shrink: 0;
}

.includes-text {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.includes-label {
	font-weight: 600;
	color: var(--color-main-text);
}

.includes-desc {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

/* CTA block (View pricing button + contact email) */
.cta-block {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
}

.cta-contact {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	margin: 0;
}

.cta-contact a {
	color: var(--color-primary);
	text-decoration: none;
}

.cta-contact a:hover {
	text-decoration: underline;
}

/* Telemetry section */
.telemetry-section {
	margin-top: 24px;
}

.telemetry-actions {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 12px;
}

.telemetry-last-report {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.telemetry-feedback {
	margin-top: 12px;
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

/* Contact info block */
.contact-info-block {
	margin-bottom: 20px;
	padding: 16px 20px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);

	p {
		margin: 0 0 8px 0;
		line-height: 1.5;

		&:last-child {
			margin-bottom: 0;
		}
	}

	a {
		color: var(--color-primary-element);
		font-weight: 500;
		text-decoration: none;

		&:hover {
			text-decoration: underline;
		}
	}
}

.field-row {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;

	label {
		font-weight: 500;
		font-size: 14px;
	}
}

.contact-input {
	width: 100%;
	max-width: 400px;
	padding: 8px 12px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;

	&:focus {
		border-color: var(--color-primary-element);
		outline: none;
	}
}

/* License key section */
.license-key-actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

.message {
	margin-top: 15px;
	padding: 10px 15px;
	border-radius: var(--border-radius);
	font-size: 14px;

	&.success {
		background: #d4edda;
		color: #155724;
		border: 1px solid #c3e6cb;
	}

	&.error {
		background: #f8d7da;
		color: #721c24;
		border: 1px solid var(--color-error, #f5c6cb);
	}
}
</style>
