# PR Splitting Strategy

This document outlines how to split the patch into reviewable pull requests for `nextcloud/calendar`. Each PR is designed to be independently mergeable, with clear dependencies noted.

## Overview

```
PR 1: principal.js — Room metadata     (no dependencies)
PR 2: EditFull.vue — Modal layout       (no dependencies)
PR 3: ResourceList + RoomCard — Finder  (depends on PR 1)
PR 4: EditFull.vue — Hybrid meetings    (depends on PR 3)
```

---

## PR 1: Expose Room Metadata from CalDAV Properties

**Type:** Enhancement (non-breaking)
**Risk:** Low
**Files:** `src/models/principal.js`
**Depends on:** Nothing

### What

Add 8 room-specific properties to the principal model: `roomSeatingCapacity`, `roomType`, `roomAddress`, `roomFeatures`, `roomFloor`, `roomBuildingName`, `roomBuildingAddress`, `roomNumber`.

### Why

The CalDAV standard defines room properties (`room-seating-capacity`, `room-type`, `room-features`, `room-building-address`, `room-building-floor`, `room-building-room-number`) that are already served by Nextcloud's room backends. The Calendar frontend currently ignores them. Mapping these properties into the principal model makes them available for any UI improvement without changing how principals are fetched.

### Scope

- ~50 lines added to `principal.js`
- No template or style changes
- No new dependencies
- Backward compatible: properties default to `null` when not provided by the backend

### Conventional Commit

```
feat(principal): map CalDAV room metadata properties

Extend the principal model to extract room-seating-capacity,
room-type, room-features, room-building-address, room-building-floor,
and room-building-room-number from CalDAV principal responses.

These properties are defined in the CalDAV standard and already
served by Nextcloud room backends, but not yet used by the Calendar
frontend.

Signed-off-by: ...
```

---

## PR 2: Event Editor Modal Layout Improvements

**Type:** Enhancement (CSS-only)
**Risk:** Low–Medium
**Files:** `src/views/EditFull.vue` (styles only)
**Depends on:** Nothing

### What

Improve the full-page event editor modal:
- Auto-height with max-height instead of `height: 100%`
- Vertical centering via CSS transform
- Hide the `modal-header` (renders outside container causing width issues) and add an in-content title row
- Fix attendees overflow (`width: calc(100% - 53px)` + `box-sizing: border-box`)
- Align time pickers closer to date inputs (`flex-start` instead of `space-between`)
- Set content max-width to 960px with 24px padding

### Why

The current modal always fills the full viewport height, leaving excess whitespace for short events. The `modal-header` sits outside the `modal-container` on the `modal-mask` level, which means its width is viewport-based and overflows on narrow screens. The attendees section overflows by 53px due to a margin/width mismatch. Time pickers are unnecessarily spread apart.

### Scope

- Template: Add `h2` title row with actions menu (~15 lines)
- Styles: `:deep()` overrides for modal container, modal-header, modal-container__content, time pickers, attendees
- No JavaScript logic changes
- Responsive: graceful degradation at 600px, 785px, 900px, 1200px breakpoints

### Conventional Commit

```
fix(EditFull): improve modal layout and fix overflow issues

- Use height: auto with max-height instead of height: 100%
- Vertically center the modal with CSS transform
- Hide modal-header (rendered outside container causing width issues)
  and add in-content title row with actions menu
- Fix attendees horizontal overflow (width calc with box-sizing)
- Align time pickers closer to date inputs

Signed-off-by: ...
```

---

## PR 3: Visual Room Finder

**Type:** Feature
**Risk:** Medium
**Files:**
- `src/components/Editor/Resources/ResourceList.vue` (replacement)
- `src/components/Editor/Resources/ResourceRoomCard.vue` (new)
**Depends on:** PR 1 (room metadata in principal model)

### What

