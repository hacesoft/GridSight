[🇨🇿 Česky](README_CZ.md) | [🇬🇧 **English**](README.md)


# GridSight 1.0.0

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

Nextcloud 35, PHP 8.3 or newer as declared in `src/appinfo/info.xml`, enabled Shared App Core `>= 0.18.8`, and a reachable LINEA API with schema `1`. The technical app ID is `hc_gridsight`.

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



```sh
python3 scripts/check-languages.py
```

## Update checks

About checks both GridSight (`hacesoft/GridSight`) and the required Core (`hacesoft/core`). Checks read `src/appinfo/info.xml` on each repository’s default branch, validate the app ID and compare `<version>` with the installed version. Archive filenames in `release/` are only a fallback. Successful results are cached by Core for 6 hours; opening About triggers the check, with no background polling. See the [Core reference documentation](https://github.com/hacesoft/core/blob/main/docs/en/CORE_REFERENCE.md) for exact endpoints, selection rules and diagnostics.

## History: 2D and 3D

History has a 2D/3D switch sharing all period presets from one hour to all data. Select one 3D parameter; the graph updates automatically. Axes show Prague local time of day, date and value (W, %, °C or CZK/kWh). These are historical averages, not daily kWh totals. Long periods are reduced to at most 64 rows and 96 time columns without modifying stored data. Available samples in each cell are averaged; partial gaps within a populated cell are not separately visualized. Empty cells use black, distinct from measured zero. Daily aggregates cannot reconstruct missing intraday detail.

Customize low/high/gap colors. Smoothing defaults to zero and changes display only, leaving empty cells empty. Hover shows the original cell mean and a representative sample timestamp. Drag rotates; Shift changes the roll angle; right drag pans; wheel zooms. Touch supports one-finger rotation and two-finger zoom. Reset restores the view. Rendering uses local browser Canvas with no external chart service.

The graph uses the remaining viewport height. Settings include a per-user inverted vertical mouse and Up/Down arrow preference. Focus the graph to rotate with arrow keys; Shift controls roll. Home or the home icon restores the view.
