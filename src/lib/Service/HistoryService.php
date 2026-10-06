<?php

declare(strict_types=1);

namespace OCA\HcGridSight\Service;

use OCA\HcGridSight\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IConfig;

class HistoryService {
    public const RES_5M = 300;
    public const RES_15M = 900;
    public const RES_1H = 3600;
    public const RES_1D = 86400;

    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
    ) {}

    public function isEnabled(): bool {
        $old = $this->config->getAppValue('lineamonitor', 'history_enabled', '1');
        return $this->config->getAppValue(Application::APP_ID, 'history_enabled', $old) !== '0';
    }

    public function setEnabled(bool $enabled): void {
        $this->config->setAppValue(Application::APP_ID, 'history_enabled', $enabled ? '1' : '0');
    }

    public function getLastCollectedAt(): int {
        $old = $this->config->getAppValue('lineamonitor', 'history_last_collect', '0');
        return (int)$this->config->getAppValue(Application::APP_ID, 'history_last_collect', $old);
    }

    /**
     * Compatibility/manual fallback. The normal 0.2.6 path is the 1 s daemon,
     * which calls storeAggregateWindow() once per completed 5-minute window.
     */
    public function storeSnapshot(array $status, ?int $now = null): bool {
        $now ??= time();
        $bucket = intdiv($now, self::RES_5M) * self::RES_5M;
        if ($this->exists(self::RES_5M, $bucket)) return false;
        $acc = new FastCollectorAccumulator($bucket, self::RES_5M);
        $acc->add($status, 1.0);
        return $this->storeAggregateWindow($acc->finalize(), $status, $now);
    }

    public function storeAggregateWindow(array $row, array $lastStatus, ?int $now = null): bool {
        if (!$this->isEnabled()) return false;
        $now ??= time();
        $bucket = (int)($row['ts'] ?? 0);
        if ($bucket <= 0 || (int)($row['resolution'] ?? 0) !== self::RES_5M) {
            throw new \InvalidArgumentException('Invalid LINEA history aggregate window.');
        }
        if ($this->exists(self::RES_5M, $bucket)) {
            // A previous attempt can have written the bucket but failed before
            // updating the daily total. Rebuild it without counting twice.
            $this->refreshDailyForBucket($bucket, $now);
            $this->storeHourlySpot($bucket, $row['spot_hour_x10000'] ?? null, $now);
            $this->config->setAppValue(Application::APP_ID, 'history_last_collect', (string)$now);
            return false;
        }
        $this->insertHistoryRow($row);
        $this->storeHourlySpot($bucket, $row['spot_hour_x10000'] ?? null, $now);
        $this->refreshDailyForBucket($bucket, $now);
        if ($this->localDay($bucket - self::RES_5M) !== $this->localDay($bucket)) {
            $this->refreshDailyForBucket($bucket - self::RES_5M, $now);
        }
        $this->storeRawSnapshot($lastStatus, $bucket);
        $this->buildAggregates($now);
        $this->prune($now);
        $this->config->setAppValue(Application::APP_ID, 'history_last_collect', (string)$now);
        $this->updateRuntime(['last_flush_ts'=>$now, 'last_error'=>null]);
        return true;
    }

    public function trackEventsFromStatus(array $status, ?int $now = null): void {
        $this->trackEvents($status, $now ?? time());
    }

    public function earliestTimestamp(): int {
        $qb=$this->db->getQueryBuilder();
        $first=$qb->select('ts')->from('hc_gridsight_hist')->orderBy('ts','ASC')->setMaxResults(1)->executeQuery()->fetchOne();
        return $first===false || $first===null ? 0 : (int)$first;
    }

    public function getHistory(int $from, int $to): array {
        $from = max(0, $from);
        $to = max($from + 1, $to);
        $span = $to - $from;
        $preferred = $span <= 7 * 86400 ? self::RES_5M
            : ($span <= 90 * 86400 ? self::RES_15M
            : ($span <= 5 * 365 * 86400 ? self::RES_1H : self::RES_1D));
        $rows = $this->queryRows($preferred, $from, $to);
        $resolution = $preferred;
        if ($rows === []) {
            foreach ([self::RES_5M, self::RES_15M, self::RES_1H, self::RES_1D] as $fallback) {
                $rows = $this->queryRows($fallback, $from, $to);
                if ($rows !== []) { $resolution = $fallback; break; }
            }
        }
        $rows = $this->attachHourlySpot($rows);
        return [
            'resolutionSec' => $resolution,
            'points' => array_map(fn(array $r): array => $this->publicRow($r), $rows),
            'summary' => $this->summary($rows),
            'collector' => $this->info(),
            // The event log is independent of the selected chart range.
            'events' => $this->getEvents(0, time(), 100),
        ];
    }

    private function storeHourlySpot(int $bucket, mixed $priceX10000, int $now): void {
        if ($priceX10000 === null) return;
        $hour = intdiv($bucket, self::RES_1H) * self::RES_1H;
        $price = (int)$priceX10000;
        $select = $this->db->getQueryBuilder();
        $select->select('price_x10000')->from('hc_gridsight_spot_hour')
            ->where($select->expr()->eq('hour_ts', $select->createNamedParameter($hour, IQueryBuilder::PARAM_INT)));
        $previous = $select->executeQuery()->fetchOne();
        if ($previous !== false && (int)$previous === $price) return;
        $qb = $this->db->getQueryBuilder();
        if ($previous === false) {
            $qb->insert('hc_gridsight_spot_hour')
                ->setValue('hour_ts', $qb->createNamedParameter($hour, IQueryBuilder::PARAM_INT))
                ->setValue('price_x10000', $qb->createNamedParameter($price, IQueryBuilder::PARAM_INT))
                ->setValue('recorded_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
        } else {
            $qb->update('hc_gridsight_spot_hour')
                ->set('price_x10000', $qb->createNamedParameter($price, IQueryBuilder::PARAM_INT))
                ->set('recorded_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('hour_ts', $qb->createNamedParameter($hour, IQueryBuilder::PARAM_INT)));
        }
        $qb->executeStatement();
    }

    /** Map one stored hourly price to chart points without copying it into history. */
    private function attachHourlySpot(array $rows): array {
        if ($rows === []) return $rows;
        $firstHour = intdiv((int)$rows[0]['ts'], self::RES_1H) * self::RES_1H;
        $lastHour = intdiv((int)$rows[count($rows)-1]['ts'], self::RES_1H) * self::RES_1H;
        $qb = $this->db->getQueryBuilder();
        $qb->select('hour_ts', 'price_x10000')->from('hc_gridsight_spot_hour')
            ->where($qb->expr()->gte('hour_ts', $qb->createNamedParameter($firstHour, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lte('hour_ts', $qb->createNamedParameter($lastHour, IQueryBuilder::PARAM_INT)));
        $prices = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $priceRow) {
            $prices[(int)$priceRow['hour_ts']] = (int)$priceRow['price_x10000'];
        }
        foreach ($rows as &$row) {
            if (($row['spot_x10000'] ?? null) === null) {
                $hour = intdiv((int)$row['ts'], self::RES_1H) * self::RES_1H;
                $row['spot_x10000'] = $prices[$hour] ?? null;
            }
        }
        unset($row);
        return $rows;
    }

    /** Today's local calendar day, based on completed five-minute intervals. */
    public function todayAccounting(): array {
        $day = $this->localDay(time());
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('hc_gridsight_daily')
            ->where($qb->expr()->eq('day', $qb->createNamedParameter($day, IQueryBuilder::PARAM_STR)));
        $stored = $qb->executeQuery()->fetchAssociative();
        // Existing five-minute rows from before this release can be shown as soon
        // as the schema is installed, even before the collector's first new flush.
        $totals = $stored ?: $this->calculateDaily($day);
        return [
            'day' => $day,
            'timezone' => 'Europe/Prague',
            'hasData' => $totals !== null,
            'historyEnabled' => $this->isEnabled(),
            'importKWh' => $totals === null ? null : (int)$totals['import_mwh'] / 1000000,
            'exportKWh' => $totals === null ? null : (int)$totals['export_mwh'] / 1000000,
            'saleCzk' => $totals === null || (int)$totals['unpriced_export_mwh'] > 0
                ? null : (int)$totals['sale_microczk'] / 1000000,
            'pricedSaleCzk' => $totals === null ? null : (int)$totals['sale_microczk'] / 1000000,
            'unpricedExportKWh' => $totals === null ? null : (int)$totals['unpriced_export_mwh'] / 1000000,
            'coverageSec' => $totals === null ? 0 : (int)$totals['coverage_sec'],
            'bucketCount' => $totals === null ? 0 : (int)$totals['bucket_count'],
            'updatedAt' => $totals === null ? null : (int)$totals['updated_at'],
            'purchaseCzk' => null, // Waiting for Shelly tariff state and purchase tariffs.
        ];
    }

    private function localDay(int $timestamp): string {
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d');
    }

    /** Reconstruct from raw 5m buckets; never sum a previously stored daily row. */
    private function calculateDaily(string $day): ?array {
        $start = new \DateTimeImmutable($day . ' 00:00:00', new \DateTimeZone('Europe/Prague'));
        $from = $start->getTimestamp();
        $to = $start->modify('+1 day')->getTimestamp();
        $rows = $this->queryRows(self::RES_5M, $from, $to - 1);
        if ($rows === []) return null;
        $totals = ['day'=>$day, 'export_mwh'=>0, 'import_mwh'=>0,
            'sale_microczk'=>0, 'unpriced_export_mwh'=>0,
            'coverage_sec'=>0, 'bucket_count'=>count($rows), 'updated_at'=>time()];
        foreach ($rows as $row) {
            $totals['export_mwh'] += (int)$row['grid_export_mwh'];
            $totals['import_mwh'] += (int)$row['grid_import_mwh'];
            $totals['coverage_sec'] += (int)$row['coverage_sec'];
            [$sale, $unpriced] = $this->bucketSale($row);
            $totals['sale_microczk'] += $sale;
            $totals['unpriced_export_mwh'] += $unpriced;
        }
        return $totals;
    }

    /** Legacy 5m buckets used the SPOT price stored with that same bucket. */
    private function bucketSale(array $row): array {
        if (($row['sale_microczk'] ?? null) !== null) {
            return [(int)$row['sale_microczk'], (int)($row['unpriced_export_mwh'] ?? 0)];
        }
        $export = (int)($row['grid_export_mwh'] ?? 0);
        if ($export <= 0) return [0, 0];
        if (($row['spot_x10000'] ?? null) === null || (int)$row['resolution'] !== self::RES_5M) {
            return [0, $export];
        }
        return [(int)round($export * (int)$row['spot_x10000'] / 10000), 0];
    }

    private function refreshDailyForBucket(int $bucket, int $now): void {
        $totals = $this->calculateDaily($this->localDay($bucket));
        if ($totals === null) return;
        $totals['updated_at'] = $now;
        $select = $this->db->getQueryBuilder();
        $select->select('day')->from('hc_gridsight_daily')
            ->where($select->expr()->eq('day', $select->createNamedParameter($totals['day'], IQueryBuilder::PARAM_STR)));
        $exists = $select->executeQuery()->fetchOne() !== false;
        $qb = $this->db->getQueryBuilder();
        if ($exists) {
            $qb->update('hc_gridsight_daily')
                ->where($qb->expr()->eq('day', $qb->createNamedParameter($totals['day'], IQueryBuilder::PARAM_STR)));
        } else {
            $qb->insert('hc_gridsight_daily')
                ->setValue('day', $qb->createNamedParameter($totals['day'], IQueryBuilder::PARAM_STR));
        }
        foreach ($totals as $column => $value) {
            if ($column === 'day') continue;
            $parameter = $qb->createNamedParameter($value, IQueryBuilder::PARAM_INT);
            if ($exists) $qb->set($column, $parameter);
            else $qb->setValue($column, $parameter);
        }
        $qb->executeStatement();
    }

    public function info(): array {
        $runtime = $this->runtime();
        $now = time();
        $heartbeat = (int)($runtime['heartbeat_ts'] ?? 0);
        $leaseUntil = (int)($runtime['lease_until'] ?? 0);
        $startedAt = (int)($runtime['started_ts'] ?? 0);
        $lastFlushAt = (int)($runtime['last_flush_ts'] ?? 0);
        $online = $heartbeat > 0 && ($now - $heartbeat) <= 15;
        // During the first ~11 minutes there may legitimately be no completed bucket yet.
        // After that an online collector without a recent DB flush must not be presented as healthy.
        $writeHealthy = !$online || $startedAt <= 0 || ($now - $startedAt) <= 660
            || ($lastFlushAt > 0 && ($now - $lastFlushAt) <= 660);
        return [
            'enabled' => $this->isEnabled(),
            'lastCollectedAt' => $this->getLastCollectedAt(),
            'fiveMinuteBuckets' => $this->countRows(self::RES_5M),
            'rawSamples' => $this->countRows(self::RES_5M), // backward-compatible UI key
            'resolutionPlan' => [300, 900, 3600, 86400],
            'retention' => ['5mDays'=>30, '15mDays'=>365, 'hourlyDays'=>1825, 'dailyDays'=>null, 'snapshotDays'=>7],
            'database' => $this->databaseSizeInfo($now),
            'fastCollector' => [
                'online' => $online,
                'writeHealthy' => $writeHealthy,
                'writeProblem' => $online && !$writeHealthy,
                'heartbeatAt' => $heartbeat,
                'leaseUntil' => $leaseUntil,
                'owner' => (string)($runtime['collector_owner'] ?? ''),
                'startedAt' => $startedAt,
                'lastPollAt' => (int)($runtime['last_poll_ts'] ?? 0),
                'lastFlushAt' => $lastFlushAt,
                'pollOk' => (int)($runtime['poll_ok'] ?? 0),
                'pollErrors' => (int)($runtime['poll_errors'] ?? 0),
                'bufferCount' => (int)($runtime['buffer_count'] ?? 0),
                'currentBucket' => (int)($runtime['current_bucket'] ?? 0),
                'lastError' => $runtime['last_error'] ?? null,
                'pollIntervalSec' => 1,
                'databaseIntervalSec' => 300,
                'memoryBufferSec' => 3600,
            ],
        ];
    }

    /** Cross-container lease. Only one collector may own it at a time. */
    public function acquireCollectorLease(string $owner, int $now, int $leaseSec = 20): bool {
        $this->ensureRuntimeRow();
        $qb = $this->db->getQueryBuilder();
        $qb->update('hc_gridsight_runtime')
            ->set('collector_owner', $qb->createNamedParameter($owner, IQueryBuilder::PARAM_STR))
            ->set('lease_until', $qb->createNamedParameter($now + $leaseSec, IQueryBuilder::PARAM_INT))
            ->set('heartbeat_ts', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('started_ts', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->lt('lease_until', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)),
                $qb->expr()->eq('collector_owner', $qb->createNamedParameter($owner, IQueryBuilder::PARAM_STR))
            ));
        return $qb->executeStatement() === 1;
    }

    public function renewCollectorLease(string $owner, int $now, array $runtime = [], int $leaseSec = 20): bool {
        $this->ensureRuntimeRow();
        $qb = $this->db->getQueryBuilder();
        $qb->update('hc_gridsight_runtime')
            ->set('lease_until', $qb->createNamedParameter($now + $leaseSec, IQueryBuilder::PARAM_INT))
            ->set('heartbeat_ts', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('collector_owner', $qb->createNamedParameter($owner, IQueryBuilder::PARAM_STR)));
        foreach ($runtime as $key => $value) {
            if (!in_array($key, ['last_poll_ts','last_flush_ts','poll_ok','poll_errors','buffer_count','current_bucket','last_error'], true)) continue;
            $type = $key === 'last_error' ? IQueryBuilder::PARAM_STR : IQueryBuilder::PARAM_INT;
            $qb->set($key, $value === null ? $qb->createNamedParameter(null) : $qb->createNamedParameter($value, $type));
        }
        return $qb->executeStatement() === 1;
    }

    public function releaseCollectorLease(string $owner): void {
        $this->ensureRuntimeRow();
        $qb = $this->db->getQueryBuilder();
        $qb->update('hc_gridsight_runtime')
            ->set('lease_until', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('heartbeat_ts', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('collector_owner', $qb->createNamedParameter($owner, IQueryBuilder::PARAM_STR)))
            ->executeStatement();
    }

    public function updateRuntime(array $values): void {
        $this->ensureRuntimeRow();
        if ($values === []) return;
        $qb = $this->db->getQueryBuilder();
        $qb->update('hc_gridsight_runtime')->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
        foreach ($values as $key => $value) {
            if (!in_array($key, ['last_poll_ts','last_flush_ts','poll_ok','poll_errors','buffer_count','current_bucket','last_error'], true)) continue;
            $type = $key === 'last_error' ? IQueryBuilder::PARAM_STR : IQueryBuilder::PARAM_INT;
            $qb->set($key, $value === null ? $qb->createNamedParameter(null) : $qb->createNamedParameter($value, $type));
        }
        $qb->executeStatement();
    }

    private function insertHistoryRow(array $row): void {
        $allowed = [
            'resolution','ts','source_ts','sample_count','coverage_sec',
            'pv_w','house_w','grid_w','batt_w','soc_x100','batt_temp_x100','rack_temp_x100','inv1_temp_x100','inv2_temp_x100','inv3_temp_x100',
            'grid_l1_w','grid_l2_w','grid_l3_w','house_l1_w','house_l2_w','house_l3_w','ess_grid_w',
            'pv_min_w','pv_max_w','house_min_w','house_max_w','grid_min_w','grid_max_w','batt_min_w','batt_max_w','soc_min_x100','soc_max_x100',
            'forecast_pv_wh','forecast_cons_wh','vrm_pv_wh','vrm_cons_wh','vrm_import_wh','vrm_export_wh','batt_counter_charge_wh','batt_counter_discharge_wh',
            'pv_mwh','house_mwh','grid_import_mwh','grid_export_mwh','batt_charge_mwh','batt_discharge_mwh',
            'sale_microczk','unpriced_export_mwh','hdo_on_sec','hdo_off_sec'
        ];
        $qb = $this->db->getQueryBuilder();
        $qb->insert('hc_gridsight_hist');
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $row)) continue;
            $value = $row[$key];
            $qb->setValue($key, $value === null ? $qb->createNamedParameter(null) : $qb->createNamedParameter((int)$value, IQueryBuilder::PARAM_INT));
        }
        $qb->executeStatement();
    }

    private function buildAggregates(int $now): void {
        $this->aggregateCompleted(self::RES_5M, self::RES_15M, $now);
        $this->aggregateCompleted(self::RES_15M, self::RES_1H, $now);
        $this->aggregateCompleted(self::RES_1H, self::RES_1D, $now);
    }

    private function aggregateCompleted(int $sourceResolution, int $targetResolution, int $now): void {
        $bucket = intdiv($now, $targetResolution) * $targetResolution - $targetResolution;
        if ($bucket < 0 || $this->exists($targetResolution, $bucket)) return;
        $rows = $this->queryRows($sourceResolution, $bucket, $bucket + $targetResolution - 1);
        if ($rows === []) return;

        $avgCols = ['pv_w','house_w','grid_w','batt_w','soc_x100','batt_temp_x100','rack_temp_x100','inv1_temp_x100','inv2_temp_x100','inv3_temp_x100','grid_l1_w','grid_l2_w','grid_l3_w','house_l1_w','house_l2_w','house_l3_w','ess_grid_w'];
        $lastCols = ['forecast_pv_wh','forecast_cons_wh','vrm_pv_wh','vrm_import_wh','vrm_cons_wh','vrm_export_wh','batt_counter_charge_wh','batt_counter_discharge_wh'];
        $sumCols = ['coverage_sec','sample_count','pv_mwh','house_mwh','grid_import_mwh','grid_export_mwh','batt_charge_mwh','batt_discharge_mwh'];
        $minCols = ['pv_min_w','house_min_w','grid_min_w','batt_min_w','soc_min_x100'];
        $maxCols = ['pv_max_w','house_max_w','grid_max_w','batt_max_w','soc_max_x100'];
        $out = ['resolution'=>$targetResolution,'ts'=>$bucket,'source_ts'=>null];
        foreach ($avgCols as $col) $out[$col] = $this->weightedAverage($rows, $col, 'coverage_sec');
        foreach ($lastCols as $col) $out[$col] = $this->lastNonNull($rows, $col);
        foreach ($sumCols as $col) $out[$col] = array_sum(array_map('intval', array_column($rows, $col)));
        foreach (['hdo_on_sec','hdo_off_sec'] as $col) {
            $observed = array_filter($rows, static fn(array $row): bool => ($row[$col] ?? null) !== null);
            $out[$col] = $observed === [] ? null : array_sum(array_map('intval', array_column($observed, $col)));
        }
        $sales = array_map(fn(array $r): array => $this->bucketSale($r), $rows);
        $out['sale_microczk'] = array_sum(array_column($sales, 0));
        $out['unpriced_export_mwh'] = array_sum(array_column($sales, 1));
        foreach ($minCols as $col) $out[$col] = $this->minNonNull($rows, $col);
        foreach ($maxCols as $col) $out[$col] = $this->maxNonNull($rows, $col);
        $this->insertHistoryRow($out);
    }

    private function weightedAverage(array $rows, string $col, string $weightCol): ?int {
        $sum = 0.0; $weight = 0;
        foreach ($rows as $r) {
            if (($r[$col] ?? null) === null) continue;
            $w = max(1, (int)($r[$weightCol] ?? 0));
            $sum += (int)$r[$col] * $w; $weight += $w;
        }
        return $weight <= 0 ? null : (int)round($sum / $weight);
    }
    private function minNonNull(array $rows, string $col): ?int { $v=[]; foreach($rows as $r) if(($r[$col]??null)!==null)$v[]=(int)$r[$col]; return $v===[]?null:min($v); }
    private function maxNonNull(array $rows, string $col): ?int { $v=[]; foreach($rows as $r) if(($r[$col]??null)!==null)$v[]=(int)$r[$col]; return $v===[]?null:max($v); }
    private function lastNonNull(array $rows, string $col): ?int { for($i=count($rows)-1;$i>=0;$i--) if(($rows[$i][$col]??null)!==null)return (int)$rows[$i][$col]; return null; }

    private function queryRows(int $resolution, int $from, int $to): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('hc_gridsight_hist')
            ->where($qb->expr()->eq('resolution', $qb->createNamedParameter($resolution, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('ts', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lte('ts', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)))
            ->orderBy('ts', 'ASC');
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /** Complete quarter-hour Shelly states; legacy diagnostic snapshots are temporary fallback evidence. */
    public function hdoEvidence(int $from, int $to, bool $includeSnapshots = true): array {
        $from = intdiv($from, self::RES_15M) * self::RES_15M;
        $to = intdiv($to + self::RES_15M - 1, self::RES_15M) * self::RES_15M;
        $five = []; $fifteen = [];
        foreach ([self::RES_5M, self::RES_15M] as $resolution) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('ts','hdo_on_sec','hdo_off_sec')->from('hc_gridsight_hist')
                ->where($qb->expr()->eq('resolution',$qb->createNamedParameter($resolution,IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->gte('ts',$qb->createNamedParameter($from,IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->lt('ts',$qb->createNamedParameter($to,IQueryBuilder::PARAM_INT)));
            foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
                if ($row['hdo_on_sec'] === null || $row['hdo_off_sec'] === null) continue;
                $ts = (int)$row['ts'];
                if ($resolution === self::RES_5M) $five[$ts] = [(int)$row['hdo_on_sec'],(int)$row['hdo_off_sec'],'shelly'];
                else $fifteen[$ts] = [(int)$row['hdo_on_sec'],(int)$row['hdo_off_sec'],'shelly'];
            }
        }
        // Old snapshots contain only one reading at the end of a five-minute
        // window. Never invent missing windows or extend a state across gaps.
        if ($includeSnapshots) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('ts','payload')->from('hc_gridsight_snapshots')
                ->where($qb->expr()->gte('ts',$qb->createNamedParameter($from,IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->lt('ts',$qb->createNamedParameter($to,IQueryBuilder::PARAM_INT)));
            foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
                $ts = (int)$row['ts'];
                if (isset($five[$ts])) continue;
                $status = json_decode((string)$row['payload'],true);
                $state = is_array($status) ? ShellyHdo::inputState($status, $ts + 300) : null;
                if ($state !== null) $five[$ts] = [$state ? 300 : 0,$state ? 0 : 300,'snapshot'];
            }
        }
        $result = [];
        for ($quarter = $from; $quarter < $to; $quarter += self::RES_15M) {
            $slots = [];
            for ($ts=$quarter; $ts<$quarter+self::RES_15M; $ts+=self::RES_5M) {
                if (isset($five[$ts])) $slots[] = $five[$ts];
            }
            $candidate = count($slots) === 3 ? [array_sum(array_column($slots,0)),array_sum(array_column($slots,1)),
                in_array('snapshot',array_column($slots,2),true) ? 'snapshot' : 'shelly'] : ($fifteen[$quarter] ?? null);
            if ($candidate === null) continue;
            [$on,$off,$source] = $candidate;
            if ($on+$off < 810 || max($on,$off) < ($on+$off)*0.98) continue;
            $result[$quarter] = ['state'=>$on>$off,'source'=>$source];
        }
        return $result;
    }

    private function publicRow(array $r): array {
        return [
            'ts'=>(int)$r['ts'], 'resolutionSec'=>(int)$r['resolution'], 'samples'=>(int)$r['sample_count'], 'coverageSec'=>(int)$r['coverage_sec'],
            'pvW'=>$this->dbIntOrNull($r['pv_w']), 'houseW'=>$this->dbIntOrNull($r['house_w']), 'gridW'=>$this->dbIntOrNull($r['grid_w']), 'batteryW'=>$this->dbIntOrNull($r['batt_w']),
            'pvMinW'=>$this->dbIntOrNull($r['pv_min_w'] ?? null), 'pvMaxW'=>$this->dbIntOrNull($r['pv_max_w'] ?? null),
            'houseMinW'=>$this->dbIntOrNull($r['house_min_w'] ?? null), 'houseMaxW'=>$this->dbIntOrNull($r['house_max_w'] ?? null),
            'gridMinW'=>$this->dbIntOrNull($r['grid_min_w'] ?? null), 'gridMaxW'=>$this->dbIntOrNull($r['grid_max_w'] ?? null),
            'batteryMinW'=>$this->dbIntOrNull($r['batt_min_w'] ?? null), 'batteryMaxW'=>$this->dbIntOrNull($r['batt_max_w'] ?? null),
            'socPct'=>$r['soc_x100']===null?null:((int)$r['soc_x100']/100),
            'batteryTempC'=>($r['batt_temp_x100']??null)===null?null:((int)$r['batt_temp_x100']/100),
            'rackTempC'=>($r['rack_temp_x100']??null)===null?null:((int)$r['rack_temp_x100']/100),
            'inverter1TempC'=>($r['inv1_temp_x100']??null)===null?null:((int)$r['inv1_temp_x100']/100),
            'inverter2TempC'=>($r['inv2_temp_x100']??null)===null?null:((int)$r['inv2_temp_x100']/100),
            'inverter3TempC'=>($r['inv3_temp_x100']??null)===null?null:((int)$r['inv3_temp_x100']/100),
            'socMinPct'=>($r['soc_min_x100']??null)===null?null:((int)$r['soc_min_x100']/100),
            'socMaxPct'=>($r['soc_max_x100']??null)===null?null:((int)$r['soc_max_x100']/100),
            'grid'=>['l1W'=>$this->dbIntOrNull($r['grid_l1_w']),'l2W'=>$this->dbIntOrNull($r['grid_l2_w']),'l3W'=>$this->dbIntOrNull($r['grid_l3_w'])],
            'house'=>['l1W'=>$this->dbIntOrNull($r['house_l1_w']),'l2W'=>$this->dbIntOrNull($r['house_l2_w']),'l3W'=>$this->dbIntOrNull($r['house_l3_w'])],
            'essGridPointW'=>$this->dbIntOrNull($r['ess_grid_w']),
            'spotPrice'=>$r['spot_x10000']===null?null:((int)$r['spot_x10000']/10000),
            'saleCzk'=>$this->bucketSale($r)[1] > 0 ? null : $this->bucketSale($r)[0] / 1000000,
            'gridImportKWh'=>((int)$r['grid_import_mwh']/1000000), 'gridExportKWh'=>((int)$r['grid_export_mwh']/1000000),
            'houseKWh'=>((int)$r['house_mwh']/1000000), 'pvKWh'=>((int)$r['pv_mwh']/1000000),
            'forecastPvKWh'=>$r['forecast_pv_wh']===null?null:((int)$r['forecast_pv_wh']/1000),
            'forecastConsumptionKWh'=>$r['forecast_cons_wh']===null?null:((int)$r['forecast_cons_wh']/1000),
        ];
    }

    private function summary(array $rows): array {
        $sum = fn(string $c): int => array_sum(array_map('intval', array_column($rows, $c)));
        $last = $rows !== [] ? $rows[count($rows)-1] : [];
        $sales = array_map(fn(array $r): array => $this->bucketSale($r), $rows);
        $unpriced = array_sum(array_column($sales, 1));
        return [
            'pvKWh'=>$sum('pv_mwh')/1000000,
            'houseKWh'=>$sum('house_mwh')/1000000,
            'gridImportKWh'=>$sum('grid_import_mwh')/1000000,
            'gridExportKWh'=>$sum('grid_export_mwh')/1000000,
            'batteryChargeKWh'=>$sum('batt_charge_mwh')/1000000,
            'batteryDischargeKWh'=>$sum('batt_discharge_mwh')/1000000,
            'spotSaleValue'=>$unpriced > 0 ? null : array_sum(array_column($sales, 0)) / 1000000,
            'unpricedExportKWh'=>$unpriced / 1000000,
            'lastSocPct'=>isset($last['soc_x100'])&&$last['soc_x100']!==null?((int)$last['soc_x100']/100):null,
            'pointCount'=>count($rows),
        ];
    }

    private function storeRawSnapshot(array $status, int $bucket): void {
        if ($this->snapshotExists($bucket)) return;
        // Prices have their own hourly table; the diagnostic value must not
        // enter any new stored snapshot. Older snapshots remain untouched.
        unset($status['spot'], $status['ess']['decision']['pvSurplusW'],
            $status['units']['spot.currentPrice'], $status['units']['ess.decision.pvSurplusW']);
        $payload = json_encode($status, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) return;
        $qb = $this->db->getQueryBuilder();
        $qb->insert('hc_gridsight_snapshots')
            ->setValue('ts',$qb->createNamedParameter($bucket,IQueryBuilder::PARAM_INT))
            ->setValue('api_version',$qb->createNamedParameter((string)($status['api']['version']??''),IQueryBuilder::PARAM_STR))
            ->setValue('payload',$qb->createNamedParameter($payload,IQueryBuilder::PARAM_STR));
        $qb->executeStatement();
    }

    private function trackEvents(array $s, int $now): void {
        $state = [
            'system.stale'=>$s['system']['stale']??null,
            'ess.exportAllowed'=>$s['ess']['decision']['exportAllowed']??null,
            'ess.predictionActive'=>$s['ess']['decision']['predictionActive']??null,
        ];
        // Record meaningful mode transitions, never numeric readings that can
        // change every second. The previous state is already kept in app config.
        foreach (['spotGridCharging','gridCharging','delayCharging','dynamicSocReserve',
            'morningPeakBatterySales','eveningPeakBatterySales','gridConsumption'] as $name) {
            $state['ess.switches.'.$name] = $s['ess']['switches'][$name] ?? null;
        }
        foreach (['spot','temperatures','forecast','vrm'] as $block) {
            $state['source.'.$block.'.available'] = $s[$block]['available'] ?? null;
        }
        if (($s['ups']['available']??false)===true) {
            $state['ups.onBattery']=$s['ups']['data']['onBattery']??null;
            $state['ups.lowBattery']=$s['ups']['data']['status']['lowBattery']??null;
        }
        if (($s['shelly']['available']??false)===true) {
            foreach (($s['shelly']['data']['smokeDetectors']??[]) as $smoke) {
                $name=trim((string)($smoke['name']??'smoke')); $state['smoke.'.$name.'.alarm']=$smoke['alarm']??null;
            }
        }
        if (($s['climate']['available']??false)===true) {
            foreach (($s['climate']['data']['devices']??[]) as $climate) {
                $name=trim((string)($climate['name']??'climate')); $state['climate.'.$name.'.error']=$climate['error']??null;
            }
        }
        $old=$this->config->getAppValue('lineamonitor','history_event_state','');
        $previous=json_decode($this->config->getAppValue(Application::APP_ID,'history_event_state',$old),true);
        if(!is_array($previous))$previous=[];
        foreach($state as $key=>$value){
            if($value===null||!array_key_exists($key,$previous)||$previous[$key]===$value)continue;
            [$title,$severity]=$this->eventTitle($key,$value);
            $this->insertEvent($now,$key,$severity,$title,null,$this->stateString($previous[$key]),$this->stateString($value));
        }
        $this->config->setAppValue(Application::APP_ID,'history_event_state',json_encode($state,JSON_UNESCAPED_UNICODE)?:'{}');
    }

    private function eventTitle(string $key, mixed $value): array {
        if($key==='system.stale')return[$value?'LINEA data jsou zastaralá':'LINEA data jsou opět aktuální',$value?'warning':'info'];
        if($key==='ups.onBattery')return[$value?'UPS přešla na baterii':'UPS je zpět na síti',$value?'warning':'info'];
        if($key==='ups.lowBattery')return[$value?'UPS hlásí nízkou baterii':'UPS již nehlásí nízkou baterii',$value?'critical':'info'];
        if(str_starts_with($key,'smoke.'))return[$value?'Kouřové čidlo hlásí ALARM':'Alarm kouřového čidla skončil',$value?'critical':'info'];
        if(str_starts_with($key,'climate.'))return[$value?'Klimatizace hlásí chybu':'Chyba klimatizace skončila',$value?'warning':'info'];
        if($key==='ess.exportAllowed')return[$value?'ESS povolil přetok do sítě':'ESS zakázal přetok do sítě','info'];
        if($key==='ess.predictionActive')return[$value?'ESS aktivoval predikční logiku':'ESS deaktivoval predikční logiku','info'];
        if(str_starts_with($key,'ess.switches.')) {
            $name = substr($key, strlen('ess.switches.'));
            $labels = ['spotGridCharging'=>'SPOT nabíjení ze sítě','gridCharging'=>'Nabíjení ze sítě',
                'delayCharging'=>'Odložené nabíjení','dynamicSocReserve'=>'Dynamická rezerva SOC',
                'morningPeakBatterySales'=>'Ranní prodej z baterie','eveningPeakBatterySales'=>'Večerní prodej z baterie',
                'gridConsumption'=>'Odběr ze sítě'];
            return [($labels[$name] ?? $name).($value?' zapnuto':' vypnuto'),'info'];
        }
        if(str_starts_with($key,'source.')) {
            $name = explode('.', $key)[1] ?? 'LINEA';
            return [($value?'Zdroj znovu dostupný: ':'Zdroj nedostupný: ').$name,$value?'info':'warning'];
        }
        return['Změna stavu LINEA','info'];
    }

    private function insertEvent(int $ts,string $type,string $severity,string $title,?string $detail,?string $old,?string $new): void {
        $qb=$this->db->getQueryBuilder();
        $qb->insert('hc_gridsight_events')
            ->setValue('ts',$qb->createNamedParameter($ts,IQueryBuilder::PARAM_INT))
            ->setValue('type',$qb->createNamedParameter($type,IQueryBuilder::PARAM_STR))
            ->setValue('severity',$qb->createNamedParameter($severity,IQueryBuilder::PARAM_STR))
            ->setValue('title',$qb->createNamedParameter($title,IQueryBuilder::PARAM_STR))
            ->setValue('detail',$detail===null?$qb->createNamedParameter(null):$qb->createNamedParameter($detail,IQueryBuilder::PARAM_STR))
            ->setValue('old_value',$old===null?$qb->createNamedParameter(null):$qb->createNamedParameter($old,IQueryBuilder::PARAM_STR))
            ->setValue('new_value',$new===null?$qb->createNamedParameter(null):$qb->createNamedParameter($new,IQueryBuilder::PARAM_STR));
        $qb->executeStatement();
    }

    private function getEvents(int $from,int $to,int $limit): array {
        $qb=$this->db->getQueryBuilder();
        $qb->select('*')->from('hc_gridsight_events')
            ->where($qb->expr()->gte('ts',$qb->createNamedParameter($from,IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lte('ts',$qb->createNamedParameter($to,IQueryBuilder::PARAM_INT)))
            ->orderBy('ts','DESC')->setMaxResults($limit);
        return array_map(static fn(array $r):array=>['ts'=>(int)$r['ts'],'type'=>$r['type'],'severity'=>$r['severity'],'title'=>$r['title'],'detail'=>$r['detail'],'oldValue'=>$r['old_value'],'newValue'=>$r['new_value']],$qb->executeQuery()->fetchAllAssociative());
    }

    private function prune(int $now): void {
        $this->deleteOlder(self::RES_5M,$now-30*86400);
        $this->deleteOlder(self::RES_15M,$now-365*86400);
        $this->deleteOlder(self::RES_1H,$now-1825*86400);
        $qb=$this->db->getQueryBuilder();
        $qb->delete('hc_gridsight_snapshots')->where($qb->expr()->lt('ts',$qb->createNamedParameter($now-7*86400,IQueryBuilder::PARAM_INT)))->executeStatement();
    }
    private function deleteOlder(int $resolution,int $before): void {
        $qb=$this->db->getQueryBuilder();
        $qb->delete('hc_gridsight_hist')->where($qb->expr()->eq('resolution',$qb->createNamedParameter($resolution,IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('ts',$qb->createNamedParameter($before,IQueryBuilder::PARAM_INT)))->executeStatement();
    }
    private function exists(int $resolution,int $ts): bool {
        $qb=$this->db->getQueryBuilder();
        $qb->select('id')->from('hc_gridsight_hist')->where($qb->expr()->eq('resolution',$qb->createNamedParameter($resolution,IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('ts',$qb->createNamedParameter($ts,IQueryBuilder::PARAM_INT)))->setMaxResults(1);
        return $qb->executeQuery()->fetchOne()!==false;
    }
    private function snapshotExists(int $ts): bool {
        $qb=$this->db->getQueryBuilder();$qb->select('id')->from('hc_gridsight_snapshots')->where($qb->expr()->eq('ts',$qb->createNamedParameter($ts,IQueryBuilder::PARAM_INT)))->setMaxResults(1);
        return $qb->executeQuery()->fetchOne()!==false;
    }
    private function countRows(int $resolution): int {
        $qb=$this->db->getQueryBuilder();$qb->select($qb->func()->count('*'))->from('hc_gridsight_hist')->where($qb->expr()->eq('resolution',$qb->createNamedParameter($resolution,IQueryBuilder::PARAM_INT)));
        $v=$qb->executeQuery()->fetchOne();return $v===false?0:(int)$v;
    }
    private function runtime(): array {
        $this->ensureRuntimeRow();
        $qb=$this->db->getQueryBuilder();$qb->select('*')->from('hc_gridsight_runtime')->where($qb->expr()->eq('id',$qb->createNamedParameter(1,IQueryBuilder::PARAM_INT)))->setMaxResults(1);
        $r=$qb->executeQuery()->fetchAssociative();return is_array($r)?$r:[];
    }
    private function ensureRuntimeRow(): void {
        $qb=$this->db->getQueryBuilder();$qb->select('id')->from('hc_gridsight_runtime')->where($qb->expr()->eq('id',$qb->createNamedParameter(1,IQueryBuilder::PARAM_INT)))->setMaxResults(1);
        if($qb->executeQuery()->fetchOne()!==false)return;
        try{
            $qb=$this->db->getQueryBuilder();$qb->insert('hc_gridsight_runtime')
                ->setValue('id',$qb->createNamedParameter(1,IQueryBuilder::PARAM_INT))
                ->setValue('collector_owner',$qb->createNamedParameter('',IQueryBuilder::PARAM_STR))
                ->setValue('lease_until',$qb->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->setValue('heartbeat_ts',$qb->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->setValue('started_ts',$qb->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->setValue('last_poll_ts',$qb->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->setValue('last_flush_ts',$qb->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->setValue('poll_ok',$qb->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->setValue('poll_errors',$qb->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->setValue('buffer_count',$qb->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->setValue('current_bucket',$qb->createNamedParameter(0,IQueryBuilder::PARAM_INT));
            $qb->executeStatement();
        }catch(\Throwable){/* concurrent creator won */}
    }

    private function databaseSizeInfo(int $now): array {
        $cachedAt = (int)$this->config->getAppValue(Application::APP_ID, 'history_db_size_checked_at', '0');
        $cachedBytes = (int)$this->config->getAppValue(Application::APP_ID, 'history_db_size_bytes', '0');
        if ($cachedAt > 0 && ($now - $cachedAt) < 3600) {
            return ['bytes'=>$cachedBytes, 'checkedAt'=>$cachedAt, 'cached'=>true, 'available'=>true];
        }

        try {
            $prefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');
            $tables = array_map(static fn(string $name): string => $prefix . $name, [
                'hc_gridsight_hist', 'hc_gridsight_events', 'hc_gridsight_snapshots', 'hc_gridsight_runtime', 'hc_gridsight_daily', 'hc_gridsight_spot_hour',
            ]);
            $placeholders = implode(',', array_fill(0, count($tables), '?'));
            $sql = 'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS total_bytes '
                . 'FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $placeholders . ')';
            $result = $this->db->executeQuery($sql, $tables)->fetchOne();
            $bytes = $result === false ? 0 : max(0, (int)$result);
            $this->config->setAppValue(Application::APP_ID, 'history_db_size_bytes', (string)$bytes);
            $this->config->setAppValue(Application::APP_ID, 'history_db_size_checked_at', (string)$now);
            return ['bytes'=>$bytes, 'checkedAt'=>$now, 'cached'=>false, 'available'=>true];
        } catch (\Throwable $e) {
            if ($cachedAt > 0) {
                return ['bytes'=>$cachedBytes, 'checkedAt'=>$cachedAt, 'cached'=>true, 'available'=>true];
            }
            return ['bytes'=>null, 'checkedAt'=>0, 'cached'=>false, 'available'=>false];
        }
    }
    private function dbIntOrNull(mixed $v): ?int { return $v===null?null:(int)$v; }
    private function stateString(mixed $v): ?string { return $v===null?null:(is_bool($v)?($v?'true':'false'):(string)$v); }
}
