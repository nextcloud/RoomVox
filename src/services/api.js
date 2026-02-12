import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = (path) => generateUrl(`/apps/roomvox${path}`)

// Rooms
export const getRooms = () => axios.get(baseUrl('/api/rooms'))
export const getRoom = (id) => axios.get(baseUrl(`/api/rooms/${id}`))
export const createRoom = (data) => axios.post(baseUrl('/api/rooms'), data)
export const updateRoom = (id, data) => axios.put(baseUrl(`/api/rooms/${id}`), data)
export const deleteRoom = (id) => axios.delete(baseUrl(`/api/rooms/${id}`))

// Permissions
export const getPermissions = (id) => axios.get(baseUrl(`/api/rooms/${id}/permissions`))
export const setPermissions = (id, data) => axios.put(baseUrl(`/api/rooms/${id}/permissions`), data)

// Room Groups
export const getRoomGroups = () => axios.get(baseUrl('/api/room-groups'))
export const getRoomGroup = (id) => axios.get(baseUrl(`/api/room-groups/${id}`))
export const createRoomGroup = (data) => axios.post(baseUrl('/api/room-groups'), data)
export const updateRoomGroup = (id, data) => axios.put(baseUrl(`/api/room-groups/${id}`), data)
export const deleteRoomGroup = (id) => axios.delete(baseUrl(`/api/room-groups/${id}`))

// Room Group Permissions
export const getGroupPermissions = (id) => axios.get(baseUrl(`/api/room-groups/${id}/permissions`))
export const setGroupPermissions = (id, data) => axios.put(baseUrl(`/api/room-groups/${id}/permissions`), data)

// Bookings
export const getAllBookings = (params = {}) => axios.get(baseUrl('/api/all-bookings'), { params })
export const getBookings = (id, params = {}) => axios.get(baseUrl(`/api/rooms/${id}/bookings`), { params })
export const createBooking = (roomId, data) =>
    axios.post(baseUrl(`/api/rooms/${roomId}/bookings`), data)
export const updateBooking = (roomId, bookingUid, data) =>
    axios.put(baseUrl(`/api/rooms/${roomId}/bookings/${bookingUid}`), data)
export const respondToBooking = (roomId, bookingUid, action) =>
    axios.post(baseUrl(`/api/rooms/${roomId}/bookings/${bookingUid}/respond`), { action })
export const deleteBooking = (roomId, bookingUid) =>
    axios.delete(baseUrl(`/api/rooms/${roomId}/bookings/${bookingUid}`))

// Settings
export const getSettings = () => axios.get(baseUrl('/api/settings'))
export const saveSettings = (data) => axios.put(baseUrl('/api/settings'), data)

// Sharee search
export const searchSharees = (search) => axios.get(baseUrl('/api/sharees'), { params: { search } })

