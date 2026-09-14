# Tips

Tijdbesparers en client-specifieke trucs om het maximale uit RoomVox te halen.

## Gebruik de visuele ruimte-browser (Nextcloud Calendar)

Als je beheerder de [calendar-patch](../features/calendar-patch.md) heeft geïnstalleerd, toont Nextcloud Calendar een volledige ruimte-browser in plaats van de minimale standaard-picker:

- **Blader door alle ruimtes** gegroepeerd per gebouw
- **Filter** op beschikbaarheid, capaciteit, gebouw en faciliteiten
- **Zoek** op naam, gebouw, adres of verdieping
- **Realtime beschikbaarheids-badges** (groen/rood/blauw)

Dit is dramatisch sneller dan een ruimte-naam typen in de standaard-picker.

## Zoek op meer dan alleen de naam

In de ruimte-browser matcht het zoekveld tegen:

- Ruimte-naam (bijvoorbeeld "Boardroom")
- Gebouw (bijvoorbeeld "Heidelberglaan")
- Adres
- Verdieping of ruimte-nummer (bijvoorbeeld "2.17")

Je kunt dus zoeken op "Heidelberglaan" om alle ruimtes in dat gebouw te zien, of op "3" om alle ruimtes op verdieping 3 te zien.

## Vermijd CUTYPE-problemen op iOS

iOS / macOS Calendar verstuurt ruimtes met `CUTYPE=INDIVIDUAL` in plaats van `CUTYPE=ROOM`. **RoomVox fixt dit automatisch** — je hoeft niets bijzonders te doen. Je event toont de ruimte correct na een sync-rondje.

## Gebruik LOCATION in eM Client

In eM Client kun je een ruimte op twee manieren toevoegen:

1. **Als deelnemer** — het standaard CalDAV-resource-patroon
2. **Stel het LOCATION-veld in op de naam van de ruimte** — RoomVox matcht de LOCATION tegen bekende ruimte-namen en voegt de ruimte voor je toe als deelnemer

De LOCATION-methode is sneller wanneer je de ruimte-naam wel weet, maar niet waar je hem vindt in de deelnemers-picker.

## Stel verstandige boekings-horizonnen in

Als je gefrustreerd raakt door "Voorbij boekings-horizon"-afwijzingen, overleg dan met je beheerder. De boekings-horizon (bijvoorbeeld max. 90 dagen) is een instelling per ruimte die versoepeld kan worden als de beperking niet nodig is.

## Terugkerende vergaderingen

Een terugkerende vergadering boeken (bijvoorbeeld een wekelijkse standup):

- Stel de RRULE in je agenda-app in zoals gebruikelijk
- RoomVox checkt **elke voorkomende keer** tegen beschikbaarheids-regels, conflicten en de boekings-horizon
- Als één voorkomende keer faalt, wordt de hele serie afgewezen — kies een ander terugkeer-patroon
- Stel altijd een `UNTIL` of `COUNT` in — oneindige herhalingen worden automatisch afgewezen wanneer een horizon is ingesteld

Voor "annuleer één instantie"-workflows, zie de [FAQ](faq.md#kan-ik-een-enkele-voorkomende-keer-van-een-terugkerende-boeking-annuleren).

## Snel annuleren

Om een ruimte vrij te geven vanuit je agenda-app:

1. Open het event
2. Verwijder de ruimte uit de deelnemers-lijst (of verwijder het event)
3. Sla op

RoomVox ontvangt het CANCEL-iTIP-bericht en geeft het slot vrij.

## Wanneer je een ruimte nodig hebt die je niet kunt boeken

Check **Persoonlijke instellingen → RoomVox → Mijn ruimtes**. Elke zichtbare ruimte toont zijn **verantwoordelijke contactpersoon** — naam, e-mail of instructies over bij wie je moet zijn. Dit is precies het veld dat je beheerder heeft ingevuld om te zorgen dat viewers weten wie ze moeten benaderen.

## Gebruik API-tokens voor integraties

Als je digital signage, ruimte-displays of check-in-kiosks bouwt, vraag je beheerder dan om een Public API-token. De API ondersteunt:

- Ruimtes opsommen met huidige status (Beschikbaar / Gereserveerd)
- Boekingen van een ruimte uitlezen
- Boekingen aanmaken (met `book`-scope)
- Statistieken (met `admin`-scope)
- iCal-feeds

Zie [Public API](../features/public-api.md).

## Zie ook

- [Ruimtes boeken](booking-rooms.md) — boekings-handleidingen per client
- [FAQ](faq.md) — veelgestelde vragen
- [Calendar-patch](../features/calendar-patch.md) — visuele ruimte-browser
