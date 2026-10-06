[🇨🇿 Česky](README_CZ.md) | [🇬🇧 **English**](README.md)

# GridSight 0.9.1

GridSight is a Nextcloud 35 application for monitoring photovoltaic production, household consumption, battery storage, grid flows and electricity prices. It reads the LINEA API, stores measurement history and compares it with monthly EG.D reports. It does not control equipment or send commands to change ESS settings.


<img width="2134" height="1153" alt="GridSight dashboard" src="https://github.com/user-attachments/assets/6cae686b-1849-446d-9b7d-cb213ddca6f2" />

## Features

- LIVE: energy flows, battery, SPOT prices, forecasts and available devices.
- History: time charts, energy totals, date ranges and per-user series selection.
- ESS: states and settings published by LINEA.
- Collector and events: collector health and recorded state changes.
- Analysis: EG.D reports, contracts and prices, HDO schedules, purchase costs and comparison with a home without PV.
- Delta Green: manual sale statements compared with gross calculated SPOT revenue.

## Requirements and installation

Nextcloud 35, PHP 8.3 or newer as declared in `src/appinfo/info.xml`, enabled Shared App Core `>= 0.18.0-dev.2`, and a reachable LINEA API with schema `1`. The technical app ID is `hc_gridsight`.

The source package contains the application in `src/`, installer scripts and documentation. For the supported Synology/Docker environment, extract it and run from the project root:

```sh
sudo sh install.sh
```

Uninstall with `sudo sh uninstall.sh`; read the [installation guide](docs/en/01_INSTALLATION.md) first. One Nextcloud baseline migration creates the initial schema. The installer checks and adds missing tables/columns without deleting measurements.

The required dependency is available in the [Hacesoft Core repository](https://github.com/hacesoft/core). Install and enable it before installing GridSight.

## Documentation

- [Overview](docs/en/README.md)
- [Installation and operation](docs/en/01_INSTALLATION.md)
- [Usage](docs/en/02_USAGE.md)
- [Data and history](docs/en/03_DATA_AND_HISTORY.md)
- [SPOT calculations](docs/en/04_SPOT.md)
- [Electricity analysis](docs/en/05_ELECTRICITY_ANALYSIS.md)

## Source package

`sh build-release.sh` creates a complete source ZIP with `install.sh`, `uninstall.sh` and Czech/English documentation. Only GridSight is included. User measurements, contracts, credentials and imported XLSX are excluded.

License: AGPL-3.0-or-later; see [LICENSE](LICENSE).

## Languages

The application UI supports all 11 languages: Czech (`cs`), English (`en`), German (`de`), Spanish (`es`), French (`fr`), Italian (`it`), Dutch (`nl`), Polish (`pl`), Portuguese (`pt`), Slovak (`sk`) and Ukrainian (`uk`). Unsupported languages and individual missing translations fall back to English (EN). Every catalog has the same complete key set and preserves message placeholders. Text returned directly by the server or Core components follows those services’ localization. Guides and development documentation are available only in Czech and English.

Every future app update must audit the shared language set: `cs`, `en`, `de`, `es`, `fr`, `it`, `nl`, `pl`, `pt`, `sk`, `uk`. Add missing languages and translation keys, verify Nextcloud language selection, and document the languages actually supported. A catalog file alone does not prove translation completeness. User guides and development documentation are published only in Czech and English.

Translation audit for future releases (Python 3 and Node.js):

```sh
python3 scripts/check-languages.py
```
