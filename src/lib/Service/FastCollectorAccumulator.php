<?php

/**
 * Hacesoft GridSight – typová legenda proměnných (maďarská notace).
 * Historie: 2026-11-21 – bezpečné sjednocení interních lokálních proměnných.
 * Historie: 2026-11-22 – ověření typů, významových komentářů a shody názvů vlastností.
 * s – textový řetězec / identifikátor nebo cesta.
 * n – číslo (celé, desetinné, čas nebo jednotky).
 * a – pole / seznam.
 * o – instance objektu nebo výjimka.
 * v – smíšená nebo zatím neurčená hodnota.
 * Výjimka: názvy parametrů PHP metod a veřejných vlastností se nemění,
 * protože jsou součástí rozhraní Nextcloud, DI a pojmenovaných argumentů.
 */

declare(strict_types=1);

namespace OCA\HcGridSight\Service;

/**
 * In-memory time-weighted aggregation of fast LINEA telemetry.
 * Nothing is written per second; only a completed aggregate window is persisted.
 */
class FastCollectorAccumulator {
    private int $nSampleCount = 0;
    private float $nCoverageSec = 0.0;
    private float $nHdoOnSec = 0.0;
    private float $nHdoOffSec = 0.0;
    private ?array $vLastStatus = null;
    private ?int $nSourceTsMs = null;

    /** @var array<string,array{sum:float,min:?float,max:?float,weight:float}> */
    private array $aMetrics = [];

    private float $nPvMwh = 0.0;
    private float $nHouseMwh = 0.0;
    private float $nGridImportMwh = 0.0;
    private float $nGridExportMwh = 0.0;
    private float $nSaleMicroCzk = 0.0;
    private float $nUnpricedExportMwh = 0.0;
    private ?int $nSpotHourX10000 = null;
    private float $nBatteryChargeMwh = 0.0;
    private float $nBatteryDischargeMwh = 0.0;

    /**
     * Založí akumulátor pro určené časové okno a rozlišení.
     *
     * Vstupy: `$bucketTs` (int) – počátek časového bloku v unixových sekundách; `$resolutionSec` (int) – délka časového bloku v sekundách.
     * Výstup: `void` – nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací.
     *
     * @param int $bucketTs počátek časového bloku v unixových sekundách
     * @param int $resolutionSec délka časového bloku v sekundách
     * @return void nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací
     */
    public function __construct(private int $bucketTs, private int $resolutionSec = 300) {}

    /**
     * Vrátí počáteční čas aktuálně agregovaného pětiminutového okna.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `int` – počáteční unixový čas agregovaného okna (sekundy).
     * @return int počáteční unixový čas agregovaného okna (sekundy)
     */
    public function getBucketTs(): int { return $this->bucketTs; }
    /**
     * Vrátí délku agregovaného bloku v sekundách.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `int` – délka agregovaného okna v sekundách.
     * @return int délka agregovaného okna v sekundách
     */
    public function getResolutionSec(): int { return $this->resolutionSec; }
    /**
     * Vrátí počet platných vzorků zahrnutých do agregace.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `int` – počet zpracovaných měřicích vzorků.
     * @return int počet zpracovaných měřicích vzorků
     */
    public function getSampleCount(): int { return $this->nSampleCount; }
    /**
     * Vrátí dobu skutečného pokrytí bloku dostupnými měřeními.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `float` – součet platného časového pokrytí agregátu v sekundách.
     * @return float součet platného časového pokrytí agregátu v sekundách
     */
    public function getCoverageSec(): float { return $this->nCoverageSec; }
    /**
     * Vrátí naposledy přijatý stav LINEA.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `?array` – poslední přijatý stav LINEA, nebo null před prvním vzorkem.
     * @return ?array poslední přijatý stav LINEA, nebo null před prvním vzorkem
     */
    public function getLastStatus(): ?array { return $this->vLastStatus; }

