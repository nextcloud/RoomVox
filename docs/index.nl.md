# RoomVox documentatie

Welkom bij de RoomVox-documentatie. RoomVox is een CalDAV-native ruimtereserveringen-app voor Nextcloud waarmee gebruikers vergaderruimtes direct vanuit elke kalender-app kunnen boeken — Nextcloud Calendar, Apple Calendar, Outlook, Thunderbird, eM Client — zonder aparte boekingsinterface.

![Ruimte-overzicht](screenshots/rooms-overview.png)

*Ruimtes verschijnen als boekbare CalDAV-resources. Reserveren gebeurt in de kalender-app die gebruikers al kennen.*

## Snelle navigatie

### Voor gebruikers

Leer hoe je ruimtes boekt, reserveringen beheert en notificaties begrijpt.

- [Overzicht](user/overview.md) — Wat RoomVox is en hoe ruimtes als kalender-resources werken
- [Ruimtes boeken](user/booking-rooms.md) — Hoe je ruimtes boekt vanuit Nextcloud Calendar, Apple Calendar, Outlook, Thunderbird en eM Client
- [Boekingen beheren](user/managing-bookings.md) — Goedkeuren, afwijzen, verplaatsen en annuleren
- [Notificaties](user/notifications.md) — E-mailnotificaties uitgelegd
- [Persoonlijke instellingen](user/personal-settings.md) — My Rooms, Approvals en Bookings tabs
- [Tips](user/tips.md) — Client-specifieke tricks en tijdsbesparingen
- [Problemen oplossen](user/troubleshooting.md) — Boeking afgewezen, ruimte onzichtbaar, taalproblemen
- [FAQ](user/faq.md) — Veelgestelde gebruikersvragen

### Voor beheerders

Installatie, configuratie, ruimtebeheer en operations.

- [Beheergids](admin/guide.md) — Dagelijks beheer
- [Instellingen](admin/settings.md) — Referentie voor het admin-paneel
- [Ruimtebeheer](admin/room-management.md) — Ruimtes, typen, groepen, faciliteiten, beschikbaarheidsregels
- [Permissies](admin/permissions.md) — Viewer- / Booker- / Manager-rollen, ruimtegroep-overerving
- [E-mailconfiguratie](admin/email-configuration.md) — Nextcloud SMTP, per-ruimte SMTP, encryptie
- [Import / Export](admin/import-export.md) — CSV-import/export, MS365/Exchange-migratie
- [Telemetrie](admin/telemetry.md) — Anonieme gebruiksdata (opt-out)
- [Best practices](admin/best-practices.md) — Permissie-strategie, groep-hiërarchie, onderhoud
- [Problemen oplossen](admin/troubleshooting.md) — E-mailproblemen, calendar-patch-issues, debug-endpoints
- [FAQ](admin/faq.md) — Veelgestelde beheerdersvragen

### Features

Per-feature documentatie voor mogelijkheden.

- [Goedkeuringsworkflow](features/approval-workflow.md) — Tentative-boekingen, manager-goedkeuring, decline-redenen
- [Beschikbaarheidsregels](features/availability-rules.md) — Dag-/tijdsbeperkingen en boekingshorizon
- [E-mailnotificaties](features/email-notifications.md) — Alle 9 notificatietypen
- [Calendar-patch](features/calendar-patch.md) — Visuele ruimte-browser voor Nextcloud Calendar
- [Publieke API](features/public-api.md) — Bearer-token REST API voor displays, kiosks, integraties
- [Vergelijking vs. Calendar Resource Management](features/comparison.md) — Feature-vergelijking met de ingebouwde Nextcloud resource-app

### Voor architecten & ontwikkelaars

Technische documentatie voor integratie, evaluatie en bijdragen.

- [Architectuur-overzicht](architecture/overview.md) — Systeemontwerp en componenten
- [API-referentie](architecture/api-reference.md) — Interne + publieke v1 REST API-endpoints
- [Backend-architectuur](architecture/backend-architecture.md) — IAppConfig-opslag, virtuele gebruikers, servicelaag
- [CalDAV-scheduling](architecture/caldav-scheduling.md) — Sabre-plugin, iTIP-flow, PARTSTAT-handling
- [Exchange-integratie](architecture/exchange-integration.md) — Microsoft Graph-sync, webhooks, conflictdetectie
- [Nextcloud 34-compatibility](architecture/nc34-compatibility.md) — NC34-audit, 1.2.0 release-plan

### Deployment

Installatie, App Store-publicatie en releaseproces.

- [Installatie](deployment/installation.md) — Vereisten en installatie-gids
- [Releaseproces](deployment/release-process.md) — Versie-sync, build, GitHub-releases
- [App Store-publicatie](deployment/app-store-submission.md) — Certificaat, packaging, signing, uploaden

## Aan de slag

Nieuw bij RoomVox? Begin met de [Snelstart-gids](getting-started.md) en zet je eerste ruimte op in vijf minuten.

## Ondersteuning

- **Issues & feature requests**: [GitHub Issues](https://github.com/nextcloud/RoomVox/issues)
- **Broncode**: [GitHub-repository](https://github.com/nextcloud/RoomVox)

## Licentie

RoomVox is gelicentieerd onder de [AGPL-3.0-licentie](https://www.gnu.org/licenses/agpl-3.0.html).
