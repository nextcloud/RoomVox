# Nextcloud 34-compatibiliteitsaudit

> **Let op:** dit is ontwikkelaarsdocumentatie en blijft Engelstalig. Deze pagina geeft een Nederlandse introductie op de NC34-audit; voor de volledige tabellen met verwijderde API's, call sites en verificatiestappen zie [de Engelse versie](nc34-compatibility.md).

## Dit is een momentopname, geen actuele stand van zaken

Lees dit eerst, want het is het belangrijkste aan deze pagina: het Engelse document is een **historisch audit-verslag**. Het is uitgevoerd op **18 mei 2026** tegen Nextcloud 34 RC1, drie weken voor de GA-release van NC34, en het beschrijft de situatie van RoomVox 1.1.0 op dat moment.

Dat betekent concreet:

- De regel `<nextcloud min-version="32" max-version="34"/>` in dat document is een **aanbeveling uit mei 2026**, geen beschrijving van vandaag. RoomVox ondersteunt inmiddels **Nextcloud 32 tot en met 35**; de actuele waarde staat in `appinfo/info.xml`.
- De sectie "NC35 forward-compatibility" noemt dat `stable35` nog niet bestond. Dat is achterhaald: er is inmiddels een volwaardige NC35-audit gedraaid, en de verhoogde bovengrens is uitgebracht in RoomVox 1.5.0.
- **De actuele audit is [nc35-compatibility.md](nc35-compatibility.md).** Daarvan bestaat geen Nederlandse versie; die is alleen Engelstalig beschikbaar.

Bewaar dit document dus als achtergrond bij de overgang naar NC34, niet als antwoord op de vraag welke Nextcloud-versies RoomVox draait.

## Waar een compatibiliteitsaudit voor dient

Nextcloud verwijdert bij elke major release een stapel al lang verouderde API's. Een app die zo'n verwijderde methode gebruikt, faalt daar niet netjes op — je krijgt een fatale `Call to undefined method`, vaak pas op een pad dat zelden gelopen wordt. Een audit gaat daarom vóór de release na: welke API's verdwijnen er, gebruiken wij ze, en zo ja, wat kost het om ervan af te komen.

De audit is bedoeld voor ontwikkelaars die aan RoomVox werken en voor iedereen die moet beoordelen of een upgrade van de Nextcloud-server risico's voor de app oplevert.

## Wat er in NC34 verdween

De grote schoonmaak in NC34 bestond uit vier categorieën:

- **Legacy getters op `\OC::$server`** — methodes als `getUserManager()`, `getConfig()` en `getRootFolder()` zijn weg. Wat blijft is de generieke PSR-11-aanroep `\OC::$server->get(Class::class)`.
- **Oude klassen en helpers** — `OC_JSON`, delen van `OC_Util`, de oude Share-backend-interfaces, een aantal CSP-helpers.
- **Frontend-erfgoed** — jQuery, jQuery UI, Backbone, Handlebars en de oude `OC.*`-JavaScript-helpers zijn niet langer gebundeld.
- **Diverse `Util::*`-methodes** die al jaren als deprecated gemarkeerd stonden.

## De conclusie van de audit

RoomVox bleek in essentie al klaar voor NC34. Geen enkele verwijderde API kwam voor in de broncode:

- Er staan maar een paar aanroepen op `\OC::$server` in de code, en die gebruiken allemaal de PSR-11-vorm `get()`, die blijft bestaan.
- Het gebruikte OCP-oppervlak is stabiel. De belangrijkste interfaces voor RoomVox — `OCP\Calendar\Room\IBackend`, `IManager`, `IRoom` en `IRoomMetadata` — zijn zelfs byte-voor-byte identiek tussen `stable33` en `stable34`.
- De frontend gebruikt geen jQuery, Backbone of Handlebars; die kwamen alleen nog voor als geminificeerd residu in gebouwde bundles, niet in `src/`.
- De afhankelijkheden klopten al: PHP 8.2+, `@nextcloud/vue` 9 en `@nextcloud/dialogs` 7.

