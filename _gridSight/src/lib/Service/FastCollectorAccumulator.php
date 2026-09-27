<?php

declare(strict_types=1);

namespace OCA\HcGridSight\Service;

/**
 * In-memory time-weighted aggregation of fast LINEA telemetry.
 * Nothing is written per second; only a completed aggregate window is persisted.
 */
class FastCollectorAccumulator {
    private int $sampleCount = 0;
    private float $coverageSec = 0.0;
    private ?array $lastStatus = null;
    private ?int $sourceTsMs = null;

    /** @var array<string,array{sum:float,min:?float,max:?float,weight:float}> */
    private array $metrics = [];

    private float $pvMwh = 0.0;
    private float $houseMwh = 0.0;
    private float $gridImportMwh = 0.0;
    private float $gridExportMwh = 0.0;
    private float $saleMicroCzk = 0.0;
    private float $unpricedExportMwh = 0.0;
    private ?int $spotHourX10000 = null;
    private float $batteryChargeMwh = 0.0;
    private float $batteryDischargeMwh = 0.0;

    public function __construct(private int $bucketTs, private int $resolutionSec = 300) {}

    public function getBucketTs(): int { return $this->bucketTs; }
    public function getResolutionSec(): int { return $this->resolutionSec; }
    public function getSampleCount(): int { return $this->sampleCount; }
    public function getCoverageSec(): float { return $this->coverageSec; }
    public function getLastStatus(): ?array { return $this->lastStatus; }

    public function add(array $status, float $dtSec): void {
        $this->lastStatus = $status;
        $this->sourceTsMs = $this->timestampMs($status['system']['sourceTimestamp'] ?? null);

        // A successful poll can still carry stale source data. Keep it for events/status,
        // but do not integrate stale electrical values into history.
        if (($status['system']['stale'] ?? false) === true) {
            return;
        }

        $dtSec = max(0.0, min(2.5, $dtSec));
        $this->sampleCount++;
        if ($dtSec <= 0.0) {
            return;
        }
        $this->coverageSec += $dtSec;

        $e = $status['energy'] ?? [];
        $pv = $e['pv'] ?? [];
        $house = $e['house'] ?? [];
        $grid = $e['grid'] ?? [];
        $batt = $e['battery'] ?? [];
        $temperatures = TemperatureReadings::fromStatus($status);
        $decision = $status['ess']['decision'] ?? [];
        $spotBlock = $status['spot'] ?? [];
        $price = (($spotBlock['available'] ?? true) === true)
            ? $this->number($spotBlock['currentPrice'] ?? ($spotBlock['data']['currentPrice'] ?? null)) : null;
        if ($price !== null && is_finite($price)) $this->spotHourX10000 = (int)round($price * 10000);
        else $price = null;

        $values = [
            'pv_w' => $pv['powerW'] ?? null,
            'house_w' => $house['powerW'] ?? null,
            'grid_w' => $grid['powerW'] ?? null,
            'batt_w' => $batt['powerW'] ?? null,
            'soc_x100' => $this->scale($batt['socPct'] ?? null, 100),
            // Independent sensors stay NULL when unavailable; never copy the
            // same rack reading into the battery curve.
            'batt_temp_x100' => $this->scale($temperatures['batteryTempC'], 100),
            'rack_temp_x100' => $this->scale($temperatures['rackTempC'], 100),
            'inv1_temp_x100' => $this->scale($temperatures['inverter1TempC'], 100),
            'inv2_temp_x100' => $this->scale($temperatures['inverter2TempC'], 100),
            'inv3_temp_x100' => $this->scale($temperatures['inverter3TempC'], 100),
            'grid_l1_w' => $grid['phases']['l1PowerW'] ?? null,
            'grid_l2_w' => $grid['phases']['l2PowerW'] ?? null,
            'grid_l3_w' => $grid['phases']['l3PowerW'] ?? null,
            'house_l1_w' => $house['phases']['l1PowerW'] ?? null,
            'house_l2_w' => $house['phases']['l2PowerW'] ?? null,
            'house_l3_w' => $house['phases']['l3PowerW'] ?? null,
            'ess_grid_w' => $decision['gridPointW'] ?? null,
        ];
        foreach ($values as $name => $value) {
            $this->addMetric($name, $value, $dtSec);
        }

        $pvW = $this->number($pv['powerW'] ?? null);
        $houseW = $this->number($house['powerW'] ?? null);
        $gridW = $this->number($grid['powerW'] ?? null);
        $battW = $this->number($batt['powerW'] ?? null);

        $this->pvMwh += $this->milliWh($pvW, $dtSec);
        $this->houseMwh += $this->milliWh($houseW, $dtSec);
        if ($gridW !== null) {
            if ($gridW >= 0) $this->gridImportMwh += $this->milliWh($gridW, $dtSec);
            else {
                $exportMwh = $this->milliWh(abs($gridW), $dtSec);
                $this->gridExportMwh += $exportMwh;
                // mWh × Kč/kWh = micro-Kč. Keep negative SPOT prices intact.
                if ($price === null) $this->unpricedExportMwh += $exportMwh;
                else $this->saleMicroCzk += $exportMwh * $price;
            }
        }
        if ($battW !== null) {
            if ($battW >= 0) $this->batteryChargeMwh += $this->milliWh($battW, $dtSec);
            else $this->batteryDischargeMwh += $this->milliWh(abs($battW), $dtSec);
        }
    }

