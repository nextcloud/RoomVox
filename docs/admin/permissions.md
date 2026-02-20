# Permissions

RoomVox uses a role-based permission system to control who can view, book, and manage rooms.

## Roles

There are three roles, each inheriting the capabilities of the previous:

| Role | Can View | Can Book | Can Manage |
|------|----------|----------|------------|
| Viewer | Yes | No | No |
| Booker | Yes | Yes | No |
| Manager | Yes | Yes | Yes |

### Viewer

- Can see the room in calendar apps (via CalDAV resource listing)
- Cannot create bookings

### Booker

- Can see the room in calendar apps
- Can create bookings (add room to events)
- Can cancel their own bookings

### Manager

- Can see the room in calendar apps
- Can create bookings
- Can approve or decline pending bookings
- Can cancel any booking for the room
- Can edit room settings and permissions
- Receives email notifications for new pending bookings

## Permission Entries

Permissions can be assigned to individual users or Nextcloud groups.

### User Permissions

Assign a role directly to a specific Nextcloud user:

```
{
  "type": "user",
  "id": "alice"
}
```

### Group Permissions

Assign a role to an entire Nextcloud group — all members of the group inherit the permission:

```
{
  "type": "group",
  "id": "developers"
}
```

## Setting Permissions

### Room-Level Permissions

1. In the room list, click the **permissions icon** for the room
2. The permission editor opens with three sections: Viewers, Bookers, Managers
3. Search for users or groups to add
4. Click **Save**

### Group-Level Permissions

Permissions set on a room group are inherited by all rooms in that group.

1. In the room groups section, click the **permissions icon** for the group
2. Add viewers, bookers, and managers using the search fields
3. Click **Save Permissions**

![Group-level permission editor — assign viewers, bookers, and managers to a room group](../screenshots/rooms-permissions.png)

### How Inheritance Works

A room's effective permissions are the **union** of:
- Its own room-level permissions
- The permissions of its assigned room group (if any)

**Example:**

```
Room Group "Building A":
  - bookers: [group: "staff"]

Room "Meeting Room 1" (in Building A):
  - managers: [user: "bob"]
  - bookers: [user: "alice"]

Effective permissions for "Meeting Room 1":
  - managers: [user: "bob"]
  - bookers: [user: "alice", group: "staff"]  ← merged
```

## Default Permissions

If no permissions are configured for a room (and no group permissions apply):

- **All authenticated users** can view and book the room
- Only **Nextcloud administrators** can manage it

Once any permission is configured, only the specified users/groups have access.

## Nextcloud Admin Bypass

Users in the Nextcloud **admin** group always have full access to all rooms, regardless of permission settings. They can:

- View all rooms
- Book any room
- Manage any room (approve/decline, edit, delete)

## CalDAV Visibility

Permissions also control which rooms are visible in calendar apps:

- **Group entries** in permissions are used as CalDAV `group_restrictions`
- Nextcloud Calendar only shows rooms to users who belong to at least one of the restricted groups
- **User entries** are enforced at booking time by the scheduling plugin, not at the CalDAV visibility level

This means:
- A user added as an individual Booker may need to search for the room by name rather than browsing
- A group added as Booker will see the room appear automatically in the resource list

## Permission Checks in Practice

### Viewing Rooms in Admin Panel

The admin panel shows rooms filtered by the user's effective permissions. Non-admin users only see rooms where they have at least Viewer access.

### Booking via CalDAV

When a user adds a room to a calendar event:

1. The scheduling plugin resolves the sender's email/principal to a Nextcloud user ID
2. It checks the user's `canBook()` permission for the room
3. If the user lacks permission, the booking is declined with status `3.7`

### Managing Bookings

The admin panel's booking overview shows bookings across all rooms the user has Manager access to. Approve/decline actions require Manager role.

## Best Practices

1. **Use groups** for common access patterns — easier to maintain than individual user permissions
2. **Use room groups** for buildings or departments — set shared permissions once
3. **Assign at least one manager** per room for approval workflows
4. **Keep Viewer permissions broad** — let users see room availability even if they can't book
5. **Review permissions periodically** — remove departing users and update group memberships
