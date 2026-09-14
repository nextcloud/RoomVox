# Best practices

Aanbevelingen voor het inrichten, onderhouden en draaien van RoomVox.

## Permissie-strategie

### Gebruik groepen in plaats van individuele gebruikers

Ken permissies waar mogelijk toe aan Nextcloud-groepen in plaats van aan individuele gebruikers.

- **Makkelijker te onderhouden** — een nieuwe gebruiker aan de juiste groep toevoegen geeft automatisch toegang
- **Overleeft de user-lifecycle** — vertrekkende medewerkers verliezen toegang zodra ze uit groepen verwijderd worden, geen opruimwerk per ruimte nodig
- **Betere CalDAV-zichtbaarheid** — groep-entries worden gepubliceerd als CalDAV-`group_restrictions`, waardoor de ruimte automatisch in de resource-lijst verschijnt. Gebruiker-entries worden alleen op boekingsmoment afgedwongen en vereisen mogelijk handmatig zoeken

### Gebruik ruimte-groepen voor gedeelde permissie-patronen

Als een gebouw, afdeling of locatie meerdere ruimtes met hetzelfde toegangspatroon heeft, maak dan een **ruimte-groep**:

- Stel permissies één keer in op de groep
- Alle ruimtes in de groep erven die permissies
- Voeg ruimte-specifieke uitzonderingen toe op individuele ruimtes (effectieve permissies = vereniging van beide)

Voorbeeld:

```
Room Group "Building A":
  bookers: [group: staff]
  managers: [group: facilities]

Room "Boardroom" (in Building A):
  bookers: [group: leadership]

Effective permissions for "Boardroom":
  bookers: [group: staff, group: leadership]
  managers: [group: facilities]
```

### Wijs ten minste één manager per ruimte aan

Zonder manager kunnen boekingen die in afwachting staan niet goedgekeurd worden. Als je overal auto-accept gebruikt, kun je dit overslaan — maar wijs voor elke ruimte met `autoAccept=false` ten minste één manager aan die:

- Goedkeurings-verzoeken per e-mail ontvangt
- Kan goedkeuren/afwijzen vanaf het tabblad Boekingen
- Ruimte-instellingen en permissies kan bewerken

### Houd viewer-permissies ruim

De rol Viewer laat gebruikers een ruimte **zien** in agenda-apps en onder **Persoonlijke instellingen → Mijn ruimtes**, waar ze ook het veld **Verantwoordelijke contactpersoon** zien. Dat is ook nuttig voor gebruikers die niet mogen boeken — ze weten zo bij wie ze moeten zijn als ze de ruimte nodig hebben.

### Controleer permissies periodiek

Elk kwartaal:

- Verwijder vertrokken gebruikers uit expliciete permissies
- Verifieer dat groeps-lidmaatschappen nog kloppen met de werkelijkheid
- Check of ruimtes met `autoAccept=false` nog een actieve manager hebben

## Ruimte-configuratie

### Vul een verantwoordelijke contactpersoon in

Gebruik het veld **Verantwoordelijke contactpersoon** (max. 255 tekens) om viewers te vertellen bij wie ze terechtkunnen als ze een ruimte niet zelf kunnen boeken. Vrije tekst — naam + e-mailadres, of gewoon "Vraag de gebouwbeheerder", of een telefoonnummer. Zichtbaar onder Persoonlijke instellingen → Mijn ruimtes.

### Gebruik beschikbaarheids-regels in plaats van handmatige goedkeuring

Als je wilt dat ruimtes alleen tijdens kantooruren boekbaar zijn:

- ✅ Configureer [beschikbaarheids-regels](../features/availability-rules.md) (weekdag/tijdvenster)
- ❌ Zet auto-accept niet uit alleen om aanvragen buiten kantooruren handmatig goed te keuren

Beschikbaarheids-regels **wijzen automatisch af** buiten de openingstijden, met een duidelijke e-mail die de regels benoemt. Handmatige beoordeling voor precies hetzelfde is verspilde moeite.

### Stel een boekings-horizon in voor veelgevraagde ruimtes

Beperk hoe ver vooruit veelgevraagde ruimtes geboekt kunnen worden (bijvoorbeeld max. 60 dagen). Dit:

- Voorkomt speculatieve reserveringen maanden vooruit
- Dwingt tot actuelere planning
- Vangt oneindige terugkerende events af (die worden altijd afgewezen als er een horizon is ingesteld)

### Kies de juiste standaard voor auto-accept

| Standaard | Wanneer gebruiken |
|---|---|
| **Aan** (direct bevestigen) | Omgevingen met veel onderling vertrouwen, capaciteit is geen strijdpunt, snel schakelen telt |
| **Uit** (manager-goedkeuring) | Veelgevraagde ruimtes, formele locaties (bestuurskamer, collegezaal), boekingen moeten getoetst worden |

Stel de app-brede standaard in onder **Instellingen**, en wijk per ruimte af waar nodig.

