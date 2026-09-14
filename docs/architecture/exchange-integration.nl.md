# Exchange-integratie

> **Let op:** dit is ontwikkelaarsdocumentatie en blijft Engelstalig. Deze pagina introduceert de Exchange-koppeling in het Nederlands; voor de API-tabellen, de webhook-payloads en de precieze configuratiesleutels zie [de Engelse versie](exchange-integration.md).

## Waar dit over gaat

Veel organisaties draaien Nextcloud naast een bestaande Microsoft 365-omgeving. De vergaderruimtes staan daar al als *room mailbox* in Exchange, en collega's boeken ze via Outlook. RoomVox kan die ruimtes overnemen zonder dat je ze uit Exchange hoeft te halen: de boekingen worden via de **Microsoft Graph**-API opgehaald en als gewone CalDAV-events in Nextcloud gezet.

Deze pagina is bedoeld voor ontwikkelaars en voor beheerders die willen begrijpen wat er onder de motorkap gebeurt. Zoek je de schermen en velden waarmee je de koppeling instelt, kijk dan bij [Instellingen → Exchange Sync](../admin/settings.md#exchange-sync-optional).

## De componenten

De koppeling bestaat uit een handvol onderdelen die je in de code terugvindt:

- **`GraphApiClient`** — praat met Microsoft Graph, beheert het OAuth2-token en vernieuwt dat ongeveer vijf minuten voor het verloopt.
- **`ExchangeSyncService`** — haalt de events op, vergelijkt ze met wat er lokaal staat, en maakt, wijzigt of verwijdert boekingen.
- **`WebhookService`** — maakt en vernieuwt de Graph-abonnementen die Microsoft gebruikt om wijzigingen te melden.
- **`CalDAVService`** — schrijft het resultaat naar Nextclouds eigen agenda-opslag.

Daaromheen draaien vier achtergrondjobs: een periodieke sync elke 15 minuten, een eenmalige job voor de eerste synchronisatie van een nieuwe ruimte, een job per binnengekomen webhook, en een vernieuwingsjob die elke 12 uur de abonnementen bijwerkt.

## Authenticatie en koppeling per ruimte

RoomVox authenticeert zich één keer voor de hele app, met **OAuth2 client credentials** uit een Azure AD-app-registratie: een tenant-ID, een client-ID en een client secret. Dat secret wordt versleuteld opgeslagen via `ICrypto`; de tokens zelf blijven in het geheugen en worden nooit weggeschreven.

Die ene set credentials volstaat voor de verbinding, maar niet voor de ruimtes. Elke ruimte koppel je afzonderlijk door het e-mailadres van de ruimte op het adres van de Exchange-mailbox te zetten. RoomVox merkt die wijziging op, zet de ruimte op `initialSyncStatus = 'pending'` en zet een eerste sync in de wachtrij. De beheerinterface pollt de ruimte elke vijf seconden zodat je de voortgang live ziet, en stopt vanzelf zodra de sync klaar is of faalt.

## Twee sync-modi

**Full sync** haalt alles op tussen 30 dagen terug en 365 dagen vooruit en brengt de lokale agenda volledig in lijn met Exchange. Die modus draait bij de eerste koppeling van een ruimte, bij een handmatige nieuwe poging na een mislukte eerste sync, en bij debuggen.

**Delta sync** gebruikt Graph's *delta query* en haalt alleen op wat er sinds de vorige ronde veranderd is. Dit is de normale modus voor het dagelijkse werk en draait elke 15 minuten.

Beide modi bouwen een **sync index** op: een afbeelding van Exchange-event-ID's naar CalDAV-URI's, zodat dezelfde afspraak in beide systemen aan elkaar te knopen is.

Bij het overnemen kijkt RoomVox naar de `showAs`-property van de Exchange-afspraak. Alleen `busy`, `tentative` en `oof` leveren een boeking op die de ruimte daadwerkelijk blokkeert. Afspraken die als `free` of `workingElsewhere` in de agenda staan worden wel gesynchroniseerd, maar veroorzaken geen conflict.

## Webhooks voor bijna-realtime updates

Wachten op de kwartiersync is voor veel situaties te traag. Daarom maakt RoomVox per gekoppelde ruimte een webhook-abonnement bij Microsoft Graph aan. Verandert er iets in Exchange, dan stuurt Microsoft een melding naar het webhook-endpoint van RoomVox.

De controller valideert eerst de validation token die Microsoft meestuurt bij het aanmaken van het abonnement, controleert daarna bij elke melding de handtekening, zoekt op basis van het subscription-ID de bijbehorende ruimte op, en zet vervolgens een sync-job in de wachtrij.

Er is een alternatief: **inline sync**. Dan draait de synchronisatie direct binnen het HTTP-verzoek in plaats van in een achtergrondjob, wat de latency verder verlaagt. Dat is bewust ingekaderd, want een reeks snelle wijzigingen in Exchange kan anders de request-thread verzadigen. Er geldt een minimuminterval per ruimte (standaard 30 seconden) en een globale limiet op het aantal inline syncs per minuut. Komt er een webhook binnen binnen dat venster, dan gaat hij alsnog als achtergrondjob de wachtrij in.

Graph-abonnementen verlopen na drie dagen. De vernieuwingsjob draait elke 12 uur en vernieuwt met een veiligheidsmarge van minstens 36 uur. Mislukt het vernieuwen — bijvoorbeeld omdat het secret geroteerd is — dan wordt het abonnement opnieuw aangemaakt.

## Bescherming tijdens de eerste sync

Zolang de eerste synchronisatie van een ruimte loopt (`pending` of `syncing`), weigert de `SchedulingPlugin` nieuwe boekingen voor die ruimte met schedule status `5.3` — een *tijdelijke* fout. Dat is geen pesterij maar bescherming: er kan in Exchange al een afspraak op dat tijdslot staan die nog niet is overgenomen, en zonder deze blokkade zou je een dubbele boeking maken.

De organisator krijgt een mail met het verzoek het straks opnieuw te proberen. Zodra de status op `completed` staat, worden boekingen weer normaal geaccepteerd.

## Schaal en prestaties

Een eerdere versie van `buildSyncIndex()` deed N+1 queries: één query voor de lijst met agenda-objecten, en daarna één losse aanroep per afspraak om de iCal-data te lezen. Bij 50 gekoppelde ruimtes met 500 boekingen elk kwam dat neer op zo'n 25.000 queries per synchronisatieronde.

De huidige implementatie gebruikt Nextclouds `getMultipleCalendarObjects()` en haalt de data in batches van 100 op. Dezelfde belasting kost nu ongeveer 300 queries — een reductie van zo'n 98%. De tijdvakindex op de agenda-tabel houdt conflictdetectie daarnaast O(log n), ongeacht hoeveel boekingen er in totaal staan.

## Beveiliging in het kort

Het client secret staat versleuteld opgeslagen, elke webhook-melding wordt op handtekening gecontroleerd voordat de payload wordt verwerkt, en de notificatie-URL moet HTTPS zijn — Graph weigert een HTTP-endpoint eenvoudigweg. De subscription-ID's zijn willekeurige UUID's van Microsoft; voorspelbare ID's zouden vervalste meldingen mogelijk maken.

## Verder lezen

De [Engelse versie van deze pagina](exchange-integration.md) bevat het complete componentendiagram, de tabellen met configuratiesleutels en `exchangeConfig`-velden, de API-endpoints, en een overzicht van de testdekking.

## Zie ook

- [Backend-architectuur](backend-architecture.nl.md) — overzicht van de servicelaag
- [CalDAV-scheduling](caldav-scheduling.nl.md) — hoe gesynchroniseerde boekingen op de plugin ingrijpen
- [Beheerinstellingen → Exchange Sync](../admin/settings.md#exchange-sync-optional) — de configuratie-interface
- [Problemen oplossen](../admin/troubleshooting.md) — synchronisatieproblemen
