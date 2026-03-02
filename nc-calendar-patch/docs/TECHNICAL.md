# Technical Change Documentation

Detailed technical documentation of all changes introduced by this patch to the Nextcloud Calendar app v6.2.0.

## Files Modified

| File | Type | Summary |
|------|------|---------|
| `src/models/principal.js` | Modified | Extract 8 room metadata properties from CalDAV principals |
| `src/views/EditFull.vue` | Modified | Modal layout, title row, hybrid meeting UI, time picker alignment |
| `src/components/Editor/Resources/ResourceList.vue` | Replaced | Full room finder with filtering and availability |
| `src/components/Editor/Resources/ResourceRoomCard.vue` | New | Room card component with status, capacity, add/remove |
| `css/global.scss` | Unchanged | No modifications to global styles |

## `principal.js` — Room Metadata Extraction

### Changes

Added 8 new properties to `getDefaultPrincipalObject()`:

```js
roomSeatingCapacity: null,
roomType: null,
roomAddress: null,
roomFeatures: null,
roomFloor: null,
roomBuildingName: null,
roomBuildingAddress: null,
roomNumber: null,
```

### Mapping Logic in `mapDavToPrincipal()`

Each property is extracted from the DAV principal object with defensive trimming:

```js
const roomSeatingCapacity = (dav.roomSeatingCapacity ?? '').toString().trim() || null
const roomType = (dav.roomType ?? '').toString().trim() || null
const roomFeatures = (dav.roomFeatures ?? '').toString().trim() || null
```

**Building address cleaning:**
```js
// Strip leading/trailing commas: ", Science Park 140, 1098 XG, Amsterdam" → "Science Park 140, 1098 XG, Amsterdam"
const roomBuildingAddress = rawBuildingAddress
  ? rawBuildingAddress.replace(/^[\s,]+|[\s,]+$/g, '').trim() || null
  : null
```

**Building name derivation:**
```js
// First segment before comma: "SURF Amsterdam, Science Park 140, ..." → "SURF Amsterdam"
const roomBuildingName = roomBuildingAddress
  ? roomBuildingAddress.split(',')[0].trim()
  : null
```

**Floor extraction with fallback:**
```js
// Explicit value from DAV, or extracted from room number (e.g. "2.17" → "2")
const rawFloor = (dav.roomBuildingFloor ?? '').toString().trim() || null
const roomFloor = rawFloor || (roomNumber
  ? (roomNumber.match(/^([A-Za-z]?\d+)/) || [])[1] || null
  : null)
```

**Smart address construction for LOCATION field:**
```js
// Format: "Street (Building, Room X.XX)" — street-first for map/navigation apps
if (commaIdx > 0) {
  const building = roomBuildingAddress.substring(0, commaIdx).trim()
  const street = roomBuildingAddress.substring(commaIdx + 1).trim()
  const detail = roomNumber ? building + ', Room ' + roomNumber : building
  roomAddress = street + ' (' + detail + ')'
}
```

### DAV Property Mapping

| JS Property | DAV Property | CalDAV Namespace |
|-------------|-------------|------------------|
| `roomSeatingCapacity` | `roomSeatingCapacity` | `{urn:ietf:params:xml:ns:caldav}` |
| `roomType` | `roomType` | `{urn:ietf:params:xml:ns:caldav}` |
| `roomFeatures` | `roomFeatures` | `{urn:ietf:params:xml:ns:caldav}` |
| `roomBuildingAddress` | `roomBuildingAddress` | `{urn:ietf:params:xml:ns:caldav}` |
| `roomBuildingName` | Derived from `roomBuildingAddress` | — |
| `roomFloor` | `roomBuildingFloor` | `{urn:ietf:params:xml:ns:caldav}` |
| `roomNumber` | `roomBuildingRoomNumber` | `{urn:ietf:params:xml:ns:caldav}` |
| `roomAddress` | Constructed | — |

---

## `ResourceList.vue` — Visual Room Finder

### Overview

Complete replacement of the stock `ResourceList.vue` (244 lines → 632 lines). The stock version renders a `ResourceListSearch` component with a search form that queries the DAV backend. The replacement loads all room principals on mount and provides client-side filtering.

### Component Structure

