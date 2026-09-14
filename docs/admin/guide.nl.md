# Beheerdersgids

Deze gids beschrijft het dagelijks beheer van RoomVox. Voor installatie, zie de [Installatie-gids](../deployment/installation.md).

![Ruimte-overzicht — alle ruimtes georganiseerd per groep](../../screenshots/rooms-overview.png)

## Overzicht

RoomVox is een CalDAV-native ruimte-boekings-app. Ruimtes verschijnen als boekbare resources in elke agenda-app — Nextcloud Calendar, Apple Calendar, Outlook, Thunderbird, eM Client — zonder aparte boekings-interface.

Als beheerder bepaal je:

- **Ruimtes** — naam, capaciteit, type, adres, faciliteiten, beschikbaarheids-regels ([Ruimtes beheren](room-management.md))
- **Permissies** — wie elke ruimte mag bekijken, boeken en beheren ([Permissies](permissions.md))
- **E-mail** — Nextcloud-SMTP, SMTP per ruimte, afzender-adressen ([E-mail-configuratie](email-configuration.md))
- **Bulk-operaties** — CSV-import/export, MS365-migratie ([Import / Export](import-export.md))
- **Optionele integraties** — calendar-patch (visuele ruimte-browser), Exchange-sync, Public API voor displays/kiosken
- **Instellingen** — standaardwaarden, ruimte-types, e-mail-schakelaar, telemetrie ([Instellingen](settings.md))

## Het beheerpaneel openen

1. Klik op je **gebruikers-avatar** rechtsboven
2. Selecteer **Instellingen** (⚙️)
3. Scroll in de linker zijbalk omlaag naar **Beheer**
4. Klik op **RoomVox**

Het beheerpaneel heeft vijf tabs:

- **Ruimtes** — CRUD en permissies
- **Boekingen** — goedkeuren, afwijzen, verplaatsen, annuleren
- **Import / Export** — bulk-ruimte-beheer
- **Instellingen** — standaardwaarden, ruimte-types, e-mail, API-tokens, telemetrie
- **Statistieken** — gebruiksdata

## Dagelijkse taken

| Taak | Waar | Referentie |
|---|---|---|
| Een ruimte aanmaken | Ruimtes-tab → **+ Nieuwe ruimte** | [Ruimtes beheren](room-management.md#ruimtes-aanmaken) |
| Instellen wie een ruimte mag boeken | Ruimtes-tab → permissie-icoon | [Permissies](permissions.md) |
| Een openstaande boeking goedkeuren | Boekingen-tab → filter **In afwachting** | [Boekingen beheren](../user/managing-bookings.md#boekingen-goedkeuren) |
| Ruimte-types toevoegen/bewerken | Instellingen-tab → **Ruimte-types** | [Instellingen](settings.md) |
| Ruimtes in bulk importeren uit MS365 | Import / Export-tab → sleep CSV | [Import / Export](import-export.md) |
| Een API-token aanmaken | Instellingen-tab → **API-tokens** | [Public API](../features/public-api.md) |
| Telemetrie uitschakelen | Instellingen-tab → **Support** → zet uit | [Telemetrie](telemetry.md) |

## Boekingen en goedkeuring

Elke ruimte heeft een **auto-accept**-instelling:

- **Auto-accept aan** — boekingen worden direct bevestigd als er geen conflict is
- **Auto-accept uit** — boekingen komen binnen als **Voorlopig**; een manager moet ze goedkeuren

Openstaande boekingen verschijnen in de Boekingen-tab. Managers ontvangen een e-mail wanneer er een nieuwe openstaande boeking binnenkomt. Zie de feature-pagina [Goedkeurings-workflow](../features/approval-workflow.md) voor het volledige proces.

## Hoe effectieve permissies werken

Permissies worden op twee niveaus opgeslagen:

1. **Ruimte-niveau** — regels op een specifieke ruimte
2. **Ruimte-groep-niveau** — regels op een ruimte-groep, overgeërfd door alle ruimtes in die groep

Effectieve permissies zijn de **vereniging** van beide niveaus. Nextcloud-beheerders hebben altijd volledige toegang, ongeacht expliciete permissies.

Als een ruimte **geen permissies geconfigureerd** heeft (en er gelden geen groeps-permissies), dan mogen alle ingelogde gebruikers hem boeken — alleen Nextcloud-admins kunnen hem beheren. Zodra je ook maar één permissie configureert, hebben alleen de vermelde gebruikers/groepen toegang. Zie [Permissies](permissions.md).

## E-mail-notificaties

RoomVox stuurt negen soorten notificaties — boeking bevestigd, afgewezen, conflict, horizon overschreden, buiten beschikbaarheid, goedkeurings-verzoek, annulering en andere. Zie [E-mail-notificaties](../features/email-notifications.md) voor de volledige matrix.

Vereisten:

- **Nextcloud-SMTP** geconfigureerd in Instellingen → Beheer → Basisinstellingen → E-mailserver
- **E-mail-notificaties ingeschakeld** in de Instellingen-tab van RoomVox
- **Gebruikers hebben een e-mailadres** in hun Nextcloud-profiel
- **`occ config:app:set dav sendInvitations --value yes`** voor iMIP-uitnodigingen aan externe deelnemers

Zie [E-mail-configuratie](email-configuration.md) voor SMTP-details en het instellen van SMTP per ruimte.

## SMTP per ruimte

Elke ruimte kan zijn eigen SMTP-server hebben, zodat notificaties lijken te komen van de eigen mailbox van de ruimte. Wachtwoorden worden versleuteld via Nextclouds `ICrypto`. Zie [E-mail-configuratie → SMTP per ruimte](email-configuration.md#smtp-per-ruimte-optioneel).

## Optioneel: calendar-patch

De optionele [calendar-patch](../features/calendar-patch.md) vervangt de minimale resource-picker van Nextcloud Calendar door een volledige ruimte-browser — groeperen per gebouw, realtime beschikbaarheid, multi-filter-chips, zoeken op naam/adres/verdieping. Uitrollen doe je met `./deploy-calendar.sh <target>`.

## Optioneel: Public API

De [Public API](../features/public-api.md) biedt REST-endpoints met Bearer-token-authenticatie voor externe integraties (ruimte-displays, kiosken, digital signage). Drie scopes: `read`, `book`, `admin`. Tokens kunnen beperkt worden tot specifieke ruimtes.

## Volgende stappen

- [Instellingen-referentie](settings.md) — elke beheerdersoptie in detail
- [Ruimtes beheren](room-management.md) — volledige CRUD-doorloop
- [Permissies](permissions.md) — rollen, overerving, CalDAV-zichtbaarheid
- [Best practices](best-practices.md) — aanbevolen patronen
- [Troubleshooting](troubleshooting.md) — veelvoorkomende problemen
- [FAQ](faq.md) — veelgestelde vragen
