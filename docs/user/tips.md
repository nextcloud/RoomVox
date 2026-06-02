# Tips

Time-savers and client-specific tricks for getting the most out of RoomVox.

## Use the Visual Room Browser (Nextcloud Calendar)

If your administrator has installed the [Calendar Patch](../features/calendar-patch.md), Nextcloud Calendar shows a full room browser instead of the minimal default picker:

- **Browse all rooms** grouped by building
- **Filter** by availability, capacity, building, and facilities
- **Search** by name, building, address, or floor
- **Real-time availability** badges (green/red/blue)

This is dramatically faster than typing a room name into the standard picker.

## Search by More Than Just Name

In the room browser, the search field matches against:

- Room name (e.g., "Boardroom")
- Building (e.g., "Heidelberglaan")
- Address
- Floor or room number (e.g., "2.17")

So you can search for "Heidelberglaan" to see all rooms in that building, or "3" to see all rooms on floor 3.

## Avoid CUTYPE Issues on iOS

iOS / macOS Calendar sends rooms with `CUTYPE=INDIVIDUAL` instead of `CUTYPE=ROOM`. **RoomVox auto-fixes this** — you don't need to do anything special. Your event will show the room correctly after a sync round-trip.

## Use LOCATION in eM Client

In eM Client, you can add a room two ways:

1. **As an attendee** — standard CalDAV resource pattern
2. **Set the LOCATION field to the room's name** — RoomVox matches the LOCATION against known room names and adds the room as an attendee for you

The LOCATION method is faster when you remember the room name but not where to find it in the attendee picker.

## Set Sensible Booking Horizons

If you find yourself frustrated by "Beyond booking horizon" rejections, talk to your administrator. The booking horizon (e.g., max 90 days) is a per-room setting that can be relaxed if the constraint isn't needed.

## Recurring Meetings

Booking a recurring meeting (e.g., weekly standup):

- Set the RRULE in your calendar app as usual
- RoomVox checks **every occurrence** against availability rules, conflicts, and the booking horizon
- If any occurrence fails, the entire series is declined — pick a different recurring pattern
- Always set an `UNTIL` or `COUNT` — infinite recurrences are auto-declined when a horizon is set

For "cancel one instance" workflows, see the [FAQ](faq.md#can-i-cancel-just-one-occurrence-of-a-recurring-booking).

## Cancelling Quickly

To free a room from your calendar app:

1. Open the event
2. Remove the room from the attendees list (or delete the event)
3. Save

RoomVox receives the CANCEL iTIP message and frees the slot.

## When You Need a Room You Can't Book

Check **Personal Settings → RoomVox → My Rooms**. Every visible room shows its **Responsible contact** — name, email, or instructions for who to ask. This is exactly the field your administrator filled in to make sure viewers know who to approach.

## Use API Tokens for Integrations

If you build digital signage, room displays, or check-in kiosks, ask your administrator for a Public API token. The API supports:

- Listing rooms with current status (Available / Reserved)
- Reading bookings for a room
- Creating bookings (with `book` scope)
- Statistics (with `admin` scope)
- iCal feeds

See [Public API](../features/public-api.md).

## See Also

- [Booking Rooms](booking-rooms.md) — per-client booking guides
- [FAQ](faq.md) — common questions
- [Calendar Patch](../features/calendar-patch.md) — visual room browser
