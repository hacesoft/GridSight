[🇨🇿 Česky](../cz/README.md) | [🇬🇧 **English**](README.md)

# GridSight

GridSight is a read-only Nextcloud 35 application for LINEA API. It displays live solar production, grid flow, battery and ESS state, SPOT prices, other devices and historical measurements. It does not send control commands to the plant.

Its technical app ID is `hc_gridsight`. It requires enabled Shared App Core `>= 0.18.0-dev.2` and a reachable, read-only LINEA API with schema `1`. Install from the **full source ZIP**, including `install.sh`, `scripts/` and `src/`.

1. [Installation and operation](01_INSTALLATION.md)
2. [Using GridSight](02_USAGE.md)
3. [Data and history](03_DATA_AND_HISTORY.md)
4. [Daily SPOT calculation](04_SPOT.md)
5. [Electricity analysis and ED.G import](05_ELECTRICITY_ANALYSIS.md)

## Languages

The application UI supports all 11 languages: Czech (`cs`), English (`en`), German (`de`), Spanish (`es`), French (`fr`), Italian (`it`), Dutch (`nl`), Polish (`pl`), Portuguese (`pt`), Slovak (`sk`) and Ukrainian (`uk`). Unsupported languages and individual missing translations fall back to English (EN). Every catalog has the same complete key set and preserves message placeholders. Text returned directly by the server or Core components follows those services’ localization. Guides and development documentation are available only in Czech and English.

Every future app update must audit the shared language set: `cs`, `en`, `de`, `es`, `fr`, `it`, `nl`, `pl`, `pt`, `sk`, `uk`. Add missing languages and translation keys, verify Nextcloud language selection, and document the languages actually supported. A catalog file alone does not prove translation completeness. User guides and development documentation are published only in Czech and English.

Translation audit for future releases (Python 3 and Node.js):

```sh
python3 scripts/check-languages.py
```
