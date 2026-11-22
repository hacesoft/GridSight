[🇨🇿 **Česky**](08_API_A_DATOVA_POLE.md) | [🇬🇧 English](../en/08_API_AND_DATA_FIELDS.md)

# Referenční dokumentace API a datových položek GridSight 1.0.2

Tato stránka popisuje **skutečně implementované HTTP trasy GridSight** v Nextcloudu a význam polí čtených z externího LINEA API (schéma `1`). GridSight LINEA pouze čte; tato aplikace **nemění nastavení FVE ani ESS**. Kompletní upstream specifikace LINEA/Node-RED je samostatná; zde jsou přesně dokumentované položky, které GridSight používá, nikoli tvrzení o všech polích upstream služby.

## Přístup, konvence a chyby

Všechny níže uvedené trasy začínají `/apps/hc_gridsight`. Jsou určené pro přihlášeného uživatele Nextcloudu v kontextu aplikace. Zápisové operace vyžadují navíc standardní ochranu požadavků Nextcloudu a některé výslovně **správcovské oprávnění**. Nejde o volně přístupné veřejné API bez přihlášení.

Běžná odpověď je JSON `DataResponse`. `ok: true` označuje úspěch, `data` nese výsledek u stavových a historických endpointů. Chyby obsahují `error` nebo strojový `code`. Výpadek LINEA u `/api/linea/status` a `/api/linea/health` vrací HTTP **503**, `{ "ok": false, "code": "linea_api_unavailable" }`. Neplatná odpověď nebo jiná chyba může vrátit HTTP **502** a `code: linea_api_error`. HTTP **400** označuje neplatná vstupní data; **403** nedostatečná práva. Při obnově Node-RED klient sám opakuje čtení.

## Přehled cest

| Metoda a cesta | Vstup | Obsah výsledku | Oprávnění |
|---|---|---|---|
| `GET /` | bez parametrů | stránka GridSight | přihlášený uživatel |
| `GET /api/linea/health` | bez parametrů | `ok`, `data` z `/api/v1/health` | čtení |
| `GET /api/linea/status` | bez parametrů | `ok`, `data` z `/api/v1/status`, doplněné `hcTemperatures` | čtení |
| `GET /api/history` | `range`, volitelně `from` a `to` (Unix sekundy) | `ok`, `from`, `to`, `data` s body historie | čtení |
| `GET /api/history/info` | bez parametrů | `ok`, `data` s diagnostikou sběrače | čtení |
| `GET /api/history/daily` | bez parametrů | `ok`, `data` s denními kWh a SPOT tržbou | čtení |
| `GET /api/analysis` | `period` ve formátu `YYYY-MM`, volitelný | `ok`, `data` se souhrnem EG.D, smluv a cen | čtení |
| `PUT /api/analysis/profile` | `profile` – objekt odběrného místa, smluv, cen, HDO | `ok`, `profile` | správce |
| `POST /api/analysis/report` | soubor `file` (XLSX EG.D) | `ok`, `result` importu | správce |
| `DELETE /api/analysis/report/{period}` | měsíc `YYYY-MM` v URL | `ok` | správce |
| `POST /api/analysis/export` | `periods` – seznam měsíců | ZIP export reportů, případně JSON chyba | čtení |
| `POST /api/analysis/statement` | `statement` – vyúčtování Delta Green | `ok`, `statement` | správce |
| `DELETE /api/analysis/statement/{id}` | ID vyúčtování | `ok` | správce |
| `GET /api/settings` | `namespace=hc_gridsight_main` | `values` | čtení |
| `PUT /api/settings` | `namespace=hc_gridsight_main`, `values` | aktualizovaná odpověď `values` | běžná uživatelská nastavení; globální správce |
| `POST /api/settings/test` | volitelně `baseUrl` | `ok`, `health` nebo chyba | správce |

## LINEA API – vstupní struktura a význam položek

