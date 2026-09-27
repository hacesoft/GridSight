[🇨🇿 Česky](../cz/03_DATA_A_HISTORIE.md) | [🇬🇧 **English**](03_DATA_AND_HISTORY.md)

# Data and history

GridSight reads live LINEA API data through Nextcloud; the browser never connects directly to Node-RED. A separate collector polls about once a second, accumulates samples in memory and stores each completed five-minute interval. Power, SOC and temperatures are time-weighted; energy is integrated. Stale source states do not contribute to energy.

| Logical table | Contents |
| --- | --- |
| `hc_gridsight_hist` | Five-minute readings and longer aggregates, including optional rack, battery and inverter temperatures |
| `hc_gridsight_events` | Significant state changes |
| `hc_gridsight_snapshots` | Short-lived technical snapshots |
| `hc_gridsight_runtime` | Collector state and last activity |
| `hc_gridsight_daily` | Daily import, export and gross sale amount |
| `hc_gridsight_spot_hour` | Hourly SPOT price |

Nextcloud automatically prepends its system database table prefix. With `dbtableprefix=oc_`, a physical table is named `oc_hc_gridsight_hist`. Do not change the system prefix. The installer adds missing schema, preserves existing rows and does not convert other tables.

History retention: five-minute data for 30 days, fifteen-minute data for 365 days and hourly data for 1,825 days; daily aggregates remain. Technical snapshots are kept for seven days. Aggregation and pruning happen automatically. Per-user chart visibility settings do not change collected measurements.

The documented LINEA API uses `temperatures: {available, data}` with arrays `temperatures.data.racks[]`, `inverters[]` and `other[]`; each sensor has `name` and `temperatureC`. The number of inverters is variable; GridSight maps up to three for history by 1–3/L1–L3 identifiers, falling back to source order. Live temperatures on the House consumption card come directly from the current API, while historical temperatures come from stored intervals. Schema `1` does not define a separate battery temperature. Its optional history column stays `NULL` unless the API provides an independent sensor; the rack value is never copied there. Older stored history is not filled in from current readings.

`temperatures.available: true` means that a module object has been stored, not that a device has just responded. LINEA also publishes `data.updatedAt` and `system.stale`, which matter when assessing freshness. GridSight does not add stale main status to its energy history.