    /**
     * Přidá nový časově vážený vzorek do právě otevřeného bloku.
     *
     * Vstupy: `$status` (array) – strukturovaná stavová odpověď LINEA; `$dtSec` (float) – doba reprezentovaná novým měřením v sekundách.
     * Výstup: `void` – připojí vzorek do časově váženého agregátu; nevrací hodnotu.
     *
     * @param array $status strukturovaná stavová odpověď LINEA
     * @param float $dtSec doba reprezentovaná novým měřením v sekundách
     * @return void připojí vzorek do časově váženého agregátu; nevrací hodnotu
     */
    public function add(array $status, float $dtSec): void {
        $this->vLastStatus = $status;
        $this->nSourceTsMs = $this->timestampMs($status['system']['sourceTimestamp'] ?? null);

        // A successful poll can still carry stale source data. Keep it for events/status,
        // but do not integrate stale electrical values into history.
        if (($status['system']['stale'] ?? false) === true) {
            return;
        }

        $dtSec = max(0.0, min(2.5, $dtSec));
        $this->nSampleCount++;
        if ($dtSec <= 0.0) {
            return;
        }
        $this->nCoverageSec += $dtSec;
        $vHdo = ShellyHdo::inputState($status);
        if ($vHdo === true) $this->nHdoOnSec += $dtSec;
        elseif ($vHdo === false) $this->nHdoOffSec += $dtSec;

        $vEnergy = $status['energy'] ?? [];
        $vPv = $vEnergy['pv'] ?? [];
        $vHouse = $vEnergy['house'] ?? [];
        $vGrid = $vEnergy['grid'] ?? [];
        $vBatt = $vEnergy['battery'] ?? [];
        $vTemperatures = TemperatureReadings::fromStatus($status);
        $vDecision = $status['ess']['decision'] ?? [];
        $vSpotBlock = $status['spot'] ?? [];
        $nPrice = (($vSpotBlock['available'] ?? true) === true)
            ? $this->number($vSpotBlock['currentPrice'] ?? ($vSpotBlock['data']['currentPrice'] ?? null)) : null;
        if ($nPrice !== null && is_finite($nPrice)) $this->nSpotHourX10000 = (int)round($nPrice * 10000);
        else $nPrice = null;

        $aValues = [
            'pv_w' => $vPv['powerW'] ?? null,
            'house_w' => $vHouse['powerW'] ?? null,
            'grid_w' => $vGrid['powerW'] ?? null,
            'batt_w' => $vBatt['powerW'] ?? null,
            'soc_x100' => $this->scale($vBatt['socPct'] ?? null, 100),
            // Independent sensors stay NULL when unavailable; never copy the
            // same rack reading into the battery curve.
            'batt_temp_x100' => $this->scale($vTemperatures['batteryTempC'], 100),
            'rack_temp_x100' => $this->scale($vTemperatures['rackTempC'], 100),
            'inv1_temp_x100' => $this->scale($vTemperatures['inverter1TempC'], 100),
            'inv2_temp_x100' => $this->scale($vTemperatures['inverter2TempC'], 100),
            'inv3_temp_x100' => $this->scale($vTemperatures['inverter3TempC'], 100),
            'grid_l1_w' => $vGrid['phases']['l1PowerW'] ?? null,
            'grid_l2_w' => $vGrid['phases']['l2PowerW'] ?? null,
            'grid_l3_w' => $vGrid['phases']['l3PowerW'] ?? null,
            'house_l1_w' => $vHouse['phases']['l1PowerW'] ?? null,
            'house_l2_w' => $vHouse['phases']['l2PowerW'] ?? null,
            'house_l3_w' => $vHouse['phases']['l3PowerW'] ?? null,
            'ess_grid_w' => $vDecision['gridPointW'] ?? null,
        ];
        foreach ($aValues as $sName => $vValue) {
            $this->addMetric($sName, $vValue, $dtSec);
        }

        $nPvW = $this->number($vPv['powerW'] ?? null);
        $nHouseW = $this->number($vHouse['powerW'] ?? null);
        $nGridW = $this->number($vGrid['powerW'] ?? null);
        $nBattW = $this->number($vBatt['powerW'] ?? null);

        $this->nPvMwh += $this->milliWh($nPvW, $dtSec);
        $this->nHouseMwh += $this->milliWh($nHouseW, $dtSec);
        if ($nGridW !== null) {
            if ($nGridW >= 0) $this->nGridImportMwh += $this->milliWh($nGridW, $dtSec);
            else {
                $nExportMwh = $this->milliWh(abs($nGridW), $dtSec);
                $this->nGridExportMwh += $nExportMwh;
                // mWh × Kč/kWh = micro-Kč. Keep negative SPOT prices intact.
                if ($nPrice === null) $this->nUnpricedExportMwh += $nExportMwh;
                else $this->nSaleMicroCzk += $nExportMwh * $nPrice;
            }
        }
        if ($nBattW !== null) {
            if ($nBattW >= 0) $this->nBatteryChargeMwh += $this->milliWh($nBattW, $dtSec);
            else $this->nBatteryDischargeMwh += $this->milliWh(abs($nBattW), $dtSec);
        }
    }

