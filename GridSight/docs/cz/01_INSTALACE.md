[🇨🇿 **Česky**](01_INSTALACE.md) | [🇬🇧 English](../en/01_INSTALLATION.md)

# Instalace a provoz

GridSight vyžaduje Nextcloud 35, PHP podle požadavku v `src/appinfo/info.xml`, zapnutý Shared App Core alespoň `0.18.0-dev.2` a dostupné LINEA API se schématem `1`. Webový a cronový kontejner musí vidět stejný adresář `custom_apps`.

Rozbalte **zdrojový ZIP** do samostatné složky na NASu. Ze složky obsahující `install.sh`, `scripts/` a `src/` spusťte:

```sh
sudo sh install.sh
```

Úspěšný instalátor vypíše název, předchozí a novou verzi, velikost instalace a „Hotovo“. Při chybě vypíše celý protokol. Zkontroluje požadavky, připraví kód mimo `custom_apps`, ověří PHP, bezpečně zastaví a znovu spustí sběrač a vytvoří chybějící tabulky nebo sloupce. Existující historii nepřejmenovává, nekopíruje ani nemaže. Zálohu původního kódu ukládá mimo `custom_apps`; kontejner Nextcloudu nerestartuje.

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
