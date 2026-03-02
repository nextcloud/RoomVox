# Improved Room Booking & Event Editor for Nextcloud Calendar

**RFC / Feature Proposal for nextcloud/calendar**

## Summary

This proposal introduces three improvements to the Nextcloud Calendar event editor:

1. **Visual Room Finder** — Replace the minimal resource picker with a browsable, filterable room list with real-time availability
2. **Hybrid Meeting Support** — Add In-person and Online (Talk) toggles with disclosure panels for managing physical and virtual meeting spaces in a single event
3. **Event Editor Modal Layout** — Fix modal sizing, add vertical centering, improve content width utilization, and bring the actions menu inline with the title

These changes are fully CalDAV-native, use only standard Nextcloud Vue components, and maintain backward compatibility.

## Problem Statement

The current Calendar app (v6.2.0) resource picker has significant usability limitations for organizations managing conference rooms:

### 1. No Room Browsing
Users must **know a room name** to search for it. There is no way to browse all available rooms, see what's open, or discover rooms by building, floor, or features.

### 2. Limited Search Results
The resource picker shows a **maximum of 3 suggestions** based on a capacity-weighted query. In organizations with dozens of rooms, this is insufficient — users often cannot find the right room without multiple searches.

### 3. Hardcoded Filters
Only three room features are available as filter checkboxes: Projector, Whiteboard, and Wheelchair accessible. These are hardcoded in `ResourceListSearch.vue`. Organizations with different room attributes (video conferencing, hybrid setup, standing desks, etc.) cannot filter on them.

### 4. No Availability Overview
Room availability is only shown in search results after performing a DAV roundtrip. There is no way to see at a glance which rooms are free for a given time slot.

### 5. No Hybrid Meeting Concept
Modern meetings are frequently hybrid (in-person + online). The current editor has no first-class concept for this — users must manually manage the Talk room link and physical location separately.

### 6. Modal Layout Issues
The full-page event editor modal (`EditFull.vue`) uses `height: 100%` which forces it to fill the entire viewport, leaving excess whitespace at the bottom for short events. The `modal-header` renders outside the `modal-container` (on the `modal-mask` level), causing width calculation issues on narrow viewports.

## Proposed Solution

### Visual Room Finder

Replace `ResourceList.vue` with a comprehensive room browser that loads all room principals on mount and checks their availability via free/busy queries.

**Features:**
- **All rooms visible** — Every room is displayed as a compact card, no search required
- **Real-time availability** — Each room shows Available (green), Unavailable (red), or Reserved (blue) status
- **Client-side filtering** — Text search across name, building, address, floor; no DAV roundtrip per keystroke
- **Dynamic filter dropdowns** — Building, floor, capacity (2+/4+/8+/12+/20+/50+), and features — all populated from actual room data, not hardcoded
- **"Show unavailable" toggle** — Unavailable rooms are hidden by default, toggle to show them (dimmed)
- **Progressive loading** — Shows 8 rooms initially, "Show N more" button for pagination
- **Auto-filter on selection** — When a room is added, filters auto-set to match its building/floor/capacity/features
- **Auto-collapse** — Room finder panel collapses after selection, shows selected room summary

| Feature | Current (v6.2.0) | Proposed |
|---------|-------------------|----------|
| All rooms visible | No (search or max 3) | Yes, cards with pagination |
| Availability status | After DAV search only | Per room, on load |
| Text search | Name only via DAV | Client-side on name, building, address, floor |
| Available-only filter | Checkbox in search | Toggle that filters entire list |
| Min. capacity filter | In search form | Dropdown with presets |
| Building filter | No | Dynamic dropdown |
| Floor filter | No | Dynamic dropdown |
| Facility filter | 3 hardcoded checkboxes | Dynamic multi-select from room data |
| Room display | Name in dropdown | Card with status, capacity, floor, add/remove |

### Room Card Component

Each room is displayed as a `ResourceRoomCard` — a compact, information-dense card:

- **Room name** (bold, truncated with ellipsis)
- **Status badge**: Available / Unavailable / Reserved with color coding
- **Capacity**: e.g. "120p"
- **Location**: Room number or floor
- **Room type**: If not a standard meeting room (e.g. "Lecture Hall")
- **Full address on hover** (tooltip via `title` attribute)
- **Add/remove button** (+/−) — only shown when actionable

Visual states:
- **Added**: Blue left border + light blue background
- **Unavailable**: Dimmed (opacity 0.55), no add button

