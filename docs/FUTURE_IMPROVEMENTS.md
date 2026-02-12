# RoomVox - Toekomstige Verbeteringen

Dit document bevat alle geplande verbeteringen voor RoomVox room booking systeem.
Deze features zijn geanalyseerd maar nog niet geïmplementeerd.

---

## 1. Booking Regels & Validatie

### 1.1 Minimum/Maximum Booking Duur
**Complexiteit:** Medium | **Impact:** Hoog

Voeg `minDuration` en `maxDuration` velden toe aan room configuratie.
- Valideer in SchedulingPlugin
- Weiger bookings korter dan X minuten of langer dan Y uur
- UI: sliders in RoomEditor

```php
'bookingRules' => [
    'minDuration' => 15,    // minuten
    'maxDuration' => 480,   // 8 uur max
]
```

### 1.2 Buffer Tijd Tussen Boekingen
**Complexiteit:** Medium | **Impact:** Hoog

Voeg `bufferMinutes` toe voor schoonmaak/setup tijd.
- Check conflicts met buffer padding
- Voorbeeld: 15 min voor en na elke event

```php
'bufferMinutes' => 15,
```

### 1.3 Vooraankondiging Vereisten
**Complexiteit:** Laag | **Impact:** Medium

- `minAdvanceHours`: minimaal X uur vooruit boeken
- `maxAdvanceDays`: maximaal X dagen vooruit boeken

```php
'minAdvanceHours' => 24,
'maxAdvanceDays' => 90,
```

### 1.4 Capaciteit Afdwingen
**Complexiteit:** Medium | **Impact:** Hoog

- Track aantal attendees in booking
- Waarschuw of weiger als capacity overschreden
- Manager override mogelijk

```php
'enforceCapacity' => true,
```

### 1.5 Blackout Dates / Feestdagen
**Complexiteit:** Medium | **Impact:** Hoog

- Array van uitgesloten datums
- Support voor date ranges
- Calendar picker in admin UI

```php
'blackoutDates' => [
    '2024-12-25',
    '2024-12-26',
    ['start' => '2024-08-01', 'end' => '2024-08-15', 'reason' => 'Renovatie']
]
```

---

## 2. Approval & Workflow

### 2.1 Conditionele Auto-Accept
**Complexiteit:** Medium | **Impact:** Hoog

Regels voor automatische goedkeuring:

```javascript
"autoAcceptRules": [
    { "condition": "attendees <= 5", "action": "auto_accept" },
    { "condition": "group IN ['Management']", "action": "auto_accept" },
    { "condition": "duration > 4h", "action": "require_approval" }
]
```

### 2.2 Extra Permissie Levels
**Complexiteit:** Laag | **Impact:** Medium

Naast viewer/booker/manager:
- **Approver**: kan approve/decline maar geen room config
- **Analyst**: kan bookings en reports zien maar niet booken
- **Scheduler**: kan namens anderen booken

### 2.3 Approval met Voorwaarden
**Complexiteit:** Hoog | **Impact:** Medium

- Approve met condities: "Goedgekeurd mits max 10 deelnemers"
- Approve met wijziging: "Alleen 2 uur ipv 3 uur"
- Suggereer alternatieve tijden

### 2.4 Multi-Step Goedkeuring
**Complexiteit:** Hoog | **Impact:** Laag

- Meerdere approvers vereist
- Sequentieel of parallel
- Escalatie na X uur zonder actie

---

## 3. Notificaties & Email

### 3.1 Aanpasbare Email Templates
**Complexiteit:** Laag | **Impact:** Hoog

Admin UI voor templates met variabelen:
- `{{organizerName}}`
- `{{roomName}}`
- `{{date}}`, `{{startTime}}`, `{{endTime}}`
- `{{roomLocation}}`
- `{{customMessage}}`

### 3.2 Conditionele Notificaties
**Complexiteit:** Medium | **Impact:** Medium

- Skip email voor auto-accepted bookings
- Alleen notifyen bij pending approval
- Daily digest optie

### 3.3 Meeting Herinneringen
**Complexiteit:** Hoog | **Impact:** Hoog

- Email X uur voor meeting
- Configurable per room: 24h, 1h, 15min
- Optioneel: SMS via Twilio/Nexmo

### 3.4 iTIP Reply naar Organizer
**Complexiteit:** Medium | **Impact:** Medium

- Stuur CalDAV REPLY terug naar organizer
- Update organizer's event met room PARTSTAT

