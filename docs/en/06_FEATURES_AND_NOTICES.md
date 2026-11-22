[🇨🇿 Česky](../cz/06_PREHLED_FUNKCI_A_OZNAMENI.md) | [🇬🇧 **English**](06_FEATURES_AND_NOTICES.md)

# GridSight 1.0.1 — complete feature and notification reference

GridSight is a **read-only** Nextcloud client for LINEA API; it does not send PV, ESS, inverter, battery or climate control commands. Availability of readings depends on the underlying API. An unavailable reading is shown as `—`, not silently converted to zero.

## LIVE dashboard

| Section | Data |
| --- | --- |
| Grid | Instantaneous grid exchange, L1–L3 phases, import and export; positive means import. |
| House | Current house load, phase powers, available inverter temperatures, daily consumption and forecast. |
| Solar production | PV power, MPPT strings, voltage/current, yield and daily estimate. |
| Battery | SOC, instantaneous power, voltage, current, BatteryLife limit and available VRM energy counters. Rack temperature is not battery-cell temperature. |
| SPOT and weather | SPOT price/threshold, hourly schedule, PV and house forecasts, daylight, rain and source weather summary. |
| Climate | Available Daikin/Onecta status, temperatures, setpoint, operating mode and diagnostic details. |
| UPS | UPS status and load from NUT with additional device diagnostics where provided. |
| Shelly | Available devices, inputs/outputs, smoke detector and HDO states; sleeping sensors may report infrequently. |

The layout follows Nextcloud light/dark mode and responds to the workspace width.

## ESS (display only)

The current LINEA states and settings are displayed with field help: Grid Point, SOC limits, charging reserves, forecast controls, Morning/Evening Peak, Delay Charging and Spot-Grid Charging when available. No on-screen value indicates a control request made by GridSight.

## History and charts

A separate collector polls LINEA approximately every second and stores completed time-weighted five-minute aggregates. The 2D charts support selectable series, timeframe presets and zoom. The 3D chart shows one selected parameter by local time of day and date; it supports rotation, zoom, pan, palette and optional display-only smoothing. Missing cells are distinct from zero. Retention: 5-minute 30 days, 15-minute 365 days, hourly 1825 days, daily long-term, diagnostic snapshots 7 days. Personal chart settings do not disable data collection.

## Collection and events

Collection/database shows process health, recent writes and diagnostic information. The event log displays up to 100 recorded state changes. A Nextcloud watchdog supervises the daemon, rather than replacing second-level sampling.

## SPOT and exports

The hourly price chart displays the configured price threshold, including negative prices. Estimated gross sale revenue multiplies measured export by the corresponding known SPOT price, not merely the last price. If pricing coverage is incomplete, the complete gross revenue is unavailable. This is not an actual invoice or net payment.

## Electricity analysis

- **EG.D reports:** multi-file monthly XLSX import with EAN, full-month and 15-minute interval validation, daily/quarter-hour details, ZIP export and private AppData storage of available originals.
- **Contracts and prices:** nonoverlapping contracts, effective-price history, high/low tariff, fixed fees, optional green surcharge and source references.
- **HDO:** manually entered schedules or adequately validated Shelly historical input. Incomplete tariff information is not assumed to be zero.
- **Monthly estimates:** distributor import/export, tariff split and estimated purchase costs; optional comparison with house consumption without PV when LINEA history provides adequate coverage.
- **Delta Green:** manually entered and editable sales settlement records and comparison with fully covered calculated SPOT revenue.

See [electricity analysis](05_ELECTRICITY_ANALYSIS.md) for formulas and validation details.

## Settings, roles and version checks

Administrators manage the LINEA URL, history collection and shared analysis records. Signed-in users can choose display series, personal 3D pointer inversion and the refresh interval. About checks published GridSight and Shared App Core versions without automatically installing updates.

## LINEA API availability notices

| Condition | UI behavior |
| --- | --- |
| Node-RED restart, cURL 52 empty reply or temporary HTTP 502/503/504 | Informational message: **“LINEA API is temporarily unavailable. Waiting for reconnection.”** |
| LINEA connection returns | The next successful refresh restores the LIVE values and clears the notice. |
| Incompatible LINEA contract or API schema | Genuine compatibility error, not hidden as a temporary outage. |
| Device reports no data | An unavailable value or a source-provided device status. |
| EG.D import or configuration failure | Separate actionable error; not obscured by the LINEA connection notice. |

The browser talks to Nextcloud rather than Node-RED directly. A temporary outage does not delete historical data or uploaded statements.

## Languages

The 11 required Hacesoft locales (`en`, `cs`, `de`, `es`, `fr`, `it`, `nl`, `pl`, `pt`, `sk`, `uk`) have matching complete translation key sets. An additional **Hungarian (`hu`)** catalog now contains all 594 UI translations, including the LINEA outage notice, configuration fields and technical explanations. Product names and machine-readable identifiers are kept as-is. Guides are in Czech and English.
