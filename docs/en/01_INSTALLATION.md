[🇨🇿 Česky](../cz/01_INSTALACE.md) | [🇬🇧 **English**](01_INSTALLATION.md)

# Installation and operation

GridSight requires Nextcloud 35, the PHP version declared in `src/appinfo/info.xml`, enabled Shared App Core `>= 0.18.0-dev.2` and a reachable LINEA API with schema `1`. The web and cron containers must share the same `custom_apps` tree.

Extract the **full source ZIP** into a separate directory on the NAS. From the directory containing `install.sh`, `scripts/` and `src/`, run:

```sh
sudo sh install.sh
```

On success the installer reports the app name, previous and new versions, installed size and completion. On failure it displays the complete log. It checks requirements, stages code outside `custom_apps`, validates PHP, safely stops and restarts the collector, and creates missing tables or columns. It never renames, copies or deletes stored history. It keeps the previous code backup outside `custom_apps` and does not restart the Nextcloud container.

Enter the LINEA API URL on the **Settings** tab. Check the connection and the collector state on the **Collection** tab. From the source directory, inspect the collector with:

```sh
sudo sh scripts/collector.sh status
sudo sh scripts/collector.sh logs
```

If installation fails, retain the complete output. The installer attempts to restore the previous code and app state; it reports a backup path when it creates one. Do not manually delete database tables or scheduled jobs.

If live temperatures remain empty, this **read-only** command retrieves the sensor block through GridSight's configured LINEA connection. It prints neither the URL nor credentials and is useful for checking the actual API shape:

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

One baseline migration, `Version009000Date20261006090000`, creates the initial public schema. Nextcloud runs it during app installation/update. The installer additionally checks the schema when retrying an interrupted installation. Existing measurements are preserved. No manual `migrations:migrate` command is required.