GridSight ze serverové části Nextcloudu čte `/api/v1/health` a `/api/v1/status` na správcem nastavené základní adrese. Ve stavovém JSON kontroluje `api.name = "LINEA API"`, `api.schema = 1` a `api.readOnly = true`. U datových modulů má `available` význam dostupnosti celého bloku; `null` nebo chybějící hodnota není automaticky nula.

| Cesta položky | Jednotka / typ | Význam |
|---|---|---|
| `energy.pv.powerW` | W | okamžitý celkový výkon FVE |
| `energy.pv.strings[].powerW` | W | výkon jednotlivého MPPT řetězce |
| `energy.pv.strings[].pvVoltageV` | V | napětí MPPT |
| `energy.pv.strings[].pvCurrentA` | A | proud MPPT (ve zdroji může být dopočítán) |
| `energy.pv.strings[].yieldTodayKWh` | kWh | dnešní výroba konkrétního MPPT |
| `energy.house.powerW` | W | aktuální spotřeba domu |
| `energy.house.phases` | objekt | zatížení L1, L2, L3 |
| `energy.grid.powerW` | W | kladné číslo = odběr; záporné = dodávka |
| `energy.grid.phases` | objekt | síťové výkony jednotlivých fází |
| `energy.battery.socPct` | % | stav nabití baterie |
| `energy.battery.powerW` | W | kladné číslo = nabíjení; záporné = vybíjení |
| `energy.battery.currentA`, `voltageV` | A, V | proud a napětí baterie |
| `energy.battery.batteryLifeSocLimitPct` | % | spodní mez SOC BatteryLife / ESS |
| `ess.decision.gridPointW` | W | výsledný požadovaný Grid Point vyhodnocený LINEA |
| `ess.decision.predictionActive`, `exportAllowed` | boolean | podmínky predikce a dovolení exportu |
| `ess.decision.reason` | objekt/text | zdůvodnění rozhodnutí ESS podle LINEA |
| `ess.switches` | objekt | stavy strategií ESS, například `spotGridCharging`, `gridCharging`, `delayCharging`, `dynamicSocReserve`, `morningPeakBatterySales`, `eveningPeakBatterySales` |
| `ess.settings.balancingReserveW`, `setGridValueW`, `maxGridPointW` | W | rezervní výkon, cílový odběr a limit některých režimů |
| `ess.settings.spotThresholdPrice`, `acceptablePriceGrid` | cena | cenové prahy pro strategie ESS |
| `ess.settings.morningSocSalesPct`, `eveningSocSalesPct`, `gridChargingSocPct` | % | prahy SOC pro příslušné režimy |
| `ess.settings.predictionThresholdKWh` | kWh | prahová předpověď výroby |
| `ess.settings.chargingDurationGridH` | hodiny | délka nejlevnějšího souvislého bloku nabíjení |
| `ess.time.delayCharging` | časové okno | odklad nabíjení baterie |
| `spot.currentPrice` | cena | aktuální SPOT cena, může být záporná |
| `forecast.solarYieldForecastKWh`, `consumptionForecastKWh` | kWh | předpověď výroby a spotřeby |
| `vrm.data.today.pvYieldKWh`, `consumptionKWh` | kWh | dostupné denní součty VRM |
| `vrm.data.today.gridImportKWh`, `gridExportKWh` | kWh | odběr a dodávka podle VRM |
| `vrm.data.today.batteryChargeKWh`, `batteryDischargeKWh` | kWh | kumulativní energie od restartu Node-RED; nejde nutně o kalendářní den |
| `temperatures.available` | boolean | přítomnost datového bloku teplot, ne důkaz čerstvosti čidla |
| `temperatures.data.racks[]`, `inverters[]`, `other[]` | seznam objektů | skupiny teplotních čidel |
| `temperatures.data.*[].name`, `temperatureC` | text, °C | název čidla a jeho teplota |
| `system.ageMs`, `system.stale` | ms, boolean | stáří hlavního měření a indikace zastaralých dat |
| `solar`, `weather.today`, `weather.rainProbabilityPct`, `weather.trend` | různé | informace o slunci a počasí dle dostupnosti zdroje |
| `shelly`, `smoke`, `ups`, `climate` | objekty | související zařízení a diagnostika předaná LINEA |

