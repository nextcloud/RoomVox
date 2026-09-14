# Release-proces

> **Let op:** dit is ontwikkelaarsdocumentatie en blijft Engelstalig — de commando's, bestandsnamen en foutmeldingen zijn Engels, en het proces verandert te vaak om in twee talen betrouwbaar te houden. Deze pagina geeft een Nederlandse introductie; voor de exacte stappen en commando's zie [de Engelse versie](release-process).

## Voor wie is dit bedoeld

Deze pagina is voor de maintainer die een RoomVox-release uitbrengt. Je hebt commit-rechten op de repository nodig, een lokale build-omgeving (Node en Composer), en toegang tot de signing key en het App Store-certificaat. Ben je gebruiker of beheerder en wil je RoomVox alleen installeren of bijwerken? Dan heb je [Installatie](installation) nodig, niet deze pagina.

De actuele versie is RoomVox 1.5.0, die Nextcloud 32 tot en met 35 ondersteunt.

## Waar een release naartoe gaat

Eén release landt op drie plekken, en die drie moeten dezelfde inhoud dragen:

| Bestemming | Rol |
|---|---|
| **Forgejo** (`origin`) | Intern leidend — hier staat de waarheid |
| **GitHub** (`nextcloud/RoomVox`) | Publiek, inclusief de release-tarball die de App Store downloadt |
| **Nextcloud App Store** | Distributie naar eindgebruikers, via download-URL plus signature |

Push naar GitHub gaat altijd via `./push-to-github.sh`, nooit met een kale `git push github` — dat script haalt eerst de interne bestanden en deploy-scripts weg die op GitHub niets te zoeken hebben.

## Het proces op hoofdlijnen

1. **Openstaande pull requests controleren.** Doe dit vóór je iets anders doet. Een PR die nog wacht terwijl jij de versie al bumpt, mist de release — en als dat een security-fix is, staat er een bekend gat in een versie die je zelf net hebt uitgebracht.
2. **Versie bepalen en synchroniseren.** RoomVox volgt semantic versioning: MAJOR bij breaking changes, MINOR bij nieuwe features, PATCH bij bugfixes. Twee bestanden declareren de versie — `package.json` en `appinfo/info.xml` — en die **moeten** hetzelfde nummer dragen.
3. **Bouwen en testen.** `npm ci` plus een production build, en de PHPUnit-suite groen. De unit-tests draaien standalone, dus je hebt hier geen draaiende Nextcloud voor nodig.
4. **CHANGELOG bijwerken.** Nieuwe sectie bovenaan, in het bestaande patroon. Elke entry die uit een GitHub issue komt krijgt een markdown-link naar dat issue.
5. **Committen, pushen en taggen.** Eerst de commit naar Forgejo en GitHub, dan pas de tag.
6. **Tarball bouwen.** Zie hieronder — dit is de stap waar het het vaakst misgaat.
7. **Signature maken** met `openssl` en de private key die bij het App Store-certificaat hoort.
8. **GitHub-release aanmaken** met de tarball als asset. De download-URL van die asset is wat je straks aan de App Store geeft.
9. **Indienen bij de App Store.** Probeer eerst de API; werkt die niet, dan de web-UI. Zie [App-Store-publicatie](app-store-submission).
10. **Verifiëren na de release.** Installeer de app op een testserver zodra hij is goedgekeurd, controleer het versienummer in de app-lijst en draai de smoke-test: beheerpaneel, persoonlijke instellingen, een boeking via de Nextcloud-agenda, de iCal-feed en de notificatie-mails.

## De tarball

De App Store accepteert alleen een tarball met `roomvox/` als root-map — lowercase, en zonder versienummer erin. Zit er iets anders in, bijvoorbeeld `RoomVox/` of `roomvox-1.5.0/`, dan faalt de installatie bij de gebruiker met `App not found in archive`.

Wat er niet in mag: `src/` (alleen de gecompileerde `js/` gaat mee), `node_modules/`, `.git/`, `tests/`, elk `.key`- of `.crt`-bestand, de deploy-scripts en `nc-calendar-patch/`. Controleer dat voor je uploadt.

Let op bij die controle: scan de tarball door hem **uit te pakken** en dan per bestandstype te greppen. Een `tar -xzf -O` die alles als één blob door grep haalt levert vals alarm op — webpack-geminificeerde bundles bevatten toevallige byte-reeksen die op `password=` lijken.

RoomVox heeft geen runtime-Composer-dependencies, dus de tarball bevat terecht geen `vendor/`. Dat is geen vergissing en vraagt geen actie.

## Valkuilen die er echt toe doen

**Hertaggen is gevaarlijk.** Een bestaande tag opnieuw zetten en pushen zet de GitHub-release terug naar draft. De download-URL geeft dan 404 — of erger, een bestand van 9 bytes — en de update faalt bij iedereen die hem al binnenhaalde. Ga je de mist in na het taggen, breng dan liever een nieuwe patch-versie uit dan dat je een tag verplaatst.

**De App Store-API-token verloopt stil.** Je merkt het pas aan een HTTP 403 op de upload. Houd de web-UI-route achter de hand; dat is geen noodgreep maar een normale tweede weg.

**De signature hoort bij precies dat ene bestand.** Bouw je de tarball opnieuw, dan is de signature ongeldig — ook als de inhoud identiek lijkt. Onderteken het bestand dat je daadwerkelijk naar GitHub uploadt, en niets anders. De signature is base64 zonder newlines: één lange regel.

**Certificaat en key moeten bij elkaar horen.** Vergelijk voor elke release de MD5 van je lokale public key met die van het certificaat in de App Store. Vraag nooit zomaar een nieuw certificaat aan: een nieuw certificaat trekt het oude in en breekt alles wat er nog op leunt.

## Het Nextcloud-plafond ophogen

Ondersteuning voor een nieuwe Nextcloud-major volgt steeds dezelfde route: controleer de app tegen een **draaiende** instantie van die major — niet tegen de release notes — door elk `OCP\`-symbool dat de app importeert te resolven en elke class te laden, installeer en enable de app, en lees het log. Leg de audit vast onder `docs/architecture/`, verhoog dan pas `max-version` in `info.xml` plus het versienummer in beide versiebestanden, en smoke-test op de bijbehorende testserver.

Zo ging het bij NC 34 (in 1.2.0) en NC 35 (in 1.5.0). In beide gevallen was er geen enkele API-wijziging nodig: het gedeclareerde plafond was het enige dat installatie blokkeerde.

## Voor de exacte commando's

De [Engelse release-procesdocumentatie](release-process) bevat de commando's die je letterlijk kunt overnemen:

- De build-, test- en versie-controle-commando's
- Het complete tarball-commando inclusief de map-structuur
- Het `openssl`-commando voor de signature
- De `gh release create`-aanroep met CHANGELOG-extractie
- De `curl`-aanroep voor de App Store-API
- De volledige lijst met eerder opgelopen incidenten

## Zie ook

- [App-Store-publicatie](app-store-submission) — certificaat, signature en de twee upload-routes
- [Installatie](installation) — RoomVox installeren op een Nextcloud-instantie
