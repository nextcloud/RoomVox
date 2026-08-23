# CI faalt op nextcloud/ocp dev-master

> Uitgezocht 2026-08-23 bij de Forgejo-migratie. **Niet toegepast** — dit is een
> inhoudelijke keuze in deze repo, geen migratiewijziging.

## Symptoom

Beide matrixtakken van `tests.yml` en `compliance.yml` falen op:

    Root composer.json requires nextcloud/ocp dev-master
    -> nextcloud/ocp dev-master requires php ~8.3 || ~8.4 || ~8.5
    -> your php version (8.2; overridden via config.platform) does not satisfy that

Ook de 8.3-tak faalt, want `config.platform.php` staat op `8.2` en dat
override weegt zwaarder dan de werkelijk geïnstalleerde PHP-versie.

## Oorzaak

`composer.json` vraagt `"nextcloud/ocp": "dev-master"`. Dat is de
**ontwikkelversie** van Nextcloud (nu NC 35), en die eist inmiddels PHP 8.3+.

Maar `appinfo/info.xml` zegt `min-version="32" max-version="34"`. De app
ondersteunt dus NC 32 t/m 34, terwijl composer de nog-niet-uitgebrachte
NC 35 binnenhaalt. Dat liep sowieso uit de pas; de PHP-eis maakt het zichtbaar.

Dit is geen migratiefout — het speelde op Gitea net zo goed, alleen kwam de CI
daar niet tot `composer install`.

## Voorgestelde fix

```diff
-        "nextcloud/ocp": "dev-master"
+        "nextcloud/ocp": ">=32.0 <35.0"
```

Dat sluit aan op wat `info.xml` belooft. **Getest met `composer update --dry-run`:**

| platform.php | resolvet naar |
|---|---|
| 8.2 | `nextcloud/ocp v34.0.3` |
| 8.3 | `nextcloud/ocp v34.0.3` |

`ocp` v34.0.0 accepteert `~8.1 || ~8.2 || ~8.3 || ~8.4 || ~8.5`, dus beide
matrixtakken lopen weer. `config.platform.php` kan op 8.2 blijven — dat is
de laagste versie die de app claimt te ondersteunen, en daarop bouwen is juist
gewenst.

MetaVox doet het vergelijkbaar met `dev-stable31`: een stabiele lijn, geen master.

## Alternatief (niet aanbevolen)

`config.platform.php` op `8.3` zetten en `dev-master` laten staan. Dan bouw je
niet meer op je eigen ondergrens, en haal je een NC-versie binnen die buiten de
ondersteunde range valt. Lost het symptoom op, niet de oorzaak.