### Hybrid Meeting Support

Add two toggle switches to the event editor under the Location field:

**In-person toggle:**
- Enables the Room Finder disclosure panel
- When toggled off: releases all booked rooms, clears location
- Collapsed state shows selected room summary
- Clicking summary expands the panel

**Online (Talk) toggle:**
- Opens the existing `AddTalkModal` to create/select a Talk room
- Stores the Talk URL as an RFC 7986 `CONFERENCE` property on the event (not in the description or location fields, which was the legacy behavior)
- Collapsed state shows Talk room name and URL
- "Change" button to swap the Talk room
- When toggled off: removes CONFERENCE property and cleans up any legacy Talk URLs from location/description

Both toggles can be active simultaneously for hybrid meetings. The UI uses disclosure panels (expand/collapse) to keep the editor compact.

### Event Editor Modal Layout

Improvements to the `EditFull.vue` modal container:

- **Auto-height**: `height: auto` with `max-height: calc(100vh - 100px)` instead of `height: 100%` — modal fits its content, no excess whitespace
- **Vertical centering**: `top: 50%; transform: translateY(-50%)` — centered regardless of content height
- **Fixed width**: `1000px` (capped at `95vw`) with `960px` content area and `24px` padding
- **Hidden modal-header**: The NcModal `modal-header` renders as a sibling of `modal-wrapper` on the `modal-mask` level (outside the container). This causes width issues. We hide it and render a custom title row inside the content area
- **Title row with actions**: `h2` title + three-dot actions menu (Export, Duplicate, Delete) on the same row
- **Time picker alignment**: `flex-start` instead of `space-between` to keep time inputs close to date inputs
- **Attendees width fix**: `width: calc(100% - 53px)` with `box-sizing: border-box` to prevent horizontal overflow
- **Responsive**: Two-column layout collapses to single column below 900px; padding reduces below 1200px

## Room Metadata via CalDAV Properties

The room finder reads metadata from standard CalDAV properties already exposed by room backends:

| Property | CalDAV Property | Usage |
|----------|----------------|-------|
| `roomSeatingCapacity` | `{urn:ietf:params:xml:ns:caldav}room-seating-capacity` | Capacity filter + display |
| `roomType` | `{urn:ietf:params:xml:ns:caldav}room-type` | Room type label |
| `roomFeatures` | `{urn:ietf:params:xml:ns:caldav}room-features` | Feature filter chips |
| `roomBuildingAddress` | `{urn:ietf:params:xml:ns:caldav}room-building-address` | Address tooltip |
| `roomBuildingName` | Derived from `roomBuildingAddress` | Building grouping + filter |
| `roomFloor` | `{urn:ietf:params:xml:ns:caldav}room-building-floor` | Floor filter + display |
| `roomNumber` | `{urn:ietf:params:xml:ns:caldav}room-building-room-number` | Room number display |
| `roomAddress` | Constructed from above | Location field auto-fill |

These are **standard CalDAV resource properties** — any CalDAV room backend that populates them will work with the room finder. No proprietary extensions are introduced.

## Backward Compatibility

- **No breaking changes** to existing functionality — the resource picker is replaced, not removed
- **Graceful degradation** — if no room backend is registered, the room finder shows "No rooms available"
- **`resource_booking_enabled` guard** — respects the NC33 state flag that hides the resource picker when no backend is active
- **NC33 compatible** — Calendar v6.2.0 ships with NC33; all `@nextcloud/vue` components used are available in v8.x
- **Scoped styles** — all CSS changes use Vue scoped styles or `:deep()`, no global side effects
- **CONFERENCE property** — uses RFC 7986 standard, not a custom property

## Implementation Notes

- Uses only existing Nextcloud Vue components: `NcModal`, `NcButton`, `NcSelect`, `NcTextField`, `NcCheckboxRadioSwitch`, `NcLoadingIcon`, `NcActions`, `NcActionButton`, `NcActionLink`
- Availability checking uses the existing `checkResourceAvailability()` from `freeBusyService.js`
- Room principals loaded via the existing `principalsStore.getRoomPrincipals`
- No new API endpoints or backend changes required
- All strings use `$t('calendar', ...)` for i18n

## Screenshots

> Screenshots of the room finder and event editor are available in the project repository under `screenshots/bookroom-*.png` (room finder states) and can be included in the GitHub issue/PR.

---

*This proposal is based on a working implementation tested against Nextcloud 28 and 33 with the Nextcloud Calendar v6.2.0.*
