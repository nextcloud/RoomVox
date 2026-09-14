# Backend-architectuur

> **Let op:** dit is ontwikkelaarsdocumentatie en blijft Engelstalig. Klassenamen, configuratiesleutels en PHP-fragmenten zijn Engels, en dit document loopt één-op-één mee met de mappenstructuur van de code — een vertaling zou binnen een release achterlopen. Deze pagina geeft een Nederlandse introductie op het PHP-backend; de volledige beschrijving met mappenboom en codevoorbeelden staat in [de Engelse versie](backend-architecture.md).

## Waar dit over gaat

Deze pagina beschrijft hoe het PHP-deel van RoomVox is opgebouwd: waar data staat,
hoe ruimtes aan een CalDAV-identiteit komen, welke services er zijn en wat er bij het
opstarten van de app gebeurt. Het is bedoeld voor ontwikkelaars die code aanpassen of
een integratie bouwen die dieper gaat dan de API.

Voor het systeemoverzicht en het diagram, zie [Architectuur-overzicht](overview.md).
Voor de scheduling-plug-in specifiek, zie [CalDAV-scheduling](caldav-scheduling.md).

## Opbouw van `lib/`

Het backend volgt de gebruikelijke Nextcloud-indeling, met vier plekken die je moet
kennen:

| Map | Wat er staat |
|-----|--------------|
| `AppInfo/` | `Application.php` — bootstrap, DI-registratie, listeners |
| `Controller/` | de HTTP-laag: één controller per domein (ruimtes, boekingen, tokens, Exchange, instellingen, licentie, webhook) |
| `Service/` | alle logica; controllers doen zelf zo min mogelijk |
| `Connector/Room/`, `Dav/`, `UserBackend/` | de CalDAV-kant: ruimtes als resource, de scheduling-plug-in, en de virtuele accounts |

Daarnaast draait er in `BackgroundJob/` een handvol taken: Exchange-synchronisatie
elke 15 minuten, vernieuwing van Graph-webhook-abonnementen elke 12 uur, en het
telemetrie-rapport elke 24 uur.

## Opslag: alleen IAppConfig

RoomVox heeft **geen eigen databasetabellen**. Alle configuratie staat in
`oc_appconfig` onder de app `roomvox`, met sleutels als:

| Sleutel | Inhoud |
|---------|--------|
| `rooms_index` | JSON-array met alle ruimte-id's |
| `room/{roomId}` | de configuratie van één ruimte als JSON |
| `permissions/{roomId}` | de permissies van die ruimte |
| `room_groups_index`, `group/{groupId}`, `group_permissions/{groupId}` | hetzelfde voor ruimtegroepen |
| `roomTypes` | de gedefinieerde ruimtetypes |
| `api_tokens` | tokens, met alleen prefix en hash — nooit het ruwe token |

De boekingen zelf staan níet in RoomVox-opslag maar als gewone CalDAV-afspraken in
`oc_calendarobjects`. RoomVox houdt daar geen schaduwadministratie van bij; dat is een
bewuste keuze en de reden dat een boeking die je in Nextcloud Calendar aanpast meteen
klopt in RoomVox.

Waarom geen database? Geen migraties tussen releases, geen schema-onderhoud, en bij
tientallen tot honderden ruimtes is key-value-opslag ruim efficiënt genoeg. Permissies
en instellingen zijn bovendien van nature document-vormig.

Let op bij het lezen van opgeslagen ruimtes: SMTP-wachtwoorden en Exchange-secrets
staan versleuteld met `ICrypto`, en in API-antwoorden wordt een ingesteld wachtwoord
altijd vervangen door `"***"`.

## Virtuele gebruikersaccounts

Elke ruimte krijgt een verborgen Nextcloud-gebruiker met het voorvoegsel `rb_`,
bijvoorbeeld `rb_boardroom`. Die accounts komen uit `RoomUserBackend` en zijn
uitdrukkelijk geen echte gebruikers: inloggen lukt nooit (`checkPassword()` geeft
altijd `false`), ze zijn verborgen in zoekopdrachten en gebruikerslijsten, en hun
weergavenaam is die van de ruimte.