```
ResourceList
├── Filters (grid layout)
│   ├── Text search (NcTextField)
│   ├── Building dropdown (NcSelect)
│   ├── Capacity dropdown (NcSelect)
│   ├── Floor dropdown (NcSelect)
│   └── Features multi-select (NcSelect)
├── Loading indicator (NcLoadingIcon)
└── Results
    ├── Header ("Suggested conference rooms" + Show unavailable toggle)
    ├── Room cards (ResourceRoomCard v-for)
    ├── Show more button
    └── Empty state
```

### Data Flow

1. **Mount**: `loadAllRooms()` reads `principalsStore.getRoomPrincipals`, sets all rooms as available
2. **Availability check**: `loadAvailability()` calls `checkResourceAvailability()` from `freeBusyService.js` with all room emails
3. **Reactive update**: Uses `Vue.set()` to update individual room availability (Vue 2 reactivity caveat)
4. **Filtering**: Computed properties chain: `allRooms` → `filteredRooms` → `sortedRooms` → `visibleRooms`
5. **Date watching**: `calendarObjectInstance.startDate` and `endDate` changes trigger debounced availability reload (500ms)

### Filter Logic

Rooms that are **already added to the event** always pass all filters (they stay visible regardless).

```
filteredRooms = allRooms.filter(room =>
  (isAdded || showUnavailable || room.isAvailable) &&
  textMatch(filterText, room.displayname, roomAddress, roomBuildingAddress, roomBuildingName, roomNumber) &&
  buildingMatch(selectedBuilding, room.roomBuildingName) &&
  capacityMatch(selectedCapacity, room.roomSeatingCapacity) &&
  floorMatch(selectedFloor, room.roomFloor) &&
  featuresMatch(selectedFeatures, room.roomFeatures)
)
```

### Sort Order

1. Added rooms first (currently booked for this event)
2. Available rooms before unavailable
3. Alphabetical by display name

### Add Room Behavior

When a room is added:
1. `calendarObjectInstanceStore.addAttendee()` with `calendarUserType: 'ROOM'`
2. Build location string: `"Room Name, Building Address, Room X.XX"`
3. Update event LOCATION via `calendarObjectInstanceStore.changeLocation()`
4. Auto-set filters to match the selected room's properties
5. Emit `add-room` event (parent collapses the finder panel)

### Remove Room Behavior

1. Find attendee by email match
2. `calendarObjectInstanceStore.removeAttendee()`
3. Clear LOCATION field
4. Reset all filters

### Dynamic Filter Options

All filter dropdown options are computed from actual room data:

- **Buildings**: `Set` of unique `roomBuildingName` values, sorted alphabetically
- **Floors**: `Set` of unique `roomFloor` values, sorted with `numeric: true` locale compare
- **Capacity**: Static presets `[2, 4, 8, 12, 20, 50]`
- **Features**: `Set` of unique features from all rooms' comma-separated `roomFeatures`, formatted via `formatFacility()`

---

## `ResourceRoomCard.vue` — Room Card Component

### Overview

New component (202 lines). Compact card showing room info with add/remove action.

### Props

| Prop | Type | Description |
|------|------|-------------|
| `room` | Object | Room principal with availability data |
| `isAdded` | Boolean | Whether this room is already in the event |
| `isReadOnly` | Boolean | Read-only mode |
| `isViewedByOrganizer` | Boolean | Whether viewer is the event organizer |
| `hasRoomSelected` | Boolean | Whether any room is selected (disables add on other rooms) |

### Display Logic

- **Status label**: "Reserved" (added), "Available" (free), "Unavailable" (busy)
- **Status color**: Blue (reserved), green (available), red (unavailable)
- **Sub-location**: Prefers `roomNumber` (e.g. "2.12"), falls back to `roomFloor`
- **Room type**: Hidden for standard `meeting-room`, formatted via `formatRoomType()` for others
- **Add button**: Only shown when `isViewedByOrganizer && !isReadOnly && (isAdded || (isAvailable && !hasRoomSelected))`

### CSS States

- `.room-card--added`: Blue left border (`3px solid var(--color-primary)`) + light blue background
- `.room-card--unavailable`: `opacity: 0.55` (only when not added)

---

## `EditFull.vue` — Modal Layout & Hybrid Meeting UI

### Modal Container CSS (`:deep()` overrides)

```scss
:deep() {
  .modal-wrapper--full > .modal-container {
    --header-height: 50px !important;
    width: 1000px !important;
    max-width: 95vw !important;
    height: auto !important;
    max-height: calc(100vh - 100px) !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    overflow: hidden !important;
    display: flex !important;
    flex-direction: column !important;
  }

  .modal-header {
    display: none !important;
  }

  .modal-container__content {
    flex: 1 1 auto !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    min-height: 0 !important;
  }
}
```

