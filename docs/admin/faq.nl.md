# FAQ voor beheerders

Veelgestelde vragen van RoomVox-beheerders. Voor vragen aan gebruikerskant, zie de [FAQ voor gebruikers](../user/faq.md).

## Configuratie

### Heb ik een database nodig voor RoomVox?

Geen eigen tabellen. RoomVox slaat **alle** configuratie op in Nextclouds `IAppConfig` (key-value-store). Boekingen leven in standaard CalDAV-agenda's. Geen migraties bij installatie of upgrade. Werkt met elke DB-backend die Nextcloud ondersteunt (PostgreSQL, MySQL, SQLite).

### Waar staan de ruimtes in de database?

In `oc_appconfig` onder de app `roomvox`. Inspecteer met:

```bash
sudo -u www-data php occ config:app:get roomvox rooms_index       # array of room IDs
sudo -u www-data php occ config:app:get roomvox room/<roomId>     # one room
sudo -u www-data php occ config:app:get roomvox permissions/<roomId>  # permissions
```

Zie [Backend-architectuur](../architecture/backend-architecture.md) voor het volledige key/value-model.

### Kan ik per ruimte andere instellingen hebben?

Ja. Het app-brede tabblad **Instellingen** is voor standaardwaarden (standaard auto-accept, ruimte-types, e-mail inschakelen, telemetrie). Instellingen per ruimte (auto-accept, beschikbaarheids-regels, boekings-horizon, e-mailadres, SMTP per ruimte, Exchange-koppeling) staan in de ruimte-editor.

### Waarom heeft agenda-delen in Nextcloud geen effect op RoomVox?

RoomVox heeft zijn **eigen** permissie-systeem. Een agenda delen in Nextcloud heeft geen effect op toegang tot ruimtes. Dat is bewust — de semantiek van ruimte-boeken (3 rollen, groeps-overerving, zichtbaarheid van CalDAV-resources) verschilt van agenda-delen. Zie [Permissies](permissions.md).

### Kunnen gebruikers ruimtes zien die ze niet mogen boeken?

Ja. De rol **Viewer** geeft zichtbaarheid zonder boekingsrecht. Nuttig voor gebruikers die af en toe moeten weten of een ruimte vrij is, of iemand anders namens hen laten boeken. Zie het veld **Verantwoordelijke contactpersoon** in [Ruimtes beheren](room-management.md).

## Boekings-gedrag

### Waarom is de boeking van mijn gebruiker afgewezen?

Veelvoorkomende redenen:

| Reden | Wat te checken |
|---|---|
| **Geen permissie** | Voeg gebruiker/groep toe als Booker op de ruimte (of op de ruimte-groep) |
| **Planning-conflict** | Er bestaat al een andere boeking op dat tijdstip — check het tabblad Boekingen |
| **Buiten beschikbaarheid** | Boeking valt buiten de beschikbaarheids-regels van de ruimte |
| **Voorbij boekings-horizon** | Boeking ligt te ver in de toekomst |
| **Afzender lost op naar 0 of meerdere gebruikers** | LDAP/AD met dubbele e-mailadressen — check de warning-log (v1.1.1+) |

De organisator ontvangt een duidelijke e-mail met uitleg over de afwijzing.

### Hoe werken conflicten bij terugkerende events?

