# App-Store-publicatie

> **Let op:** deze pagina beschrijft een proces dat draait om Engelse commando's, bestandsnamen en foutmeldingen van de Nextcloud App Store; die houden we niet in twee talen bij. Hieronder staat een Nederlandse introductie op het geheel; de letterlijke stappen staan in [de Engelse versie](app-store-submission).

## Voor wie is dit bedoeld

Voor de maintainer die een RoomVox-release naar de Nextcloud App Store brengt. Je hebt daarvoor twee dingen nodig die je maar één keer regelt — een certificaat en de bijbehorende private key — en per release een getekende tarball met een publiek bereikbare download-URL.

Deze pagina hoort bij het [release-proces](release-process); daar staat de volledige volgorde. Hier gaat het specifiek over het App-Store-deel.

## Hoe de App Store werkt

De App Store host je app niet. Wat je indient is een verwijzing: een download-URL naar de tarball op de GitHub-release, plus een signature die bewijst dat jij die tarball hebt gemaakt. De App Store haalt het bestand op, controleert de signature tegen het certificaat dat aan RoomVox gekoppeld staat, en distribueert het pas daarna.

Daar volgen twee dingen uit. Ten eerste moet de GitHub-release al gepubliceerd zijn en de tarball echt downloadbaar, voordat je indient. Ten tweede is de signature gebonden aan dat exacte bestand: vervang je de tarball achteraf, dan klopt de signature niet meer.

## Eenmalig: certificaat en key

De eerste keer regel je dit, daarna nooit meer — tenzij het misgaat.

1. Genereer een private key (`openssl genrsa`, 4096 bits) en een certificate signing request.
2. Dien de CSR in als issue bij `nextcloud/app-certificate-requests`. Goedkeuring duurt doorgaans een dag of twee.
3. Het Nextcloud-team commit een getekend certificaat terug.
4. Registreer de app op `apps.nextcloud.com/developer/register`, upload het certificaat en teken een challenge om te bewijzen dat je de private key hebt.

De private key is het kroonjuweel. Zonder die key kun je geen enkele release meer uploaden. Bewaar hem versleuteld met een backup buiten je werkmachine, en zorg dat hij nooit in een tarball of in git belandt.

## Per release

Op hoofdlijnen doorloop je dit:

1. **Controleer of key en certificaat nog bij elkaar horen.** Vergelijk de MD5 van de public key van je lokale key met die van het certificaat dat de App Store voor RoomVox publiceert. Die twee moeten identiek zijn.
2. **Bouw de app schoon.** Oude build weggooien, `composer install --no-dev`, `npm ci` (niet `npm install` — reproduceerbaarheid), dan de production build.
3. **Maak de tarball** met `roomvox/` als root-map, lowercase en zonder versienummer.
4. **Scan de tarball** op wat er niet in hoort.
5. **Teken de tarball** met `openssl dgst -sha512` en je private key, base64 zonder newlines.
6. **Publiceer de GitHub-release** met de tarball als asset.
7. **Dien in** via de API, of via de web-UI als de API weigert.

Goedkeuring door het Nextcloud-team kan dagen tot weken duren. Ze kijken naar codekwaliteit, security, naleving van de App Store-richtlijnen en correct gebruik van de Nextcloud-API's.

## De twee upload-routes

Er zijn twee wegen naar hetzelfde resultaat, en je hebt ze allebei nodig.

**Route A — de API.** Een `curl`-POST naar `apps.nextcloud.com/api/v1/apps/releases` met je API-token, de download-URL en de signature. HTTP 200 betekent klaar.

**Route B — de web-UI.** Inloggen op `apps.nextcloud.com`, naar het developer-dashboard van RoomVox, "New Release", en daar download-URL, signature en release notes plakken. Die pagina is alleen bereikbaar als je bent ingelogd als eigenaar van de app.

Route B is geen noodgreep maar een reële tweede weg, want de API-token verloopt zonder waarschuwing. Je merkt dat aan een HTTP 403 met "You do not have permission". Publiceer dan via de web-UI en ververs daarna pas je token — dan houd je de release niet op.

## Valkuilen die er echt toe doen

**De root-map van de tarball.** `roomvox`, lowercase, geen versienummer. Fout is fout: de installatie faalt bij de gebruiker met `App not found in archive`. Controleer het met `tar -tzf ... | head -3` voor je uploadt.

**Wat er niet in de tarball mag.** `src/`, `node_modules/`, `.git/`, `tests/`, alle `.key`- en `.crt`-bestanden, de deploy-scripts met serverdetails, en elk stuk sample-data. Scan door de tarball uit te pakken en per bestandstype te greppen — een enkele grep over de hele gecomprimeerde stroom geeft vals alarm op geminificeerde bundles.

**De signature hoort bij één bestand.** Hergenereer je de tarball, dan moet je opnieuw tekenen. En de signature is één lange regel base64 zonder newlines.

**Vraag niet zomaar een nieuw certificaat aan.** Een nieuw certificaat trekt het oude automatisch in en breekt alle bestaande tooling. Klopt de MD5-vergelijking niet, zoek dan eerst uit waarom voor je iets nieuws aanvraagt.

**Volg redirects bij de certificaat-check.** `apps.nextcloud.com/api/v1/apps.json` stuurt tegenwoordig een 302 naar `garm2.nextcloud.com`. Zonder `curl -sL` vergelijk je stilzwijgend twee lege invoerstromen en denk je dat alles klopt.

**Geen `vendor/` is correct.** RoomVox heeft geen runtime-Composer-dependencies. De per-room-SMTP leunt op Symfony Mailer die Nextcloud zelf meelevert, en de Exchange-sync praat rechtstreeks met de Graph REST-API via Nextclouds eigen HTTP-client. Komt er ooit wél een echte runtime-dependency bij, dan moet `vendor/` mee in de tarball.

## Voor de exacte commando's

Zie de [Engelse App-Store-publicatiedocumentatie](app-store-submission) voor:

- De `openssl`-commando's voor key, CSR en signature
- De volledige MD5-vergelijking van key tegen App-Store-certificaat
- Het complete tarball-commando
- De `curl`-aanroep voor Route A, met de statuscodes en hun betekenis
- De stappen om een verlopen API-token te vervangen
- De volledige troubleshooting-lijst

## Zie ook

- [Release-proces](release-process) — de volledige release-volgorde waar dit onderdeel van is
- [Installatie](installation) — RoomVox installeren op een Nextcloud-instantie
