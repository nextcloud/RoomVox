# Future Ideas

Ideas for potential future RoomVox features. These are not planned or committed — just captured for consideration.

## Room photo

Allow administrators to upload a photo of a room. This gives bookers a visual impression of the space before booking — size, layout, available equipment, and overall atmosphere. Photos are shown in the room browser and room detail view.

## Lunch ordering

Integrate lunch or catering ordering into the booking flow. When booking a room, the organizer can optionally add a lunch or catering order for the meeting. This could connect to an external catering system or provide a simple order form with predefined options.

## Room layout selection

Allow rooms to have multiple layout configurations (e.g., theatre, boardroom, U-shape, classroom). When booking, the organizer can select the desired layout. Room managers or facility staff receive the layout preference so the room can be prepared accordingly.

## Expected attendee count

Let the organizer specify the expected number of attendees when booking. This differs from room capacity (a property of the room) — attendee count is per booking. Useful for catering planning, layout preparation, and ensuring the room isn't oversized or undersized for the meeting.

## Cost center allocation

Allow a cost center or cost code to be attached to a booking for internal billing. Organizations with departmental budgets can track and charge room usage back to the responsible department. Could integrate with existing financial systems via the API.

## Booking attachments

Allow organizers to upload files (agenda, floor plan, presentation) when creating a booking. Attachments are stored in Nextcloud and linked to the booking event. Room managers and facility staff can access them to prepare the room.

## Visitor registration

Register expected visitors as part of a room booking. The organizer enters visitor names and optionally their company. This generates a visitor list that reception or front desk staff can use for check-in. Could integrate with Nextcloud's notification system to alert reception when a meeting with visitors is about to start.

## Parking reservation

Allow the organizer to reserve parking spaces alongside a room booking. Useful for locations with limited parking where visitors or external attendees need a guaranteed spot. Parking spaces are managed as a separate resource type with their own availability.

## No-show detection and tracking

Track bookings where the room was reserved but never used. This can be based on manual confirmation (organizer marks attendance) or integration with occupancy sensors. No-show data helps identify underutilized rooms and can be used to enforce policies like automatic cancellation of unconfirmed bookings.

## Room display integration

Support for room displays (tablets or screens mounted outside meeting rooms) that show the current and upcoming bookings. The display shows room status (available/occupied), current meeting details, and allows walk-in bookings. Communicates with RoomVox via the existing public API.

## Occupancy maps

Provide a visual floor plan or map view showing real-time room availability. Rooms are displayed on an uploaded floor plan image with color-coded status indicators (available, occupied, upcoming). Users can click a room on the map to book it directly.

## Recurring booking templates

Allow users to save frequently used booking configurations as templates (e.g., "Weekly team standup — Room A, Monday 09:00–09:30, 8 people, U-shape layout"). Templates speed up the booking process for repetitive meetings.

## Key and access management

Track physical keys or access cards associated with rooms. When a booking is confirmed, the system can notify reception to prepare the key or automatically grant temporary access card permissions. Useful for rooms that are normally locked or require special access.