Ze bestaan om één reden: CalDAV-scheduling heeft een principal-URI
(`principals/users/<uid>`) nodig om een deelnemer te kunnen adresseren. Zonder
bijbehorende Nextcloud-gebruiker faalt `getPrincipalByUri()`. Het virtuele account
levert die principal zonder de bijwerkingen van een echt account — het telt niet mee
voor licenties en duikt niet op in gebruikerskiezers.

## De servicelaag

Controllers hangen aan services, services hangen aan elkaar. De belangrijkste:

| Service | Verantwoordelijkheid |
|---------|---------------------|
| `RoomService` | CRUD op ruimtes, beheer van de virtuele accounts, de ruimte-index |
| `PermissionService` | rollen oplossen (Viewer/Booker/Manager), inclusief overerving via groepen |
| `CalDAVService` | rechtstreekse toegang tot de agenda-backend: boekingen en conflictdetectie |
| `MailService` | per ruimte eigen SMTP, anders Nextclouds `IMailer` |
| `ImportExportService` | CSV in beide formaten (RoomVox en MS365), inclusief duplicaatdetectie |
| `ApiTokenService` | tokens aanmaken, hashen en scopes controleren |
| `TelemetryService`, `LicenseService` | anonieme gebruiksstatistieken en abonnementsvalidatie |
| `Exchange/*` | synchronisatie met Microsoft Graph |

### De cirkel tussen PermissionService en RoomService

Eén afhankelijkheid is het waard om vooraf te kennen, omdat hij er in de code raar
uitziet. `PermissionService` heeft `RoomService` nodig (om ruimtedata te lezen voor
groepsovererving), en `RoomService` heeft `PermissionService` nodig (voor
permissiebewuste queries). Via constructor-injectie zou dat een cirkel zijn.

De cirkel wordt doorbroken met **late injectie in `Application::boot()`**: daar wordt
`setRoomService()` op `PermissionService` aangeroepen. Pas je hier iets aan, kijk dan
naar de unit-tests — die controleren deze bedrading expliciet.

## Bootstrap

`lib/AppInfo/Application.php` draait in twee fasen.

**Registratie** (`register()`): `RoomBackend` aanmelden als CalDAV-ruimte-backend,
`SabrePluginListener` registreren zodat de SchedulingPlugin in de Sabre-server wordt
geïnjecteerd, en `ApiTokenMiddleware` aanhaken voor `/api/v1/*`.

**Boot** (`boot()`): `RoomUserBackend` aanmelden bij de gebruikersmanager, de late
injectie hierboven uitvoeren, en controleren dat opgeslagen Exchange-credentials
ontsleutelbaar zijn.

## De twee API-lagen

In `appinfo/routes.php` staan twee lagen naast elkaar. De **Internal API**
(`/api/*`) is sessie-geauthenticeerd en bedient de Vue-beheerinterface. De **Public
API v1** (`/api/v1/*`) loopt via `ApiTokenMiddleware` en verwacht een Bearer-token met
een scope. Zie de [API-referentie](api-reference.md) voor het verschil in
authenticatie, foutvormen en antwoordstructuur.

## Voor het volledige verhaal

Zie de [Engelse backend-architectuur](backend-architecture.md) voor:

- De complete mappenboom van `lib/`, met een regel uitleg per bestand
- Alle IAppConfig-sleutels, inclusief de Exchange-instellingen
- De volledige JSON-structuur van een opgeslagen ruimte
- Het codefragment van de late injectie
- Het permissiemodel met JSON-voorbeeld en de CalDAV-zichtbaarheidsregel
- De beveiligingsnotities (CSRF, sanitization, encryptie, PartialMatch-logging)

## Zie ook

- [Architectuur-overzicht](overview.md) — diagram en componentenkaart
- [CalDAV-scheduling](caldav-scheduling.md) — de SchedulingPlugin in detail
- [Exchange-integratie](exchange-integration.md) — synchronisatie met Microsoft Graph
- [Telemetrie](../admin/telemetry.md) — wat er precies wordt verstuurd