    public function finalize(): array {
        $s = $this->lastStatus ?? [];
        $forecastBlock = $s['forecast'] ?? [];
        $forecast = (($forecastBlock['available'] ?? true) === true) ? ($forecastBlock['data'] ?? $forecastBlock) : [];
        $vrm = (($s['vrm']['available'] ?? false) === true) ? ($s['vrm']['data']['today'] ?? []) : [];

        $row = [
            'resolution' => $this->resolutionSec,
            'ts' => $this->bucketTs,
            'source_ts' => $this->sourceTsMs,
            'sample_count' => $this->sampleCount,
            'coverage_sec' => (int)round($this->coverageSec),
            // Metadata for the hourly SPOT table, never a 5-minute history column.
            'spot_hour_x10000' => $this->spotHourX10000,
            'forecast_pv_wh' => $this->kwhToWh($forecast['solarYieldForecastKWh'] ?? null),
            'forecast_cons_wh' => $this->kwhToWh($forecast['consumptionForecastKWh'] ?? null),
            'vrm_pv_wh' => $this->kwhToWh($vrm['pvYieldKWh'] ?? null),
            'vrm_cons_wh' => $this->kwhToWh($vrm['consumptionKWh'] ?? null),
            'vrm_import_wh' => $this->kwhToWh($vrm['gridImportKWh'] ?? null),
            'vrm_export_wh' => $this->kwhToWh($vrm['gridExportKWh'] ?? null),
            'batt_counter_charge_wh' => $this->kwhToWh($vrm['batteryChargeKWh'] ?? null),
            'batt_counter_discharge_wh' => $this->kwhToWh($vrm['batteryDischargeKWh'] ?? null),
            'pv_mwh' => (int)round($this->pvMwh),
            'house_mwh' => (int)round($this->houseMwh),
            'grid_import_mwh' => (int)round($this->gridImportMwh),
            'grid_export_mwh' => (int)round($this->gridExportMwh),
            'sale_microczk' => (int)round($this->saleMicroCzk),
            'unpriced_export_mwh' => (int)round($this->unpricedExportMwh),
            'batt_charge_mwh' => (int)round($this->batteryChargeMwh),
            'batt_discharge_mwh' => (int)round($this->batteryDischargeMwh),
        ];

        foreach (['pv_w','house_w','grid_w','batt_w','soc_x100','batt_temp_x100','rack_temp_x100','inv1_temp_x100','inv2_temp_x100','inv3_temp_x100','grid_l1_w','grid_l2_w','grid_l3_w','house_l1_w','house_l2_w','house_l3_w','ess_grid_w'] as $name) {
            $row[$name] = $this->avg($name);
        }
        foreach (['pv_w','house_w','grid_w','batt_w','soc_x100'] as $name) {
            $prefix = match ($name) {
                'batt_w' => 'batt',
                'soc_x100' => 'soc_x100',
                default => substr($name, 0, -2),
            };
            if ($name === 'soc_x100') {
                $row['soc_min_x100'] = $this->min($name);
                $row['soc_max_x100'] = $this->max($name);
            } else {
                $row[$prefix . '_min_w'] = $this->min($name);
                $row[$prefix . '_max_w'] = $this->max($name);
            }
        }
        return $row;
    }

    private function addMetric(string $name, mixed $value, float $weight): void {
        $v = $this->number($value);
        if ($v === null) return;
        if (!isset($this->metrics[$name])) {
            $this->metrics[$name] = ['sum'=>0.0,'min'=>null,'max'=>null,'weight'=>0.0];
        }
        $m =& $this->metrics[$name];
        $m['sum'] += $v * $weight;
        $m['weight'] += $weight;
        $m['min'] = $m['min'] === null ? $v : min($m['min'], $v);
        $m['max'] = $m['max'] === null ? $v : max($m['max'], $v);
    }

    private function avg(string $name): ?int {
        $m = $this->metrics[$name] ?? null;
        return !$m || $m['weight'] <= 0.0 ? null : (int)round($m['sum'] / $m['weight']);
    }
    private function min(string $name): ?int { $v = $this->metrics[$name]['min'] ?? null; return $v === null ? null : (int)round($v); }
    private function max(string $name): ?int { $v = $this->metrics[$name]['max'] ?? null; return $v === null ? null : (int)round($v); }
    private function number(mixed $v): ?float { return ($v === null || $v === '' || !is_numeric($v)) ? null : (float)$v; }
    private function scale(mixed $v, int $scale): ?int { $n = $this->number($v); return $n === null ? null : (int)round($n * $scale); }
    private function kwhToWh(mixed $v): ?int { $n = $this->number($v); return $n === null ? null : (int)round($n * 1000); }
    private function milliWh(?float $w, float $seconds): float { return $w === null ? 0.0 : $w * $seconds * 1000.0 / 3600.0; }
    private function timestampMs(mixed $v): ?int { if (!is_string($v) || trim($v)==='') return null; $ts = strtotime($v); return $ts === false ? null : $ts * 1000; }
}
