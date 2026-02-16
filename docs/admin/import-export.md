# Import / Export

RoomVox supports bulk room management via CSV files. You can export all rooms for backup or migration, and import rooms from CSV files — including files exported from Microsoft 365 / Exchange.

## Export

### Export All Rooms

1. Go to **Settings > Administration > RoomVox**
2. Click the **Import / Export** tab
3. Click **Export CSV**

![Import / Export tab — export and import rooms](../screenshots/export-rooms2.png)

A CSV file is downloaded with all rooms and 13 columns:

| Column | Example |
|--------|---------|
| `name` | Meeting Room 1 |
| `email` | room1@company.com |
| `capacity` | 12 |
| `roomNumber` | 2.17 |
| `roomType` | meeting-room |
| `building` | Building A |
| `street` | Heidelberglaan 8 |
| `postalCode` | 3584 CS |
| `city` | Utrecht |
| `facilities` | projector,whiteboard,video-conference |
| `description` | Large meeting room on 2nd floor |
| `autoAccept` | true |
| `active` | true |

This file can be:
- Imported into another RoomVox instance
- Edited in Excel or LibreOffice Calc
- Used as a backup

### Download Sample CSV

Click **Download sample CSV** to get a template file with headers and one example row. Use this as a starting point when creating rooms from scratch.

## Import

### Supported Formats

RoomVox automatically detects the CSV format:

**RoomVox format** — Files exported from RoomVox or created manually with the column names listed above.

**MS365/Exchange format** — Files exported via PowerShell:

```powershell
Get-EXOMailbox -RecipientTypeDetails RoomMailbox | Get-Place | Export-Csv -Path rooms.csv
```

MS365 columns are automatically mapped to RoomVox fields:

| MS365 Column | RoomVox Field |
|--------------|---------------|
| `DisplayName` | name |
| `PrimarySmtpAddress` / `EmailAddress` | email |
| `Capacity` / `ResourceCapacity` | capacity |
| `Floor` / `FloorLabel` | roomNumber |
| `Building` | building |
| `City` | city |
| `Tags` | facilities |
| `IsWheelchairAccessible` | wheelchair facility |

### Import Steps

1. Go to **Import / Export** tab
2. Drag and drop a CSV file, or click **Choose file**
3. RoomVox shows a **preview** with:
   - Detected format (RoomVox or MS365)
   - Number of rooms found
   - Per-row action: **New** (will create) or **Update** (matches existing room)
   - Any validation errors

![Import preview — detected format, per-row action, and validation](../screenshots/import-rooms.png)

4. Choose an import mode:
   - **Only create new rooms** — Skip rows that match existing rooms
   - **Create new + update existing** — Create new rooms and update existing ones
5. Click **Import**
6. Review the results: created, updated, skipped, and errors

![Import results — created, updated, and skipped counts](../screenshots/export-rooms2.png)

### Duplicate Detection

RoomVox detects duplicates by comparing:

1. **Email address** (primary match) — If the CSV email matches an existing room's email
2. **Room name** (secondary match) — If the CSV name matches an existing room's name

Matched rooms are shown as "Update" in the preview. In create-only mode, these rows are skipped.

### Validation

The import validates each row before processing:

- **Name** is required — rows without a name are rejected
- **Capacity** must be a number if provided
- **Email** must be a valid email address if provided
- **Duplicate names** within the CSV itself are flagged

### Facilities

The facilities column accepts comma-separated values. Known facilities are normalized automatically:

| Input | Normalized |
|-------|-----------|
| `projector`, `beamer` | projector |
| `whiteboard` | whiteboard |
| `video-conference`, `videoconf` | video-conference |
| `audio-system` | audio-system |
| `display-screen`, `screen` | display-screen |
| `wheelchair-accessible`, `wheelchair` | wheelchair-accessible |

### Delimiter Detection

RoomVox automatically detects the CSV delimiter. Comma (`,`), semicolon (`;`), and tab-separated files are all supported. This means files saved by Dutch or German versions of Excel (which use `;`) work without manual conversion.

### File Size Limit

Uploaded CSV files are limited to **5 MB**. For larger imports, split the file into multiple parts.

### Tips

- Export your current rooms first to see the exact format
- Edit the exported CSV in Excel/LibreOffice, then re-import with "create + update" mode
- For MS365 migrations, export the room list via PowerShell and import directly — no manual column mapping needed
- The import handles UTF-8 BOM (byte order mark) automatically

### Exported CSV in Excel

The exported CSV can be opened directly in Excel or LibreOffice Calc for editing.

![Exported CSV opened in a spreadsheet application](../screenshots/export-rooms.png)