    /**
     * Uzavře agregaci a vrátí hodnoty připravené pro databázi.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `array` – databázový řádek výkonů, energií, HDO a hodinové SPOT ceny.
     * @return array databázový řádek výkonů, energií, HDO a hodinové SPOT ceny
     */
    public function finalize(): array {
        $vS = $this->vLastStatus ?? [];
        $vForecastBlock = $vS['forecast'] ?? [];
        $vForecast = (($vForecastBlock['available'] ?? true) === true) ? ($vForecastBlock['data'] ?? $vForecastBlock) : [];
        $vVrm = (($vS['vrm']['available'] ?? false) === true) ? ($vS['vrm']['data']['today'] ?? []) : [];

        $aRow = [
            'resolution' => $this->resolutionSec,
            'ts' => $this->bucketTs,
            'source_ts' => $this->nSourceTsMs,
            'sample_count' => $this->nSampleCount,
            'coverage_sec' => (int)round($this->nCoverageSec),
            'hdo_on_sec' => $this->nHdoOnSec + $this->nHdoOffSec > 0 ? (int)round($this->nHdoOnSec) : null,
            'hdo_off_sec' => $this->nHdoOnSec + $this->nHdoOffSec > 0 ? (int)round($this->nHdoOffSec) : null,
            // Metadata for the hourly SPOT table, never a 5-minute history column.
            'spot_hour_x10000' => $this->nSpotHourX10000,
            'forecast_pv_wh' => $this->kwhToWh($vForecast['solarYieldForecastKWh'] ?? null),
            'forecast_cons_wh' => $this->kwhToWh($vForecast['consumptionForecastKWh'] ?? null),
            'vrm_pv_wh' => $this->kwhToWh($vVrm['pvYieldKWh'] ?? null),
            'vrm_cons_wh' => $this->kwhToWh($vVrm['consumptionKWh'] ?? null),
            'vrm_import_wh' => $this->kwhToWh($vVrm['gridImportKWh'] ?? null),
            'vrm_export_wh' => $this->kwhToWh($vVrm['gridExportKWh'] ?? null),
            'batt_counter_charge_wh' => $this->kwhToWh($vVrm['batteryChargeKWh'] ?? null),
            'batt_counter_discharge_wh' => $this->kwhToWh($vVrm['batteryDischargeKWh'] ?? null),
            'pv_mwh' => (int)round($this->nPvMwh),
            'house_mwh' => (int)round($this->nHouseMwh),
            'grid_import_mwh' => (int)round($this->nGridImportMwh),
            'grid_export_mwh' => (int)round($this->nGridExportMwh),
            'sale_microczk' => (int)round($this->nSaleMicroCzk),
            'unpriced_export_mwh' => (int)round($this->nUnpricedExportMwh),
            'batt_charge_mwh' => (int)round($this->nBatteryChargeMwh),
            'batt_discharge_mwh' => (int)round($this->nBatteryDischargeMwh),
        ];

        foreach (['pv_w','house_w','grid_w','batt_w','soc_x100','batt_temp_x100','rack_temp_x100','inv1_temp_x100','inv2_temp_x100','inv3_temp_x100','grid_l1_w','grid_l2_w','grid_l3_w','house_l1_w','house_l2_w','house_l3_w','ess_grid_w'] as $sName) {
            $aRow[$sName] = $this->avg($sName);
        }
        foreach (['pv_w','house_w','grid_w','batt_w','soc_x100'] as $sName) {
            $sPrefix = match ($sName) {
                'batt_w' => 'batt',
                'soc_x100' => 'soc_x100',
                default => substr($sName, 0, -2),
            };
            if ($sName === 'soc_x100') {
                $aRow['soc_min_x100'] = $this->min($sName);
                $aRow['soc_max_x100'] = $this->max($sName);
            } else {
                $aRow[$sPrefix . '_min_w'] = $this->min($sName);
                $aRow[$sPrefix . '_max_w'] = $this->max($sName);
            }
        }
        return $aRow;
    }

