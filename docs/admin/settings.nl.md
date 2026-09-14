# Referentie beheerdersinstellingen

Referentie voor elke optie in **Instellingen → Beheer → RoomVox**.

![Instellingen — algemene opties, ruimte-types, API-tokens, telemetrie](../../screenshots/settings.png)

## Overzicht van de tabs

| Tab | Doel |
|---|---|
| Ruimtes | Ruimte-CRUD, permissies, ruimte-groepen |
| Boekingen | Goedkeuren/afwijzen, lijst-/agenda-weergave, verplaatsen met drag-and-drop |
| Import / Export | Bulk-CSV-operaties |
| Instellingen | App-brede standaardwaarden — ruimte-types, e-mail, telemetrie, API-tokens |
| Statistieken | Gebruiksdata |

## Algemene instellingen

### Standaard auto-accept

**Functie:** standaardwaarde voor de auto-accept-schakelaar van nieuwe ruimtes.

| Stand | Gedrag |
|---|---|
| ✅ Aangevinkt | Nieuwe ruimtes bevestigen boekingen automatisch wanneer er geen conflict is |
| ☐ Niet aangevinkt | Nieuwe ruimtes markeren boekingen als **Voorlopig**; een manager moet ze goedkeuren |

Elke ruimte kan dit in zijn eigen editor overschrijven.

**Opslag:** `appconfig`-sleutel `defaultAutoAccept` (`'true'` / `'false'`)

### E-mail-notificaties inschakelen

**Functie:** hoofdschakelaar voor alle e-mail-notificaties van RoomVox.

Wanneer uit, stuurt RoomVox geen bevestigings-, afwijzings-, goedkeurings- of annuleringsmails — ook niet als Nextcloud-SMTP geconfigureerd is. Handig tijdens onderhoud of de eerste inrichting.

**Opslag:** `appconfig`-sleutel `emailEnabled` (`'true'` / `'false'`)

### Weekenden tonen in agenda

**Functie:** bepaalt of weekenddagen zichtbaar zijn in de boekings-agenda-weergave.

Dit is uitsluitend een **weergave**-instelling — hij voorkomt geen boekingen in het weekend. Wil je boekingen beperken tot werkdagen, gebruik dan [beschikbaarheids-regels](../features/availability-rules.md) op individuele ruimtes.

## Ruimte-types

**Functie:** definieer de ruimte-types waaruit gebruikers kunnen kiezen bij het aanmaken van een ruimte.

Standaardwaarden (Engels):

- Meeting Room
- Rehearsal Room
- Studio
- Lecture Hall
- Telephone Booth
- Outdoor Area
- Other

Je kunt types toevoegen, bewerken, verwijderen of van volgorde wisselen via de sleep-handvatten. Types worden gepubliceerd als CalDAV-property (`{http://nextcloud.com/ns}room-type`), waardoor agenda-apps op type kunnen filteren.

**Opslag:** `appconfig`-sleutel `roomTypes` (JSON-array)

## API-tokens

**Functie:** Bearer-tokens voor de [Public API](../features/public-api.md).

![API-tokens — tokens beheren voor externe integraties](../../screenshots/api-tokens.png)

### Token-eigenschappen

| Veld | Omschrijving |
|---|---|
| Naam | Vrij tekstlabel (bijv. `Lobby Display`) |
| Scope | `read`, `book` of `admin` |
| Beperken tot ruimtes | Optioneel — beperk toegang tot specifieke ruimtes |
| Verloopt | Optionele vervaldatum |

### Scopes

| Scope | Staat toe |
|---|---|
| `read` | Ruimtes opsommen, boekingen lezen, beschikbaarheid lezen |
| `book` | Bovenstaande + boekingen aanmaken |
| `admin` | Bovenstaande + statistieken + admin-operaties |

Tokens worden **één keer** getoond, bij het aanmaken — kopieer ze meteen. Daarna wordt alleen de prefix (`rvx_xxxx...`) getoond.

Zie [Public API](../features/public-api.md) en [API-referentie](../architecture/api-reference.md#public-api-v1) voor details.

## Exchange-sync (optioneel)

Configureer Microsoft Graph-credentials voor bidirectionele Exchange-sync. Zie [Exchange-integratie](../architecture/exchange-integration.md) voor de volledige architectuur.

**Instellingen:**

| Veld | Omschrijving |
|---|---|
| Tenant ID | Microsoft Azure-tenant-ID |
| Client ID | Client-ID van de app-registratie |
| Client Secret | Secret van de app-registratie |
| Globaal ingeschakeld | Hoofdschakelaar voor Exchange-sync over alle ruimtes |
| Inline-sync-throttle | Minimale interval per ruimte tussen door webhooks getriggerde syncs |
| Inline-sync-rate-limit | Globale rate-limiet om overbelasting te voorkomen |

Het koppelen van Exchange per ruimte gebeurt in de ruimte-editor.

## Support-tab

### Abonnementssleutel (Enterprise)

Configureer je VoxCloud-abonnementssleutel voor Enterprise-activatie. De gratis tier is volledig uitgerust tot aan de volume-limiet (geen feature-gating).

### Telemetrie

| Schakelaar | Effect |
|---|---|
| Anonieme gebruiksstatistieken versturen | Schakelt dagelijkse telemetrie in/uit |
| Nu rapport versturen | Verstuur handmatig een rapport |

Zie [Telemetrie](telemetry.md) voor de volledige data-inventarisatie en instructies om het uit te schakelen.

## Per ruimte versus app-breed

| Instelling | Bereik |
|---|---|
| `defaultAutoAccept` | App-brede standaardwaarde; per ruimte te overschrijven |
| `emailEnabled` | App-breed; niet per ruimte te overschrijven |
| `roomTypes` | App-breed |
| `showWeekendsInCalendar` | App-brede weergave-voorkeur |
| `telemetry_enabled` | App-breed |
| API-tokens | App-breed, optioneel beperkt tot ruimtes |
| Exchange-sync | App-brede credentials + koppeling per ruimte |

Instellingen per ruimte (auto-accept, beschikbaarheids-regels, boekings-horizon, SMTP, Exchange-koppeling) staan in de ruimte-editor — zie [Ruimtes beheren](room-management.md).

## Waar instellingen worden opgeslagen

Alle RoomVox-instellingen staan in de `oc_appconfig`-tabel van Nextcloud (key-value, geen eigen tabellen). Inspecteren doe je met:

```bash
sudo -u www-data php occ config:app:get roomvox defaultAutoAccept
sudo -u www-data php occ config:app:get roomvox emailEnabled
sudo -u www-data php occ config:app:get roomvox roomTypes
sudo -u www-data php occ config:app:get roomvox rooms_index
sudo -u www-data php occ config:app:get roomvox room/<roomId>
```

Zie [Backend-architectuur](../architecture/backend-architecture.md) voor het volledige opslagmodel.

## Zie ook

- [Beheerdersgids](guide.md) — dagelijks beheer
- [Ruimtes beheren](room-management.md) — configuratie per ruimte
- [Public API](../features/public-api.md) — gebruik van API-tokens
- [Telemetrie](telemetry.md) — verzamelde data
