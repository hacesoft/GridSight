[🇨🇿 Česky](../cz/08_API_A_DATOVA_POLE.md) | [🇬🇧 **English**](08_API_AND_DATA_FIELDS.md)

# GridSight 1.0.1 API and data field reference

This reference lists the actual Nextcloud routes and the LINEA fields consumed by GridSight. GridSight reads, but never controls, LINEA / FVE / ESS equipment. It is not a complete specification for upstream Node-RED. API routes are prefixed with `/apps/hc_gridsight` and require an authenticated Nextcloud application session; writing settings or shared analysis data requires appropriate authorization and Nextcloud request protection.

## Routes

| Method and route | Inputs | Output | Access |
|---|---|---|---|
| `GET /` | none | GridSight page | signed-in user |
| `GET /api/linea/health` | none | `{ok, data}` from upstream health | read |
| `GET /api/linea/status` | none | `{ok, data}`, includes `hcTemperatures` | read |
| `GET /api/history` | `range` or `from`, `to` in Unix seconds | `{ok, from, to, data}` | read |
| `GET /api/history/info` | none | `{ok, data}` collector diagnostics | read |
| `GET /api/history/daily` | none | `{ok, data}` local-day totals | read |
| `GET /api/analysis` | optional `period` (`YYYY-MM`) | `{ok, data}` billing analysis | read |
| `PUT /api/analysis/profile` | `profile` object | `{ok, profile}` | admin |
| `POST /api/analysis/report` | XLSX upload field `file` | `{ok, result}` | admin |
| `DELETE /api/analysis/report/{period}` | `YYYY-MM` | `{ok}` | admin |
| `POST /api/analysis/export` | `periods` array | ZIP download or JSON error | read |
| `POST /api/analysis/statement` | `statement` object | `{ok, statement}` | admin |
| `DELETE /api/analysis/statement/{id}` | statement ID | `{ok}` | admin |
| `GET /api/settings` | `namespace=hc_gridsight_main` | `{values}` | read |
| `PUT /api/settings` | `namespace`, `values` | updated `{values}` | personal settings; shared values admin |
| `POST /api/settings/test` | optional `baseUrl` | `{ok, health}` or error | admin |

LINEA availability problems on `health` and `status` return HTTP 503 with `{ "ok": false, "code": "linea_api_unavailable" }`; other upstream response failures may return HTTP 502 (`linea_api_error`). Invalid inputs produce HTTP 400 and unauthorized changes HTTP 403.

## LINEA status contract (schema 1)

GridSight calls upstream `/api/v1/health` and `/api/v1/status`. It checks `api.name` (`LINEA API`), `api.schema` (`1`) and `api.readOnly` (`true`). `available` marks a module as supplied; an absent or null field is not automatically zero. Measurements marked by `system.stale` must not be treated as fresh.

| Field | Unit / type | Meaning |
|---|---|---|
| `energy.pv.powerW`, `energy.pv.strings[].powerW` | W | total PV and individual MPPT power |
| `energy.pv.strings[].pvVoltageV`, `pvCurrentA`, `yieldTodayKWh` | V, A, kWh | MPPT voltage, current and today's yield |
| `energy.house.powerW`, `energy.house.phases` | W, object | total and per-phase house consumption |
| `energy.grid.powerW`, `energy.grid.phases` | W, object | positive grid power is import; negative is export |
| `energy.battery.socPct`, `powerW` | %, W | SOC; positive power is charging, negative is discharging |
| `energy.battery.currentA`, `voltageV`, `batteryLifeSocLimitPct` | A, V, % | battery readings and ESS minimum SOC |
| `ess.decision.gridPointW`, `predictionActive`, `exportAllowed`, `reason` | W, boolean, mixed | computed control decision from LINEA (display only) |
| `ess.switches` | object | modes such as `gridCharging`, `spotGridCharging`, `delayCharging`, `dynamicSocReserve`, `morningPeakBatterySales`, `eveningPeakBatterySales` |
| `ess.settings.balancingReserveW`, `setGridValueW`, `maxGridPointW` | W | reserve, configured grid point and related limits |
| `ess.settings.spotThresholdPrice`, `acceptablePriceGrid`, `chargingDurationGridH` | price, hours | strategy thresholds and cheapest-hour block duration |
| `ess.settings.morningSocSalesPct`, `eveningSocSalesPct`, `gridChargingSocPct` | % | SOC thresholds |
| `ess.settings.predictionThresholdKWh`, `ess.time.delayCharging` | kWh, time interval | prediction threshold and delayed charging window |
| `spot.currentPrice` | price | current spot price, possibly negative |
| `forecast.solarYieldForecastKWh`, `consumptionForecastKWh` | kWh | forecast production and consumption |
| `vrm.data.today.pvYieldKWh`, `consumptionKWh`, `gridImportKWh`, `gridExportKWh` | kWh | Victron totals |
| `vrm.data.today.batteryChargeKWh`, `batteryDischargeKWh` | kWh | cumulative since Node-RED restart, not necessarily daily |
| `temperatures.data.racks[]`, `inverters[]`, `other[]` | arrays | sensor groups containing `name` and `temperatureC` |
| `system.ageMs`, `system.stale` | ms, boolean | source age and staleness |
| `solar`, `weather`, `shelly`, `smoke`, `ups`, `climate` | objects | source-dependent conditions and devices |

GridSight adds `hcTemperatures` with `rackTempC`, `batteryTempC`, `inverter1TempC` through `inverter3TempC` (°C or null) and `sensors[]` (`name`, `temperatureC`, `kind`). It deliberately excludes the internal `ess.decision.pvSurplusW` field.

## Historical points and settings

`GET /api/history` returns `data.resolutionSec`, `data.points[]`, `data.summary`, `data.collector`, and `data.events`. Points contain `ts` (Unix seconds), `resolutionSec`, `samples`, `coverageSec`, `pvW`, `houseW`, `gridW`, `batteryW`, min/max powers, `socPct`, temperature values, `grid.l1W` to `l3W`, `house.l1W` to `l3W`, `spotPrice`, `gridImportKWh`, `gridExportKWh`, `houseKWh`, `pvKWh`, `forecastPvKWh`, `forecastConsumptionKWh`, and `saleCzk` (null if export is not fully priced).

`GET /api/history/info` provides collection status, retention and database diagnostics. `GET /api/history/daily` reports `day`, `timezone`, `hasData`, `historyEnabled`, `importKWh`, `exportKWh`, `saleCzk`, `pricedSaleCzk`, `unpricedExportKWh`, `coverageSec`, `bucketCount`, `updatedAt` and `purchaseCzk` (currently null; purchased electricity is evaluated separately by monthly analysis).

`GET/PUT /api/settings` uses `namespace=hc_gridsight_main` and `values` keys: `baseUrl` (admin-controlled), `refreshSeconds` (1–60 seconds), `historyEnabled` (admin), `canConfigure`, `history3dInvert`, and personal `chart_*` toggles. The analysis `profile` includes `ean`, `distributor`, `usage`, `meterType`, `distributionTariff`, `mainBreaker`, `contracts[]`, `pricePeriods[]`, and `hdoSchedules[]`. A Delta Green `statement` includes `from`, `to`, `amountCzk`, optional `saleEan`, `document`, and an `id` for editing. See [Electricity analysis](05_ELECTRICITY_ANALYSIS.md) for validation and accounting details.