---

## 4. Rapportage & Analytics

### 4.1 Bezettingsgraad Dashboard
**Complexiteit:** Medium | **Impact:** Hoog

- Utilization rate: booked hours / available hours
- Piekuren visualisatie
- Gemiddelde booking duur
- Cancellation rate

### 4.2 No-Show Tracking
**Complexiteit:** Medium | **Impact:** Hoog

- Markeer bookings als no-show na event tijd
- Track no-show rate per user
- Waarschuw users met hoge no-show rate
- Optioneel: beperk toekomstige bookings

### 4.3 Gebruiker Booking Limieten
**Complexiteit:** Medium | **Impact:** Medium

- `maxConcurrentBookings`: max X simultaan
- `maxMonthlyHours`: maandelijks quota
- `dailyBookingLimit`: max per dag
- Uitzonderingen voor VIP users

### 4.4 Export naar CSV/PDF
**Complexiteit:** Laag | **Impact:** Medium

- Download booking overzicht als CSV
- PDF rapport voor management
- Datum range selectie

---

## 5. Room Configuratie

### 5.1 Room Categorieën & Tags
**Complexiteit:** Laag | **Impact:** Medium

```javascript
{
    "category": "meeting",  // meeting | training | call-room | lounge
    "tags": ["video", "quiet", "accessible", "premium"]
}
```

Filter UI: `[Category ▼] [Tags ▼] [Capacity ▼]`

### 5.2 Room Vergelijk View
**Complexiteit:** Laag | **Impact:** Medium

- Side-by-side vergelijking
- Features, capaciteit, beschikbaarheid

### 5.3 Room Ratings/Reviews
**Complexiteit:** Medium | **Impact:** Laag

- 1-5 sterren na booking
- Comments/reviews
- Issue reporting (projector kapot, etc.)

### 5.4 Amenity Reservering
**Complexiteit:** Medium | **Impact:** Medium

- Gedeelde resources (1 projector voor 3 rooms)
- Track amenity beschikbaarheid apart
- Request specifieke amenities bij booking

---

## 6. Integraties

### 6.1 Public Calendar Feed
**Complexiteit:** Laag | **Impact:** Medium

- Read-only iCal feed per room
- Zonder gevoelige organizer details
- Deelbaar met externe partijen

### 6.2 REST API voor Externe Systemen
**Complexiteit:** Medium | **Impact:** Hoog

- Direct booking creation via REST
- API tokens voor integraties
- Webhooks voor booking events
- Slack/Teams notificaties

### 6.3 LDAP/SAML Group Sync
**Complexiteit:** Hoog | **Impact:** Medium

- Sync room permissions van LDAP groups
- Auto-update bij login
- Departmental booking rules

---

## 7. Technische Verbeteringen

### 7.1 Timezone Fix (BUG)
**File:** CalDAVService.php:524
**Complexiteit:** Laag | **Impact:** Medium

Huidige bug: hardcoded "Europe/Amsterdam"

```php
// Fix: gebruik room timezone
$timezone = $room['timezone'] ?? 'Europe/Amsterdam';
```

### 7.2 RRULE Parsing Verbeteren
**Complexiteit:** Hoog | **Impact:** Medium

- Gebruik RFC 5545 compliant library
- Handle alle RRULE features (BYSETPOS, BYHOUR, etc.)
- Betere recurring event expansion

### 7.3 Conflict Detection Optimalisatie
**Complexiteit:** Medium | **Impact:** Medium

- Database indexing voor snellere queries
- Caching voor grote calendars
- Lazy loading van calendar objects

### 7.4 Email Retry Queue
**Complexiteit:** Laag | **Impact:** Laag

- Queue failed emails voor retry
- Background job voor email delivery
- Fallback na X retries

---

## Implementatie Volgorde (Aanbevolen)

### Fase 1: Quick Wins
1. Timezone fix
2. Room tags/categorieën
3. Email template variabelen

### Fase 2: Booking Regels
4. Min/max duur
5. Buffer tijd
6. Advance notice
7. Capacity enforcement

### Fase 3: FullCalendar (ACTIEF)
8. Resource timeline view
9. Drag-drop
10. Click-to-create

### Fase 4: Analytics
11. Bezettingsgraad
12. No-show tracking
13. CSV export

### Fase 5: Geavanceerd
14. Conditional auto-accept
15. Blackout dates
16. Herinneringen

---

*Laatst bijgewerkt: 2026-02-11*
