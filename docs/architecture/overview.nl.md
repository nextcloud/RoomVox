# Architectuur-overzicht

> **Let op:** dit is ontwikkelaarsdocumentatie en blijft Engelstalig. Klassenamen, interfaces en CalDAV-property's zijn Engels, en de architectuurbeschrijving loopt mee met de code — twee talen naast elkaar houden zou betekenen dat één ervan stil achterloopt. Deze pagina geeft een Nederlandse introductie op de opbouw van RoomVox; het volledige verhaal met diagram en property-tabellen staat in [de Engelse versie](overview.md).

## Waar dit over gaat

RoomVox is een ruimte-boekingssysteem voor Nextcloud dat **CalDAV-native** is. Dat is
de belangrijkste ontwerpkeuze en verklaart bijna alles wat daarna volgt: een ruimte is
geen rij in een eigen database maar een echte CalDAV-resource, en een boeking is een
gewone agenda-afspraak. Wie in Nextcloud Calendar, Apple Calendar, Outlook of
Thunderbird een ruimte uitnodigt voor een afspraak, boekt daarmee in RoomVox — zonder
dat er ook maar één RoomVox-scherm aan te pas komt.

Deze pagina is bedoeld voor ontwikkelaars die aan RoomVox werken of ertegenaan
bouwen. Wil je alleen ruimtes beheren, lees dan de [beheerdersgids](../admin/guide.md).

## De begrippen die je nodig hebt

Voor je het Engelse document induikt, is het handig om deze vier termen te kennen.

**iTIP** is het protocol waarmee agenda's uitnodigingen uitwisselen: een `REQUEST`
is een nieuwe of gewijzigde uitnodiging, een `CANCEL` een annulering. RoomVox
onderschept die berichten en beslist namens de ruimte.

**Sabre DAV** is de CalDAV-server die in Nextcloud zit. Plug-ins daarop krijgen een
prioriteit; wie een lager nummer heeft, komt eerder aan de beurt.

**PARTSTAT** is de deelnemerstatus in een agenda-afspraak: `ACCEPTED`, `TENTATIVE` of
`DECLINED`. Voor een ruimte betekent `TENTATIVE` in RoomVox: aangevraagd, wacht op
goedkeuring van een manager.

**Principal** is het CalDAV-adres van een deelnemer, bijvoorbeeld
`principals/users/rb_boardroom`. Zonder principal kan de agendaserver een ruimte niet
als deelnemer adresseren.

## De belangrijkste componenten

| Component | Rol |
|-----------|-----|
| **RoomBackend / Room** | publiceren ruimtes als CalDAV-resource, met capaciteit, type, adres en faciliteiten, zodat agenda-apps ze kunnen vinden |
| **SchedulingPlugin** | het hart van RoomVox: onderschept iTIP-berichten en beslist of een boeking wordt geaccepteerd, aangevraagd of geweigerd |
| **RoomUserBackend** | maakt per ruimte een verborgen, niet-inlogbaar `rb_*`-account zodat de ruimte een CalDAV-principal heeft |
| **RoomService** | CRUD op ruimtes en het beheer van die virtuele accounts |
| **PermissionService** | lost de rollen Viewer, Booker en Manager op, inclusief overerving via ruimtegroepen |
| **CalDAVService** | praat rechtstreeks met de agenda-backend: boekingen lezen en schrijven, conflicten detecteren |
| **MailService** | verstuurt bevestigingen en annuleringen, per ruimte via eigen SMTP of via Nextclouds mailer |
| **IAppConfig** | de opslag — RoomVox heeft geen eigen databasetabellen |

## Hoe een boeking verloopt

De SchedulingPlugin draait op **prioriteit 99**, vlak vóór Sabres eigen
scheduling-afhandeling op 100. Dat is geen detail: Sabres handler probeert de
ruimte-principal op te lossen via een actieve gebruikerssessie, en dat lukt niet bij
virtuele `rb_*`-accounts. RoomVox vangt het bericht dus eerst af, handelt de bezorging
zelf af en geeft `false` terug zodat Sabre het niet nog eens probeert.

Komt er een `REQUEST` binnen, dan loopt die langs deze controles, in deze volgorde:

1. **Permissie** — mag de afzender deze ruimte boeken? Zo niet: `DECLINE`.
2. **Beschikbaarheid** — valt de afspraak binnen de openingstijden van de ruimte?
3. **Boekingshorizon** — ligt hij niet verder vooruit dan is toegestaan?
4. **Conflict** — overlapt hij met een bestaande boeking? Zo ja: `DECLINE` plus een
   conflictmelding aan de organisator.
5. **Status bepalen** — staat auto-accept aan, dan `ACCEPTED`, anders `TENTATIVE`.
6. **Bezorgen** in de agenda van de ruimte, en een bericht naar de organisator of,
   bij `TENTATIVE`, naar de managers van de ruimte.

Een `CANCEL` is korter: verwijderen uit de ruimte-agenda, status bijwerken, en
organisator en managers informeren.

## Opslag: geen eigen tabellen

Alle configuratie staat in Nextclouds `IAppConfig`, onder sleutels als `rooms_index`,
`room/{roomId}` en `permissions/{roomId}`. De boekingen zelf staan waar ze horen: als
gewone CalDAV-afspraken in de agenda-backend van Nextcloud. RoomVox houdt daar geen
schaduwkopie van bij.

Dat scheelt migraties tussen releases en maakt installeren simpel. Het werkt omdat het
aantal ruimtes in de praktijk in de tientallen tot honderden loopt — bij die schaal is
key-value-opslag prima, en permissies en instellingen zijn van nature
document-vormig (JSON).

## Permissies

Drie rollen, oplopend: **Manager > Booker > Viewer**. Permissies staan op twee
niveaus — per ruimte en per ruimtegroep — en de effectieve permissie is de *vereniging*
van beide. Nextcloud-beheerders hebben altijd volledige toegang.

Eén subtiliteit die het waard is om te onthouden: groepsvermeldingen worden óók als
`group_restrictions` in de CalDAV-metadata gepubliceerd, waardoor Nextcloud Calendar
een ruimte alleen toont aan leden van die groepen. Individuele gebruikersvermeldingen
doen dat niet; die worden pas bij het boeken afgedwongen.

## Frontend

De beheerinterface is Vue 3 met de Composition API en de Nextcloud-componentbibliotheek
(`@nextcloud/vue`), gebouwd met Webpack. Hij hangt in Nextclouds instellingen-framework
op `/settings/admin/roomvox` en mount in een kale `<div id="app-roomvox">`, zonder
`NcContent`- of `NcAppContent`-wrappers. De schermen zijn `RoomList`, `RoomEditor`,
`PermissionEditor` en `BookingOverview`; alle API-aanroepen lopen via
`src/services/api.js`.

## Voor het volledige verhaal

Zie het [Engelse architectuur-overzicht](overview.md) voor:

- Het volledige componentendiagram
- De tabel met CalDAV-DAV-property's en hun `IRoomMetadata`-constanten
- De openstaande upstream-kwestie rond `room-building-name`
- De complete JSON-structuur van een ruimte
- De bootstrap-volgorde in `Application.php`

## Zie ook

- [Backend-architectuur](backend-architecture.md) — mappenindeling, services en opslag
- [CalDAV-scheduling](caldav-scheduling.md) — de SchedulingPlugin in detail
- [Exchange-integratie](exchange-integration.md) — synchronisatie met Microsoft Graph
- [API-referentie](api-reference.md) — de twee API's van RoomVox