Replace the stock resource picker with a visual room finder that:
- Loads all room principals on mount
- Checks availability via free/busy queries
- Displays rooms as cards with status, capacity, floor
- Provides client-side filtering: text, building, floor, capacity, features
- Shows "Available/Unavailable" toggle, progressive pagination
- Auto-sets filters when a room is selected
- Updates event LOCATION with room address

### Why

The current resource picker requires users to search by name (must know it), shows max 3 results, and has only 3 hardcoded feature checkboxes. Organizations with many rooms need to browse, filter by building/floor, and see availability at a glance.

### Scope

- `ResourceList.vue`: Complete replacement (244 → 632 lines)
- `ResourceRoomCard.vue`: New component (202 lines)
- Uses only existing NC Vue components and services (`NcSelect`, `NcTextField`, `NcCheckboxRadioSwitch`, `NcButton`, `NcLoadingIcon`, `checkResourceAvailability`)
- `ResourceListSearch.vue` is no longer imported (can be removed in a follow-up cleanup)

### Testing Notes

- Requires a room backend (e.g., RoomVox or the built-in LDAP room backend) with rooms configured
- Free/busy availability requires rooms to have calendar proxies
- Dynamic filters only appear when rooms have the corresponding properties

### Conventional Commit

```
feat(resources): add visual room finder with filtering and availability

Replace the search-based resource picker with a browsable room finder.
All rooms are loaded on mount with free/busy availability checks.
Client-side filters for building, floor, capacity, and features are
dynamically populated from room metadata.

New component: ResourceRoomCard displays each room as a compact card
with availability status, capacity, and add/remove action.

Signed-off-by: ...
```

---

## PR 4: Hybrid Meeting Support (In-person + Online)

**Type:** Feature
**Risk:** Medium
**Files:** `src/views/EditFull.vue` (template + script + styles)
**Depends on:** PR 3 (room finder)

### What

Add In-person and Online (Talk) toggles to the event editor:
- **In-person toggle**: Shows/hides the room finder in a disclosure panel
- **Online toggle**: Creates a Talk room via the existing `AddTalkModal`; stores URL as RFC 7986 `CONFERENCE` property
- Both can be active for hybrid meetings
- Disclosure panels with expand/collapse and summary views
- Smart initialization: reads existing room attendees and CONFERENCE property

### Why

Hybrid meetings (in-person + online) are the standard in modern organizations. The current editor has no concept of meeting type — users manage room bookings and Talk links independently. Adding toggle switches with disclosure panels provides a clear, organized workflow for creating any type of meeting.

### Scope

- Template: ~80 lines of meeting options UI
- Script: ~150 lines for toggle handlers, Talk URL management, initialization
- Styles: ~80 lines for meeting-options, meeting-option, talk-room-info
- Uses RFC 7986 CONFERENCE property (standard) instead of legacy location/description embedding
- Cleans up legacy Talk URLs when removing online component

### Conventional Commit

```
feat(EditFull): add hybrid meeting support with In-person and Online toggles

Add In-person and Online (Talk) switches to the event editor.
In-person toggle controls the room finder disclosure panel.
Online toggle manages Talk room creation via CONFERENCE property (RFC 7986).

Both toggles can be active simultaneously for hybrid meetings.
Disclosure panels with expand/collapse keep the editor compact.

Signed-off-by: ...
```

---

## Submission Order

1. **PR 1** first — small, non-breaking, enables PR 3
2. **PR 2** second — independent CSS improvement, quick review
3. **PR 3** after PR 1 merges — the main feature
4. **PR 4** after PR 3 merges — builds on the room finder

Each PR should be accompanied by a GitHub issue (feature request) that describes the user-facing problem and links to the PR. Use the NC Calendar issue template.

## GitHub Issue Template

For each PR, create a matching issue:

```markdown
### Feature Description

[Brief description of the feature]

### Use Case

[Who benefits and why]

### Current Behavior

[What happens now]

### Expected Behavior

[What should happen]

### Screenshots

[If applicable]
```