    /**
     * Zahrne číselnou veličinu do časově váženého součtu.
     *
     * Vstupy: `$sName` (string) – název metriky, sloupce nebo položky podle metody; `$value` (mixed) – aktuální hodnota metriky nebo atributu; `$weight` (float) – časová váha vstupního vzorku v sekundách.
     * Výstup: `void` – aktualizuje časově vážené součty, minimum a maximum metriky; nevrací hodnotu.
     *
     * @param string $sName název metriky, sloupce nebo položky podle metody
     * @param mixed $value aktuální hodnota metriky nebo atributu
     * @param float $weight časová váha vstupního vzorku v sekundách
     * @return void aktualizuje časově vážené součty, minimum a maximum metriky; nevrací hodnotu
     */
    private function addMetric(string $name, mixed $value, float $weight): void {
        $v = $this->number($value);
        if ($v === null) return;
        if (!isset($this->aMetrics[$name])) {
            $this->aMetrics[$name] = ['sum'=>0.0,'min'=>null,'max'=>null,'weight'=>0.0];
        }
        $vM =& $this->aMetrics[$name];
        $vM['sum'] += $v * $weight;
        $vM['weight'] += $weight;
        $vM['min'] = $vM['min'] === null ? $v : min($vM['min'], $v);
        $vM['max'] = $vM['max'] === null ? $v : max($vM['max'], $v);
    }