Odpověď GridSight `/api/linea/status` doplňuje `hcTemperatures`: `rackTempC`, `batteryTempC`, `inverter1TempC`, `inverter2TempC`, `inverter3TempC` (°C nebo `null`) a `sensors[]` s `name`, `temperatureC` a `kind`. Položka baterie nevzniká automaticky z libovolného racku; vyhodnocení čidel se řídí názvem nebo příslušným blokem. Interní `ess.decision.pvSurplusW` se z veřejné odpovědi GridSight záměrně vylučuje.

## Data historie – hlavní klíče

`GET /api/history` vrací `data.resolutionSec` (sekundy jednoho agregátu), `data.points[]`, `data.summary`, `data.collector` a `data.events`. Body používají `ts` (Unix sekundy), `resolutionSec`, `samples`, `coverageSec`, `pvW`, `houseW`, `gridW`, `batteryW`, minima/maxima výkonů, `socPct`, dostupné teploty `batteryTempC`, `rackTempC`, `inverter1TempC` až `inverter3TempC`, fáze `grid.l1W` až `l3W` a `house.l1W` až `l3W`, `spotPrice`, `gridImportKWh`, `gridExportKWh`, `houseKWh`, `pvKWh`, `forecastPvKWh`, `forecastConsumptionKWh` a `saleCzk` (může být `null`, pokud není oceněna celá dodávka).

`GET /api/history/info` vrací `data.enabled`, `data.lastCollectedAt`, `data.fiveMinuteBuckets`, `data.resolutionPlan` a `data.retention`; vnořené `data.fastCollector` obsahuje mimo jiné `online`, `writeHealthy`, `heartbeatAt`, `leaseUntil` a diagnostiku zápisů. `GET /api/history/daily` vrací `day`, `timezone`, `hasData`, `historyEnabled`, `importKWh`, `exportKWh`, `saleCzk`, `pricedSaleCzk`, `unpricedExportKWh`, `coverageSec`, `bucketCount`, `updatedAt` a `purchaseCzk` (v této verzi `null`; nákup se účtuje samostatnou měsíční analýzou).

## Nastavení a analýza – význam vstupních položek

`/api/settings` vyžaduje `namespace=hc_gridsight_main`. Odpověď `values` obsahuje `baseUrl` (adresa LINEA API; pouze správce smí měnit), `refreshSeconds` (uživatelský interval 1–60 s), `historyEnabled` (globální povolení sběru; pouze správce), `canConfigure` (příznak práv), `history3dInvert` (uživatelské obrácení svislé osy ovládání 3D) a přepínače `chart_pv`, `chart_house`, `chart_grid`, `chart_export`, `chart_battery`, `chart_soc`, `chart_spot`, `chart_batteryTemp`, `chart_rackTemp`, `chart_inverter1Temp`, `chart_inverter2Temp`, `chart_inverter3Temp`, `chart_forecastPv`, `chart_forecastHouse`.

`profile` obsahuje `ean` (18 číslic odběrného místa), `distributor`, `usage`, `meterType`, `distributionTariff`, `mainBreaker`, dále `contracts[]` (historie smluv), `pricePeriods[]` (cenové intervaly) a `hdoSchedules[]` (okna nízkého tarifu). Přesné podmínky importu a účtování jsou v [Analýze elektřiny](05_ANALYZA_ELEKTRINY.md). `statement` obsahuje `id` při úpravě existujícího záznamu, `from`, `to` (datum `YYYY-MM-DD`), `amountCzk`, volitelné `saleEan` a `document`. Archiv importovaných reportů a uložená historie LINEA zůstávají oddělené.
