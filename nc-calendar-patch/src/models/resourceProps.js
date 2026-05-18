/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * Format a room type string for display
 *
 * @param {string|null} type The room type identifier
 * @return {string}
 */
export function formatRoomType(type) {
	const types = {
		'meeting-room': t('roomvox', 'Meeting room'),
		'board-room': t('roomvox', 'Board room'),
		'conference-room': t('roomvox', 'Conference room'),
		'lecture-hall': t('roomvox', 'Lecture hall'),
		'rehearsal-room': t('roomvox', 'Rehearsal room'),
		'studio': t('roomvox', 'Studio'),
		'outdoor-area': t('roomvox', 'Outdoor area'),
		'other': t('roomvox', 'Other'),
	}
	return types[type] || type || ''
}

/**
 * Get a human-readable label for a facility.
 * Calls to t() are inline (not behind a lookup table) so that translation
 * string extractors can pick them up.
 *
 * @param {string} facility The facility identifier
 * @return {string}
 */
export function formatFacility(facility) {
	const lower = facility.toLowerCase().trim()
	switch (lower) {
	case 'projector':
	case 'beamer':
		return t('roomvox', 'Projector')
	case 'whiteboard':
		return t('roomvox', 'Whiteboard')
	case 'video_conference':
	case 'videoconference':
		return t('roomvox', 'Video')
	case 'wheelchair_accessible':
	case 'wheelchair-accessible':
		return t('roomvox', 'Wheelchair')
	case 'audio':
		return t('roomvox', 'Audio')
	case 'display':
		return t('roomvox', 'Display')
	default:
		return facility.charAt(0).toUpperCase() + facility.slice(1)
	}
}