Sinds v1.1.0 worden conflicten gecheckt op **elke voorkomende keer** van een terugkerend event — niet alleen op de master. Eerdere versies checkten alleen de master, waardoor conflicten in wekelijkse vergader-reeksen gemist werden. Zie [CHANGELOG #8](https://github.com/nextcloud/RoomVox/blob/main/CHANGELOG.md).

### Kan een gebruiker één enkele keer uit een terugkerende reeks annuleren?

Sinds v1.1.1 wel. De admin-UI biedt een expliciete keuze tussen **Deze keer annuleren** en **Hele reeks annuleren**. Bij het annuleren van één keer wordt een `EXDATE` op de master geschreven en wordt een eventuele bijpassende `RECURRENCE-ID`-override verwijderd. De agenda van de booker zelf krijgt een `RECURRENCE-ID`-override die de ruimte voor die ene instantie op `DECLINED` zet. Zie [CHANGELOG #13](https://github.com/nextcloud/RoomVox/blob/main/CHANGELOG.md).

### Wat gebeurt er als een manager een geaccepteerde boeking annuleert?

Sinds v1.1.0 spiegelt de annuleer-flow iTIP CANCEL:

1. De boeking wordt uit de ruimte-agenda verwijderd
2. De ruimte-deelnemer wordt uit het eigen event van de booker verwijderd
3. `LOCATION` wordt gewist
4. Er gaat een "Boeking geannuleerd door manager"-e-mail naar de booker

Het slot komt direct weer vrij in de Room Finder. Zie [CHANGELOG #10](https://github.com/nextcloud/RoomVox/blob/main/CHANGELOG.md).

## E-mail

### Waarom werkt e-mail niet?

Check op volgorde:

1. **Nextcloud-SMTP geconfigureerd** — Instellingen → Beheer → Basisinstellingen → E-mailserver. Klik op "E-mail versturen" om te testen.
2. **`mail_smtpsecure` ingesteld** — veelvoorkomende omissie. `tls` voor poort 587, `ssl` voor poort 465.
3. **RoomVox-e-mail ingeschakeld** — tabblad Instellingen → "E-mail-notificaties inschakelen".
4. **Gebruikers hebben e-mailadressen** — vereist voor zowel organisator als managers.
5. **`sendInvitations` ingeschakeld** — `occ config:app:set dav sendInvitations --value yes`.

Zie [E-mail-configuratie](email-configuration.md) en [Troubleshooting](troubleshooting.md).

### Kan elke ruimte zijn eigen SMTP hebben?

Ja. SMTP per ruimte in de ruimte-editor:

- Host, poort, gebruikersnaam, wachtwoord (versleuteld met `ICrypto`), encryptie (TLS/SSL/geen)
- De SMTP-**gebruikersnaam** is de envelope-sender
- Het **ruimte-e-mailadres** wordt als Reply-To gezet als het afwijkt van de gebruikersnaam

Ruimtes zonder eigen SMTP vallen terug op de globale SMTP van Nextcloud. Zie [E-mail-configuratie → SMTP per ruimte](email-configuration.md#smtp-per-ruimte-optioneel).

### Waarom komen e-mails van `noreply@roomvox.local`?

Ruimtes met automatisch gegenereerde `@roomvox.local`-e-mailadressen worden **niet** als afzenderadres gebruikt. In plaats daarvan wordt de Nextcloud-systeem-afzender (`mail_from_address@mail_domain`) gebruikt.

Stel een echt e-mailadres in op de ruimte (bijvoorbeeld `boardroom@company.com`) om dit te veranderen.

## Versies & compatibiliteit

### Welke Nextcloud-versies worden ondersteund?

NC 32 tot en met 35 (volgens `appinfo/info.xml`). Zie [NC 34-compatibiliteit](../architecture/nc34-compatibility.md) en [NC 35-compatibiliteit](../architecture/nc35-compatibility.md) voor de audits achter die versiebereiken.

### Zijn er breaking changes die ik moet weten?

| Versie | Wijziging | Impact |
|---|---|---|
| v1.0.0 → v1.1.0 | Annuleren door manager spiegelt iTIP CANCEL | Bookers krijgen nu een annuleringsmail en hun event toont de ruimte niet meer |
| v1.0.x → v1.1.0 | Conflict-checking expandeert nu terugkerende events | Boekingen die er eerder op latere keren doorheen glipten, worden nu correct afgewezen |
| v1.1.0 → v1.1.1 | Veld `responsibleContact` staat nu op de API-whitelist | Opgelost: bewerkingen worden niet meer stil weggegooid bij create/update |
| v1.1.0 → v1.1.1 | Boeking-aanmaken via API routeert manager-goedkeurings-mails | Boekingen die via de REST-API aangemaakt worden, triggeren nu goedkeurings-mails voor ruimtes zonder auto-accept |
| v1.1.0 → v1.1.1 | Het veld `ORGANIZER` verzint geen `@localhost` meer | Boekingen met een extern e-mailadres hebben nu een correcte CalDAV-`ORGANIZER` |

Volledige historie in [CHANGELOG.md](https://github.com/nextcloud/RoomVox/blob/main/CHANGELOG.md).

## Publieke API

### Hoe maak ik een API-token aan?

1. **Instellingen → Beheer → RoomVox → tabblad Instellingen**
2. Scroll naar **API-tokens** → vul een naam en scope in
3. Klik op **Token aanmaken** — kopieer het meteen, het wordt maar één keer getoond

Zie [Publieke API](../features/public-api.md) voor gebruiksvoorbeelden.

### Welke scopes zijn er?

| Scope | Staat toe |
|---|---|
| `read` | Ruimtes opvragen, boekingen lezen, beschikbaarheid lezen |
| `book` | Bovenstaande + boekingen aanmaken |
| `admin` | Bovenstaande + statistieken + beheer-operaties |

Tokens kunnen optioneel beperkt worden tot specifieke ruimtes.

### Waar zie ik het gebruik van API-tokens?

Het interne tabblad **Statistieken** toont geaggregeerde boekings-aantallen, maar splitst die momenteel **niet** uit per token. Gebruik externe monitoring of check `nextcloud.log` op entries met `RoomVox.*api/v1`.

## Exchange / MS365

### Hoe koppel ik een ruimte aan Exchange?

1. Configureer de Exchange-inloggegevens onder Instellingen → Exchange (tenant-ID, client-ID, client secret)
2. Schakel Exchange-sync globaal in
3. Per ruimte: zet het e-mailadres van de ruimte op het adres van de Exchange-mailbox — RoomVox zet automatisch een initiële volledige sync in de wachtrij (`-30d` tot `+365d`)
4. Webhook-subscriptions worden automatisch aangemaakt voor updates in bijna-realtime

Zie [Exchange-integratie](../architecture/exchange-integration.md).

### Werken boekingen tijdens de initiële sync?

Nee — die worden tijdelijk afgewezen met schedule-status `5.3` (temporary failure) en de organisator krijgt een "Ruimte-sync bezig"-e-mail met het verzoek het later opnieuw te proberen. Dat voorkomt dubbele boekingen zolang RoomVox nog niet alle Exchange-events gezien heeft. Zodra de sync klaar is, verlopen boekingen weer normaal.

## Best practices

### Hoeveel ruimtes kan RoomVox aan?

Getest op **1500 ruimtes × 300 boekingen/uur** (load-simulatie-tests). Het knelpunt zit doorgaans in de CalDAV-laag van Nextcloud, niet in RoomVox zelf. De optimalisatie `buildSyncIndex()` (v1.1.x) verlaagt het aantal Exchange-sync-queries met 98% — van 25.000 naar 300 queries per cyclus van 15 minuten bij 50 ruimtes × 500 boekingen.

### Moet ik voor alle ruimtes SMTP per ruimte gebruiken?

Meestal niet. De globale SMTP van Nextcloud bedient alle ruimtes met één configuratie. Houd SMTP per ruimte achter de hand voor ruimtes met een eigen mailbox waarbij afzenders zichtbaar vanuit die mailbox moeten komen.

### Moet ik altijd manager-goedkeuring vereisen?

Nee. Gebruik beschikbaarheids-regels en de boekings-horizon om overduidelijk verkeerde boekingen automatisch af te wijzen. Houd manager-goedkeuring voor ruimtes waar menselijk oordeel nodig is (bestuurskamer, collegezaal, veelgevraagde ruimtes).

## Zie ook

- [Beheerdershandleiding](guide.md)
- [Best practices](best-practices.md)
- [Troubleshooting](troubleshooting.md)
- [FAQ voor gebruikers](../user/faq.md)