**Why `modal-header` is hidden:** NcModal renders the header as a sibling of `modal-wrapper` on the `modal-mask` level. Since it sits outside the modal container, its width is based on the viewport (not the modal), causing horizontal overflow on narrow screens. We hide it and render a custom title row inside the content area.

### Title Row

```html
<div class="app-full__modal-title-row">
  <h2 class="app-full__modal-title">{{ modalTitle }}</h2>
  <div class="app-full__actions">
    <NcActions>
      <!-- Export, Duplicate, Delete actions -->
    </NcActions>
  </div>
</div>
```

`modalTitle` computed property: "New event" for new events, event title for existing events, "Event" as fallback.

### Content Area

```scss
.app-full {
  max-width: 960px;
  padding: 12px 24px;
  margin: 0 auto;
  box-sizing: border-box;
}
```

### Time Picker Alignment

```scss
:deep(.property-title-time-picker__time-pickers-from-inner),
:deep(.property-title-time-picker__time-pickers-to-inner) {
  justify-content: flex-start !important;
  gap: 16px !important;
}

:deep([class*="time-pickers-from-inner__selector"]),
:deep([class*="time-pickers-to-inner__selector"]) {
  flex: 0 1 auto !important;
  width: auto !important;
}
```

**Why:** The stock time picker uses `flex: 0 1 558px` on selector containers, pushing them far apart via `space-between`. Setting `flex: 0 1 auto` and `justify-content: flex-start` keeps time inputs close to their corresponding date inputs.

### Attendees Width Fix

```scss
.app-full-attendees {
  width: calc(100% - 53px);
  margin-inline-start: 53px;
  box-sizing: border-box;
}
```

**Why:** The attendees section uses `margin-inline-start: 53px` but has default `width: 100%`, causing it to overflow by 53px. The explicit width calculation prevents horizontal scroll.

### Hybrid Meeting UI

**Template structure:**
```
meeting-options
├── In-person
│   ├── Header: MapMarker icon + toggle switch + chevron
│   ├── Summary: selected room name (collapsed)
│   └── Panel: ResourceList (expanded)
└── Online (Talk)
    ├── Header: Video icon + toggle switch + chevron
    ├── Summary: Talk room name (collapsed)
    └── Panel: Talk room info + Change button (expanded)
```

**Data properties added:**
```js
isInPerson: false,      // In-person toggle state
isOnline: false,        // Online toggle state
isRoomFinderExpanded: true,   // Room finder disclosure state (set to false for existing events by watcher)
isTalkPanelExpanded: true,    // Talk panel disclosure state (set to true for existing events by watcher)
conferenceUrl: '',      // Cached CONFERENCE property URI
talkRoomDisplayName: '', // Resolved Talk room name
isModalOpen: false,     // AddTalkModal visibility
```

**Initialization (watcher on `calendarObjectInstance`):**
- Reads CONFERENCE property from event component
- Sets `isInPerson` based on existing room attendees
- Sets `isOnline` based on Talk URL in CONFERENCE/location/description
- Resolves Talk room display name via `listRooms()` API
- Runs once via `_togglesInitialized` flag

**Talk URL storage:** Uses RFC 7986 CONFERENCE property (clean) instead of embedding in location or description (legacy behavior). Cleans up legacy URLs when toggling off.

### Two-Column Body Layout

```scss
.app-full-body {
  display: flex;
  gap: 24px;
  justify-content: flex-start;
  flex-wrap: nowrap;

  &__left { flex: 1 1 auto; min-width: 0; }
  &__right { flex: 0 0 auto; width: 320px; }
}
```

Left column: Location, meeting options, description, alarms, attachments.
Right column: Status, visibility, categories, color, time transparency.

Collapses to single column at `max-width: 900px`.

### Modal Backdrop

```scss
.modal-mask {
  height: calc(100vh - var(--header-height));
  top: var(--header-height);
  background-color: rgba(0, 0, 0, 0.3) !important;
  backdrop-filter: blur(2px);
}
```

Semi-transparent backdrop showing the calendar behind the modal, similar to Outlook Web App.

---

## CSS Strategy

All styles use **Vue scoped styles** with `:deep()` for child component overrides. No modifications to `css/global.scss`. This prevents any unintended side effects on other Nextcloud apps or the Calendar app's non-editor views.
