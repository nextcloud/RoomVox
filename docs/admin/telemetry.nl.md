# Telemetrie

RoomVox verzamelt anonieme gebruiksdata om de app te helpen verbeteren. Dit is een **opt-out**-feature — hij staat standaard aan en kan op elk moment uitgezet worden.

## Welke data wordt verzameld

RoomVox stuurt de volgende anonieme data eens per 24 uur:

| Data | Omschrijving |
|------|-------------|
| Instance hash | SHA-256-hash van je Nextcloud-URL (niet de URL zelf) |
| App-versie | Geïnstalleerde RoomVox-versie |
| Totaal aantal ruimtes | Aantal geconfigureerde ruimtes |
| Totaal aantal ruimte-groepen | Aantal ruimte-groepen |
| Aantallen per ruimte-type | Hoeveel ruimtes er per type zijn (bijv. 3 vergaderruimtes, 2 studio's) |
| Gemiddelde capaciteit | Gemiddelde ruimte-capaciteit |
| Aantallen faciliteiten | Hoeveel ruimtes elke faciliteit hebben (beamer, whiteboard, etc.) |
| Aantal met auto-accept | Hoeveel ruimtes auto-accept gebruiken |
| Ruimtes met SMTP | Hoeveel ruimtes een eigen SMTP geconfigureerd hebben |
| Beschikbaarheids-regels | Hoeveel ruimtes beschikbaarheids-regels ingeschakeld hebben |
| Totaal aantal gebruikers | Totaal aantal Nextcloud-gebruikers |
| Actieve gebruikers (30d) | Gebruikers die de laatste 30 dagen actief waren |
| Nextcloud-versie | Geïnstalleerde Nextcloud-versie |
| PHP-versie | PHP-versie van de server |
| Landcode | Uit Nextclouds instelling `default_phone_region` |
| Database-type | MySQL, PostgreSQL of SQLite |
| Standaardtaal | Standaardtaal van Nextcloud |
| Standaard-tijdzone | Tijdzone van de server |
| OS-familie | Linux, Windows of macOS |
| Webserver | Apache of nginx |
| Docker | Of de server in een Docker-container draait |
| Extended Support / Enterprise | Boolean die aangeeft of de Nextcloud-host een Extended Support-/Enterprise-abonnement heeft. Komt uit de publieke API van Nextcloud (`OCP\Util::hasExtendedSupport`). Valt terug op `false` als de host Community is |
| Abonnementssleutel | Je RoomVox-abonnementssleutel (wanneer er een geconfigureerd is). Wordt meegestuurd zodat de license-server de Enterprise-claim hierboven kan authenticeren — de boolean alleen zou door iedereen die naar het telemetrie-endpoint post vervalst kunnen worden. Lege string voor community-instanties |

## Wat NIET verzameld wordt

- Geen gebruikersnamen, e-mailadressen of persoonsgegevens
- Geen boekings-inhoud, event-titels of omschrijvingen
- Geen IP-adressen of hostnamen
- Geen ruimte-namen of adressen
- Geen wachtwoorden of API-tokens

## Waar de data naartoe gaat

Telemetrie-data wordt naar de telemetrie-server van RoomVox gestuurd.

## Telemetrie uitschakelen

### Via het beheerpaneel

1. Ga naar **Instellingen > Beheer > RoomVox**
2. Klik op de tab **Support**
3. Zet de schakelaar **Anonieme gebruiksstatistieken versturen** uit

### Via de commandline

```bash
sudo -u www-data php occ config:app:set roomvox telemetry_enabled --value false
```

## Handmatig rapport

Je kunt vanuit de Support-tab direct een telemetrie-rapport versturen:

1. Ga naar **Instellingen > Beheer > RoomVox**
2. Klik op de tab **Support**
3. Klik op **Nu rapport versturen**

De knop geeft duidelijke feedback:
- **Gelukt**: bevestigt dat het rapport verstuurd is en werkt de tijdstempel bij
- **Fout**: toont de specifieke foutmelding van de server (bijv. rate-limit, verbindingsprobleem)

## Technische details

- Telemetrie draait als Nextcloud-achtergrondtaak (`TelemetryJob`)
- Rapporten worden elke 24 uur verstuurd met een willekeurige jitter van maximaal 2 uur om de belasting te spreiden
- De jitter is stabiel per installatie (gebaseerd op de hash van de instance-ID)
- Mislukte rapporten worden stilzwijgend opnieuw geprobeerd bij het volgende interval
- Timeout: 15 seconden per request