    /**
     * Vrátí průměrnou hodnotu měřené veličiny.
     *
     * Vstupy: `$name` (string) – název metriky, sloupce nebo položky podle metody.
     * Výstup: `?int` – zaokrouhlený časově vážený průměr metriky nebo null bez dat.
     *
     * @param string $name název metriky, sloupce nebo položky podle metody
     * @return ?int zaokrouhlený časově vážený průměr metriky nebo null bez dat
     */
    private function avg(string $name): ?int {
        $vM = $this->aMetrics[$name] ?? null;
        return !$vM || $vM['weight'] <= 0.0 ? null : (int)round($vM['sum'] / $vM['weight']);
    }
    /**
     * Vrátí nejmenší dostupnou hodnotu.
     *
     * Vstupy: `$name` (string) – název metriky, sloupce nebo položky podle metody.
     * Výstup: `?int` – nejmenší platná hodnota metriky nebo null bez dat.
     *
     * @param string $name název metriky, sloupce nebo položky podle metody
     * @return ?int nejmenší platná hodnota metriky nebo null bez dat
     */
    private function min(string $name): ?int { $v = $this->aMetrics[$name]['min'] ?? null; return $v === null ? null : (int)round($v); }
    /**
     * Vrátí největší dostupnou hodnotu.
     *
     * Vstupy: `$name` (string) – název metriky, sloupce nebo položky podle metody.
     * Výstup: `?int` – nejvyšší platná hodnota metriky nebo null bez dat.
     *
     * @param string $name název metriky, sloupce nebo položky podle metody
     * @return ?int nejvyšší platná hodnota metriky nebo null bez dat
     */
    private function max(string $name): ?int { $v = $this->aMetrics[$name]['max'] ?? null; return $v === null ? null : (int)round($v); }
    /**
     * Normalizuje číselné hodnoty a odděluje nedostupnost od nuly.
     *
     * Vstupy: `$v` (mixed) – zdrojová hodnota z JSON/databáze s neověřeným typem.
     * Výstup: `?float` – platná hodnota float nebo null; rozlišuje nulu od chybějícího měření.
     *
     * @param mixed $v zdrojová hodnota z JSON/databáze s neověřeným typem
     * @return ?float platná hodnota float nebo null; rozlišuje nulu od chybějícího měření
     */
    private function number(mixed $v): ?float { return ($v === null || $v === '' || !is_numeric($v)) ? null : (float)$v; }
    /**
     * Převede naměřenou hodnotu do požadovaného celočíselného měřítka.
     *
     * Vstupy: `$v` (mixed) – zdrojová hodnota z JSON/databáze s neověřeným typem; `$scale` (int) – číselný koeficient pro celočíselné uložení.
     * Výstup: `?int` – hodnota v celočíselných jednotkách určeného měřítka, jinak null.
     *
     * @param mixed $v zdrojová hodnota z JSON/databáze s neověřeným typem
     * @param int $scale číselný koeficient pro celočíselné uložení
     * @return ?int hodnota v celočíselných jednotkách určeného měřítka, jinak null
     */
    private function scale(mixed $v, int $scale): ?int { $nN = $this->number($v); return $nN === null ? null : (int)round($nN * $scale); }
    /**
     * Převede energii z kWh na Wh.
     *
     * Vstupy: `$v` (mixed) – zdrojová hodnota z JSON/databáze s neověřeným typem.
     * Výstup: `?int` – energie převedená z kWh na zaokrouhlené Wh nebo null.
     *
     * @param mixed $v zdrojová hodnota z JSON/databáze s neověřeným typem
     * @return ?int energie převedená z kWh na zaokrouhlené Wh nebo null
     */
    private function kwhToWh(mixed $v): ?int { $nN = $this->number($v); return $nN === null ? null : (int)round($nN * 1000); }
    /**
     * Převede integrální energii na tisíciny Wh pro přesnější součty.
     *
     * Vstupy: `$w` (?float) – výkon ve wattech; `$seconds` (float) – perioda aktualizace v sekundách.
     * Výstup: `float` – energie v mWh vypočtená z výkonu (W) a doby (s).
     *
     * @param ?float $w výkon ve wattech
     * @param float $seconds perioda aktualizace v sekundách
     * @return float energie v mWh vypočtená z výkonu (W) a doby (s)
     */
    private function milliWh(?float $w, float $seconds): float { return $w === null ? 0.0 : $w * $seconds * 1000.0 / 3600.0; }
    /**
     * Převede čas ze zdrojové odpovědi na milisekundy.
     *
     * Vstupy: `$v` (mixed) – zdrojová hodnota z JSON/databáze s neověřeným typem.
     * Výstup: `?int` – unixové milisekundy převedené z časového řetězce nebo null.
     *
     * @param mixed $v zdrojová hodnota z JSON/databáze s neověřeným typem
     * @return ?int unixové milisekundy převedené z časového řetězce nebo null
     */
    private function timestampMs(mixed $v): ?int { if (!is_string($v) || trim($v)==='') return null; $nTs = strtotime($v); return $nTs === false ? null : $nTs * 1000; }
}
