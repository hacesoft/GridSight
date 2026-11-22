[🇨🇿 **Česky**](01_INSTALACE.md) | [🇬🇧 English](../en/01_INSTALLATION.md)

# Instalace a provoz

GridSight vyžaduje Nextcloud 35, PHP podle požadavku v `src/appinfo/info.xml`, zapnutý Shared App Core alespoň `0.18.8` a dostupné LINEA API se schématem `1`. Webový a cronový kontejner musí vidět stejný adresář `custom_apps`.

Rozbalte **zdrojový ZIP** do samostatné složky na NASu. Ze složky obsahující `install.sh`, `scripts/` a `src/` spusťte:

```sh
sudo sh install.sh
```

Úspěšný instalátor vypisuje **průběžně 18 kroků na samostatných řádcích** (`[01/18] … OK` až `[18/18] … OK`) stejně jako referenční FamilyTree. Nejde o odložený souhrn. V závěru vypíše název, předchozí a novou verzi, velikost instalace a „Hotovo“. Při chybě oznámí neúspěšný krok, diagnostiku a pokus o bezpečnou obnovu předchozího stavu. Samotné kroky nespouštějte ručně ani je nepřeskakujte.

```text
[01/18] Kontrola oprávnění a Dockeru ..... OK
[02/18] Vyhledání kontejnerů Nextcloud ..... OK
[03/18] Kontrola duplicitních kopií aplikace ..... OK
[04/18] Kontrola kompatibility Nextcloud ..... OK
[05/18] Kontrola zdrojů, verze a databáze ..... OK
[06/18] Ověření HC Shared App Core ..... OK
[07/18] Příprava adresáře záloh a aktuálního stavu ..... OK
[08/18] Příprava izolované instalační kopie ..... OK
[09/18] Kontrola PHP syntaxe nové kopie ..... OK
[10/18] Pozastavení sběrače historie LINEA ..... OK
[11/18] Pozastavení předchozí aplikace ..... OK
[12/18] Záloha původní aplikace ..... OK
[13/18] Nahrání aplikace do webového kontejneru ..... OK
[14/18] Synchronizace s cron kontejnerem ..... OK
[15/18] Obnovení PHP cache a kontrola databáze ..... OK
[16/18] Zapnutí aplikace a migrace ..... OK
[17/18] Ověření instalované verze a souhrn ..... OK
[18/18] Obnovení sběrače historie LINEA ..... OK
Aplikace: GridSight
Verze: 1.0.1 -> 1.0.2
Velikost instalace: 1720 KiB
Hotovo.
```

*Ukázka výpisu; předchozí verze a velikost se podle instalace mohou lišit.* Zkontroluje požadavky, připraví kód mimo `custom_apps`, ověří PHP, bezpečně zastaví a znovu spustí sběrač a vytvoří chybějící tabulky nebo sloupce. Existující historii nepřejmenovává, nekopíruje ani nemaže. Zálohu původního kódu ukládá mimo `custom_apps`; kontejner Nextcloudu nerestartuje.

V kartě **Nastavení** zadejte URL LINEA API. Po instalaci zkontrolujte spojení a stav sběrače v kartě **Sběr**. Pro diagnostiku sběrače ze zdrojového adresáře slouží:

```sh
sudo sh scripts/collector.sh status
sudo sh scripts/collector.sh logs
```

Při neúspěšné instalaci zachovejte její úplný výpis. Instalátor se pokusí obnovit předchozí kód a stav aplikace; uvede cestu k záloze, pokud ji vytvořil. Ručně nemažte tabulky ani naplánované úlohy.

Když živé teploty zůstávají prázdné, následující příkaz **pouze čte** blok čidel z LINEA API přes běžné nastavení GridSight. Nezobrazuje URL ani přístupové údaje; výpis lze přiložit při diagnostice tvaru API:

```sh
sudo docker exec -i -u www-data nextcloud-app php <<'PHP'
<?php
require '/var/www/html/lib/base.php';
$status = \OC::$server->get(\OCA\HcGridSight\Service\LineaApiService::class)->status();
echo json_encode([
    'temperatures' => $status['temperatures'] ?? null,
    'batteryTemperatureC' => $status['energy']['battery']['temperatureC'] ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
PHP
```

Výchozí databázi vytváří jediná migrace `Version009000Date20261006090000`. Nextcloud ji spouští při instalaci/aktualizaci aplikace. Instalační skript navíc ověří schéma i při opakování přerušené instalace. Existující měření se nemažou. Nevyžaduje se ruční příkaz `migrations:migrate`.