## E-mail-strategie

### Configureer de Nextcloud-SMTP één keer

De meeste installaties zouden voor alle ruimtes de **globale SMTP van Nextcloud** moeten gebruiken. Houd SMTP per ruimte achter de hand voor gevallen waarin ruimtes zichtbaar vanaf hun eigen mailbox moeten versturen (bijvoorbeeld `boardroom@company.com`).

### Zet echte e-mailadressen op zichtbare ruimtes

Als een ruimte een echte mailbox heeft, stel die dan in als e-mailadres van de ruimte:

- Notificaties lijken van de ruimte te komen (`From: boardroom@company.com`)
- iMIP-uitnodigingen naar externe deelnemers tonen de ruimte als een echte entiteit
- Antwoorden komen in de juiste inbox terecht

Heeft een ruimte geen echte mailbox, laat het e-mailadres dan leeg — RoomVox genereert automatisch `<room-id>@roomvox.local` en valt voor uitgaande mail terug op de Nextcloud-systeem-afzender.

### Verifieer dat `mail_smtpsecure` is ingesteld

Een veelvoorkomende omissie: `mail_smtpmode`, `mail_smtphost` en `mail_smtpport` zijn ingesteld, maar `mail_smtpsecure` ontbreekt. SMTP werkt dan in sommige clients en loopt in andere in een timeout. Stel altijd in:

- `tls` voor poort 587 (STARTTLS)
- `ssl` voor poort 465

### Schakel `sendInvitations` in voor iMIP

```bash
sudo -u www-data php occ config:app:set dav sendInvitations --value yes
```

Zonder dit mislukken agenda-uitnodigingen naar externe deelnemers met "Failed to deliver invitation".

## CSV-import (migratie vanaf MS365)

### Gebruik het volledige export-script

Gebruik voor MS365-migraties het volledige PowerShell-script in [Import / export](import-export.md) dat `Get-EXOMailbox` en `Get-Place` samenvoegt — de simpele `Get-Place`-pipeline behoudt **geen** e-mailadressen, waardoor duplicaat-detectie stukgaat.

### Eerst importeren, daarna permissies

Werkwijze:

1. Exporteer uit MS365 (volledig script met e-mailadressen)
2. Importeer eerst in de modus **"Alleen nieuwe ruimtes aanmaken"** om te verifiëren
3. Draai opnieuw in de modus **"Aanmaken + updaten"** om bestaande bij te werken
4. Voeg permissies per ruimte of per ruimte-groep toe **nadat** de ruimtes bestaan

### Exporteer vóór bulk-wijzigingen

Exporteer altijd een back-up-CSV vóór:

- Een bulk-import die bestaande ruimtes bijwerkt
- Handmatige aanpassingen aan veel ruimtes
- Het verwijderen van een ruimte-groep

De export is een complete snapshot — round-trip-veilig.

## Onderhoud

### Let op "Boeking niet toegestaan"-warnings

Sinds v1.1.1 logt RoomVox entries op niveau **warning** wanneer de iTIP-afzender naar nul of meerdere Nextcloud-gebruikers oplost. Dat wijst meestal op LDAP/AD-configuraties waar hetzelfde e-mailadres op meerdere accounts staat. Check `nextcloud.log`:

```bash
tail -f /var/www/nextcloud/data/nextcloud.log | grep -i "RoomVox.*permission\|RoomVox.*decline"
```

### Test de calendar-patch na NC-updates

Als je de [calendar-patch](../features/calendar-patch.md) uitrolt, kan elke update van Nextcloud of de Calendar-app de gepatchte JS-bestanden overschrijven. Rol na elke update opnieuw uit met `./deploy-calendar.sh <target>` — en check of de upstream-versie van Calendar nog v6.2.0 is (de patch is daarop gepind).

### Een Nextcloud-upgrade plannen

RoomVox declareert NC 32 tot en met 35 in `appinfo/info.xml`, dus upgrades binnen dat bereik vragen niets van jou. Elk plafond wordt pas verhoogd na een audit tegen een draaiende instantie — zie [Nextcloud 34-compatibiliteit](../architecture/nc34-compatibility.md) en [Nextcloud 35-compatibiliteit](../architecture/nc35-compatibility.md).

Upgraden naar een Nextcloud-major boven het gedeclareerde plafond is het ene geval om op te wachten: de App Store biedt RoomVox er niet voor aan totdat een release `max-version` verhoogt. Bewerk `info.xml` niet zelf om daaromheen te werken — een niet-geauditeerde major kan falen op manieren die pas tijdens runtime zichtbaar worden.

## Zie ook

- [Permissies](permissions.md) — drie-rollen-systeem + overerving
- [E-mail-configuratie](email-configuration.md) — Nextcloud en SMTP per ruimte
- [Import / export](import-export.md) — CSV-workflows
- [Troubleshooting](troubleshooting.md) — veelvoorkomende problemen
