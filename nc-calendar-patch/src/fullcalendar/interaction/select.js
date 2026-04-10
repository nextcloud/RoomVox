/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import useSettingsStore from '../../store/settings.js'
import { errorCatch } from '../utils/errors.js'

/**
 * Provides a function to select a time-range in the calendar-grid.
 * This will open the new event editor. Based on the user's preference,
 * either the popover or the full.
 *
 * @param {object} router The Vue router
 * @param {object} route The Vue route
 * @param {Window} window The window object
 * @return {Function}
 */
export default function(router, route, window) {
	const settingsStore = useSettingsStore()

	return errorCatch(function({ start, end, allDay }) {
		// RoomVox: Always use full-width editor for better UX
		// Original logic checked settingsStore.skipPopover, but we want NewFullView always
		let name = 'NewFullView'

		// Original auto-switch logic (now redundant, kept for reference):
		// if (window.innerWidth <= 1024 / 2 && name === 'NewPopoverView') {
		// 	name = 'NewFullView'
		// }

		// If we are already in a new event view, keep the full view
		if (['NewFullView', 'NewPopoverView'].includes(route.name)) {
			name = 'NewFullView'  // RoomVox: Force full view even if currently in popover
		}

		const params = {
			...route.params,
			allDay: allDay ? '1' : '0',
			dtstart: String(Math.floor(start.getTime() / 1000)),
			dtend: String(Math.floor(end.getTime() / 1000)),
		}

		// Don't push new route when day didn't changed
		if (name === route.name && params.allDay === route.params.allDay
			&& params.dtstart === route.params.dtstart && params.dtend === route.params.dtend) {
			return
		}

		router.push({ name, params })
	}, 'select')
}
