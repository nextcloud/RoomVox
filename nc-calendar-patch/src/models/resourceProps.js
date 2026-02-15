/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Format a room type string for display
 *
 * @param {string|null} type The room type identifier
 * @return {string}
 */
export function formatRoomType(type) {
	const types = {
		'meeting-room': 'Meeting room',
		'board-room': 'Board room',
		'conference-room': 'Conference room',
		'lecture-hall': 'Lecture hall',
		'rehearsal-room': 'Rehearsal room',
		'studio': 'Studio',
		'outdoor-area': 'Outdoor area',
		'other': 'Other',
	}
	return types[type] || type || ''
}

/**
 * Short labels for known facility types
 */
const FACILITY_LABELS = {
	projector: 'Projector',
	beamer: 'Projector',
	whiteboard: 'Whiteboard',
	video_conference: 'Video',
	videoconference: 'Video',
	wheelchair_accessible: 'Wheelchair',
	'wheelchair-accessible': 'Wheelchair',
	audio: 'Audio',
	display: 'Display',
}

/**
 * Get a human-readable label for a facility
 *
 * @param {string} facility The facility identifier
 * @return {string}
 */
export function formatFacility(facility) {
	const lower = facility.toLowerCase().trim()
	return FACILITY_LABELS[lower] || facility.charAt(0).toUpperCase() + facility.slice(1)
}
