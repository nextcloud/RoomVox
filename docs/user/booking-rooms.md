# Booking Rooms

RoomVox makes rooms available as standard CalDAV resources. This means you can book rooms directly from any calendar app that supports CalDAV resources — no separate booking interface needed.

## How It Works

When an administrator creates a room in RoomVox, it becomes available as a CalDAV resource. When you add this resource to a calendar event, RoomVox automatically:

1. Checks your **permission** to book the room
2. Verifies the room is **available** at the requested time
3. Checks against **availability rules** (allowed days/times)
4. Checks the **booking horizon** (maximum advance booking period)
5. Either **auto-accepts** the booking or marks it as **tentative** (pending manager approval)
6. Sends **email notifications** to the organizer and managers

## Booking from Nextcloud Calendar

### Standard Resource Picker

1. Create a new event or edit an existing one
2. In the event editor, find the **Resources** section
3. Start typing the room name in the search field
4. Select the room from the results
5. The room shows capacity and address in the dropdown
6. Save the event

### Visual Room Browser (Calendar Patch)

If your administrator has installed the [calendar patch](../admin/calendar-patch.md), you'll see an enhanced room browser instead of the standard picker:

- **Browse all rooms** grouped by building
- **Filter** by availability, capacity, building, and facilities
- **Search** by name, building, address, or floor
- **Room cards** show status (available/unavailable), capacity, and floor
- Click **+** to add a room, **-** to remove it

## Booking from Apple Calendar

Apple Calendar (macOS and iOS) supports CalDAV resources natively.

### macOS

1. Create a new event
2. Click **Add Location, Video Call, or Travel Time**
3. In the attendees section, search for the room name
4. Select the room resource
5. Save the event

### iOS

1. Create a new event
2. Tap **Invitees**
3. Search for the room name
4. Add the room
5. Save the event

> **Note:** iOS sends room attendees with `CUTYPE=INDIVIDUAL` instead of `CUTYPE=ROOM`. RoomVox automatically detects and fixes this.

## Booking from Microsoft Outlook

Outlook supports CalDAV resources when connected via a CalDAV account.

1. Create a new meeting
2. In the scheduling assistant, add the room as an attendee
3. The room will respond with its availability
4. Send the meeting request

## Booking from Thunderbird

Thunderbird supports CalDAV resources through its calendar integration.

1. Create a new event
2. Add the room as an attendee in the attendee list
3. The room name should match the CalDAV resource name
4. Save the event

## Booking from eM Client

eM Client has special handling for rooms:

1. Create a new event
2. Set the **Location** field to the room name
3. RoomVox detects the room by location match and automatically adds it as an attendee
4. Save the event

> **Note:** eM Client may not send a room attendee explicitly. RoomVox's scheduling plugin detects the room by matching the LOCATION field against known room names.

## Booking Responses

After you add a room to your event, the room will respond with one of these statuses:

| Status | Meaning |
|--------|---------|
| **Accepted** | Room is booked and confirmed |
| **Tentative** | Booking requires manager approval (pending) |
| **Declined** | Booking was rejected |

### Why a Booking May Be Declined

- **Scheduling conflict** — another event is already booked at that time
- **No permission** — you don't have Booker or Manager role for the room
- **Outside availability** — the requested time is outside the room's available hours
- **Beyond booking horizon** — the event is too far in the future
- **Delivery error** — a server-side issue prevented the booking

## Conflict Detection

RoomVox automatically checks for scheduling conflicts:

- If a room is already booked at the requested time, the booking is declined
- Cancelled and declined bookings are ignored during conflict checking
- When rescheduling, the existing booking is excluded from the conflict check

## Recurring Events

RoomVox supports recurring events with some considerations:

- **Availability rules** apply to every occurrence
- **Booking horizon** is checked against the furthest occurrence (based on RRULE UNTIL or COUNT)
- **Infinite recurring events** (no UNTIL or COUNT) are always declined when a booking horizon is set
- **Conflict checking** applies to the initial booking; individual occurrence conflicts may need to be resolved manually