De enige verplichte wijziging was dan ook administratief: de `max-version` in `appinfo/info.xml` ophogen, de versie bumpen en een CHANGELOG-regel schrijven. Er hoefde geen regel functionele code om.

## Een nuance over service locators

De audit signaleerde twee plekken in `MailService` waar een dependency via `\OC::$server->get()` wordt opgehaald in plaats van via de constructor. Dat werkt prima op NC34 — het is immers de PSR-11-vorm — maar het is een service-locator-patroon waar constructor-injectie de gangbare Nextcloud-conventie is. Het stond in de audit als optionele opschoning, niet als blokkade.

## Hoe je de samenvattingstabel leest

Het Engelse document sluit af met een tabel waarin elk aspect een vinkje of een waarschuwingsteken krijgt. Dat onderscheid is opzettelijk en de moeite van het begrijpen waard:

- Een **vinkje** betekent: gecontroleerd en niets aan de hand, er is geen actie nodig. Dat gold voor alle verwijderde PHP- en JavaScript-API's, voor de calendar-interfaces en voor de afhankelijkheden.
- Een **waarschuwingsteken** betekent níet dat er iets kapot is. Het markeert werk dat nog gedaan moest worden of dat in een apart traject thuishoorde — de versiebump in `appinfo/info.xml`, de optionele opschoning in `MailService`, en de calendar-patch die aan een andere releasecyclus hangt dan de app zelf.

Er stond in de hele audit geen enkel blokkerend item. Dat is de kern van de conclusie.

## Wat er bewust buiten scope bleef

De audit benoemde ook wat er níet gedaan werd. NC34 introduceerde een paar nieuwe API's die op zichzelf aantrekkelijk zijn, maar die niet bestaan op NC32 en NC33. Ze overnemen zou betekenen dat de ondergrens van RoomVox naar 34 moet, waarmee twee nog breed gebruikte versies uit de ondersteuning vallen. Voor het beperkte voordeel was dat de prijs niet waard — een afweging die nog steeds geldt.

De patch op de Nextcloud Calendar-app viel eveneens buiten de app-release: die is aan een specifieke Calendar-versie gekoppeld en volgt daarmee een eigen ritme.

## Upstream CalDAV-wijzigingen

NC34 bracht een aantal wijzigingen in de eigen iTIP-afhandeling van Nextcloud mee: een refactor van de iTipBroker, null-veilige `ORGANIZER`-afhandeling, en een `x-nc-scheduling`-vlag die nu ook bij verwijderen wordt gerespecteerd.

Geen daarvan is blokkerend, en dat heeft een structurele reden: RoomVox onderschept iTIP-berichten voor ruimtes op prioriteit 99, dus vóór Sabre's eigen handler. Wijzigingen in die handler raken RoomVox grotendeels niet. Sommige ervan zijn juist interessant als toekomstige optimalisatie — bijvoorbeeld zoeken op event-URI, wat het opzoeken van een boeking op UID sneller zou kunnen maken.

## Verder lezen

De [Engelse versie van deze pagina](nc34-compatibility.md) bevat de releasetijdlijn van NC34, de volledige tabellen met verwijderde API's en hun impact, de lijst met call sites per bestand, de verificatiestappen en de samenvattingstabel.

Wil je weten waar RoomVox vandaag staat, lees dan de nieuwere audit: **[NC35-compatibiliteit](nc35-compatibility.md)** (alleen in het Engels).

## Zie ook

- [NC35-compatibiliteitsaudit](nc35-compatibility.md) — de actuele audit, Engelstalig
- [Backend-architectuur](backend-architecture.md) — servicelaag en opslag
- [CalDAV-scheduling](caldav-scheduling.md) — waarom prioriteit 99 ons afschermt van upstream-wijzigingen
- [Architectuuroverzicht](overview.md) — systeemcontext
