<?php

/**
 * Hacesoft GridSight – typová legenda proměnných (maďarská notace).
 * Historie: 2026-11-21 – bezpečné sjednocení interních lokálních proměnných.
 * Historie: 2026-11-22 – ověření typů, významových komentářů a shody názvů vlastností.
 * s – textový řetězec / identifikátor nebo cesta.
 * n – číslo (celé, desetinné, čas nebo jednotky).
 * b – logická hodnota.
 * a – pole / seznam.
 * o – instance objektu nebo výjimka.
 * v – smíšená nebo zatím neurčená hodnota.
 * Výjimka: názvy parametrů PHP metod a veřejných vlastností se nemění,
 * protože jsou součástí rozhraní Nextcloud, DI a pojmenovaných argumentů.
 */

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

    /**
     * Inicializuje instanci a převezme požadované závislosti.
     *
     * Vstupy: `$db` (IDBConnection) – databázové spojení Nextcloudu; `$config` (IConfig) – konfigurace aplikace Nextcloud.
     * Výstup: `void` – nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací.
     *
     * @param IDBConnection $db databázové spojení Nextcloudu
     * @param IConfig $config konfigurace aplikace Nextcloud
     * @return void nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací
     */
    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
    ) {}

    /**
     * Vrátí, zda je ukládání historie povoleno.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `bool` – true, je-li ukládání historie zapnuto.
     * @return bool true, je-li ukládání historie zapnuto
     */
    public function isEnabled(): bool {
        $old = $this->config->getAppValue('lineamonitor', 'history_enabled', '1');
        return $this->config->getAppValue(Application::APP_ID, 'history_enabled', $old) !== '0';
    }

    /**
     * Zapne nebo vypne sběr historie podle administrátorského nastavení.
     *
     * Vstupy: `$enabled` (bool) – povolení ukládání historie.
     * Výstup: `void` – zapíše příznak povolení historie; nevrací hodnotu.
     *
     * @param bool $enabled povolení ukládání historie
     * @return void zapíše příznak povolení historie; nevrací hodnotu
     */
    public function setEnabled(bool $enabled): void {
        $this->config->setAppValue(Application::APP_ID, 'history_enabled', $enabled ? '1' : '0');
    }

    /**
     * Vrátí čas posledního úspěšného uložení historie.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `int` – unixový čas posledního úspěšného sběru (sekundy).
     * @return int unixový čas posledního úspěšného sběru (sekundy)
     */
    public function getLastCollectedAt(): int {
        $old = $this->config->getAppValue('lineamonitor', 'history_last_collect', '0');
        return (int)$this->config->getAppValue(Application::APP_ID, 'history_last_collect', $old);
    }

    /**
     * Compatibility/manual fallback. The normal 0.2.6 path is the 1 s daemon,
     * which calls storeAggregateWindow() once per completed 5-minute window.
     */
    /**
     * Převede ruční stavový snímek LINEA na historický záznam jako nouzovou alternativu sběrače.
     *
     * Vstupy: `$status` (array) – strukturovaná stavová odpověď LINEA; `$now` (?int) – aktuální unixový čas (s).
     * Výstup: `bool` – true při úspěšném uložení snímku, false při odmítnutí či duplicitě.
     *
     * @param array $status strukturovaná stavová odpověď LINEA
     * @param ?int $now aktuální unixový čas (s)
     * @return bool true při úspěšném uložení snímku, false při odmítnutí či duplicitě
     */
    public function storeSnapshot(array $status, ?int $now = null): bool {
        $now ??= time();
        $bucket = intdiv($now, self::RES_5M) * self::RES_5M;
        if ($this->exists(self::RES_5M, $bucket)) return false;
        $oAcc = new FastCollectorAccumulator($bucket, self::RES_5M);
        $oAcc->add($status, 1.0);
        return $this->storeAggregateWindow($oAcc->finalize(), $status, $now);
    }

    /**
     * Bezpečně uloží dokončený pětiminutový blok a přepočítá denní účtování.
     *
     * Vstupy: `$row` (array) – databázový nebo agregovaný historický řádek; `$lastStatus` (array) – poslední přijatý stav LINEA pro doplnění agregátu; `$now` (?int) – aktuální unixový čas (s).
     * Výstup: `bool` – true při uložení dokončeného agregátu, false pokud již existuje nebo není použitelný.
     *
     * @param array $row databázový nebo agregovaný historický řádek
     * @param array $lastStatus poslední přijatý stav LINEA pro doplnění agregátu
     * @param ?int $now aktuální unixový čas (s)
     * @return bool true při uložení dokončeného agregátu, false pokud již existuje nebo není použitelný
     */
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

    /**
     * Vyhodnotí změny stavu LINEA a zapíše odpovídající události.
     *
     * Vstupy: `$status` (array) – strukturovaná stavová odpověď LINEA; `$now` (?int) – aktuální unixový čas (s).
     * Výstup: `void` – zapíše přechody stavů LINEA do historie událostí; nevrací hodnotu.
     *
     * @param array $status strukturovaná stavová odpověď LINEA
     * @param ?int $now aktuální unixový čas (s)
     * @return void zapíše přechody stavů LINEA do historie událostí; nevrací hodnotu
     */
    public function trackEventsFromStatus(array $status, ?int $now = null): void {
        $this->trackEvents($status, $now ?? time());
    }

    /**
     * Najde nejstarší dostupné historické měření.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `int` – čas nejstaršího uloženého historického záznamu; 0 pokud žádný není.
     * @return int čas nejstaršího uloženého historického záznamu; 0 pokud žádný není
     */
    public function earliestTimestamp(): int {
        $oQb=$this->db->getQueryBuilder();
        $nFirst=$oQb->select('ts')->from('hc_gridsight_hist')->orderBy('ts','ASC')->setMaxResults(1)->executeQuery()->fetchOne();
        return $nFirst===false || $nFirst===null ? 0 : (int)$nFirst;
    }

    /**
     * Načte časovou řadu z vhodné agregace, souhrny a události.
     *
     * Vstupy: `$from` (int) – počáteční unixový čas intervalu (s); `$to` (int) – konečný unixový čas intervalu (s).
     * Výstup: `array` – pole časových bodů, souhrnu a událostí pro požadovaný interval.
     *
     * @param int $from počáteční unixový čas intervalu (s)
     * @param int $to konečný unixový čas intervalu (s)
     * @return array pole časových bodů, souhrnu a událostí pro požadovaný interval
     */
    public function getHistory(int $from, int $to): array {
        $from = max(0, $from);
        $to = max($from + 1, $to);
        $nSpan = $to - $from;
        $nPreferred = $nSpan <= 7 * 86400 ? self::RES_5M
            : ($nSpan <= 90 * 86400 ? self::RES_15M
            : ($nSpan <= 5 * 365 * 86400 ? self::RES_1H : self::RES_1D));
        $rows = $this->queryRows($nPreferred, $from, $to);
        $resolution = $nPreferred;
        if ($rows === []) {
            foreach ([self::RES_5M, self::RES_15M, self::RES_1H, self::RES_1D] as $vFallback) {
                $rows = $this->queryRows($vFallback, $from, $to);
                if ($rows !== []) { $resolution = $vFallback; break; }
            }
        }
        $rows = $this->attachHourlySpot($rows);
        return [
            'resolutionSec' => $resolution,
            'points' => array_map(/** Převede interní databázový řádek na veřejnou podobu bodu historie. Vstupy: jeden databázový řádek historie. Výstup: `array` – odvozený datový záznam. */ fn(array $r): array => $this->publicRow($r), $rows),
            'summary' => $this->summary($rows),
            'collector' => $this->info(),
            // The event log is independent of the selected chart range.
            'events' => $this->getEvents(0, time(), 100),
        ];
    }

    /**
     * Uloží hodinovou cenu SPOT do samostatné tabulky místo duplikace v jednotlivých měřeních.
     *
     * Vstupy: `$bucket` (int) – počátek časového bloku (unixové sekundy); `$priceX10000` (mixed) – význam parametru podle rozhraní metody; `$now` (int) – aktuální unixový čas (s).
     * Výstup: `void` – vloží nebo aktualizuje hodinovou SPOT cenu do oddělené tabulky; nevrací hodnotu.
     *
     * @param int $bucket počátek časového bloku (unixové sekundy)
     * @param mixed $priceX10000 význam parametru podle rozhraní metody
     * @param int $now aktuální unixový čas (s)
     * @return void vloží nebo aktualizuje hodinovou SPOT cenu do oddělené tabulky; nevrací hodnotu
     */
    private function storeHourlySpot(int $bucket, mixed $priceX10000, int $now): void {
        if ($priceX10000 === null) return;
        $nHour = intdiv($bucket, self::RES_1H) * self::RES_1H;
        $nPrice = (int)$priceX10000;
        $oSelect = $this->db->getQueryBuilder();
        $oSelect->select('price_x10000')->from('hc_gridsight_spot_hour')
            ->where($oSelect->expr()->eq('hour_ts', $oSelect->createNamedParameter($nHour, IQueryBuilder::PARAM_INT)));
        $vPrevious = $oSelect->executeQuery()->fetchOne();
        if ($vPrevious !== false && (int)$vPrevious === $nPrice) return;
        $oQb = $this->db->getQueryBuilder();
        if ($vPrevious === false) {
            $oQb->insert('hc_gridsight_spot_hour')
                ->setValue('hour_ts', $oQb->createNamedParameter($nHour, IQueryBuilder::PARAM_INT))
                ->setValue('price_x10000', $oQb->createNamedParameter($nPrice, IQueryBuilder::PARAM_INT))
                ->setValue('recorded_at', $oQb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
        } else {
            $oQb->update('hc_gridsight_spot_hour')
                ->set('price_x10000', $oQb->createNamedParameter($nPrice, IQueryBuilder::PARAM_INT))
                ->set('recorded_at', $oQb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
                ->where($oQb->expr()->eq('hour_ts', $oQb->createNamedParameter($nHour, IQueryBuilder::PARAM_INT)));
        }
        $oQb->executeStatement();
    }

    /** Map one stored hourly price to chart points without copying it into history. */
    /**
     * Doplní jednotlivým časovým bodům odpovídající SPOT cenu z hodinové tabulky.
     *
     * Vstupy: `$rows` (array) – seznam historických/databázových řádků.
     * Výstup: `array` – řádky historie s přiřazenou odpovídající hodinovou SPOT cenou.
     *
     * @param array $rows seznam historických/databázových řádků
     * @return array řádky historie s přiřazenou odpovídající hodinovou SPOT cenou
     */
    private function attachHourlySpot(array $rows): array {
        if ($rows === []) return $rows;
        $nFirstHour = intdiv((int)$rows[0]['ts'], self::RES_1H) * self::RES_1H;
        $nLastHour = intdiv((int)$rows[count($rows)-1]['ts'], self::RES_1H) * self::RES_1H;
        $oQb = $this->db->getQueryBuilder();
        $oQb->select('hour_ts', 'price_x10000')->from('hc_gridsight_spot_hour')
            ->where($oQb->expr()->gte('hour_ts', $oQb->createNamedParameter($nFirstHour, IQueryBuilder::PARAM_INT)))
            ->andWhere($oQb->expr()->lte('hour_ts', $oQb->createNamedParameter($nLastHour, IQueryBuilder::PARAM_INT)));
        $aPrices = [];
        foreach ($oQb->executeQuery()->fetchAllAssociative() as $nPriceRow) {
            $aPrices[(int)$nPriceRow['hour_ts']] = (int)$nPriceRow['price_x10000'];
        }
        foreach ($rows as &$row) {
            if (($row['spot_x10000'] ?? null) === null) {
                $nHour = intdiv((int)$row['ts'], self::RES_1H) * self::RES_1H;
                $row['spot_x10000'] = $aPrices[$nHour] ?? null;
            }
        }
        unset($row);
        return $rows;
    }

    /** Today's local calendar day, based on completed five-minute intervals. */
    /**
     * Sestaví účetní součty aktuálního pražského dne z hotových pětiminutových intervalů.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `array` – průběžné součty exportu a tržeb pro aktuální pražský kalendářní den.
     * @return array průběžné součty exportu a tržeb pro aktuální pražský kalendářní den
     */
    public function todayAccounting(): array {
        $day = $this->localDay(time());
        $oQb = $this->db->getQueryBuilder();
        $oQb->select('*')->from('hc_gridsight_daily')
            ->where($oQb->expr()->eq('day', $oQb->createNamedParameter($day, IQueryBuilder::PARAM_STR)));
        $vStored = $oQb->executeQuery()->fetchAssociative();
        // Existing five-minute rows from before this release can be shown as soon
        // as the schema is installed, even before the collector's first new flush.
        $vTotals = $vStored ?: $this->calculateDaily($day);
        return [
            'day' => $day,
            'timezone' => 'Europe/Prague',
            'hasData' => $vTotals !== null,
            'historyEnabled' => $this->isEnabled(),
            'importKWh' => $vTotals === null ? null : (int)$vTotals['import_mwh'] / 1000000,
            'exportKWh' => $vTotals === null ? null : (int)$vTotals['export_mwh'] / 1000000,
            'saleCzk' => $vTotals === null || (int)$vTotals['unpriced_export_mwh'] > 0
                ? null : (int)$vTotals['sale_microczk'] / 1000000,
            'pricedSaleCzk' => $vTotals === null ? null : (int)$vTotals['sale_microczk'] / 1000000,
            'unpricedExportKWh' => $vTotals === null ? null : (int)$vTotals['unpriced_export_mwh'] / 1000000,
            'coverageSec' => $vTotals === null ? 0 : (int)$vTotals['coverage_sec'],
            'bucketCount' => $vTotals === null ? 0 : (int)$vTotals['bucket_count'],
            'updatedAt' => $vTotals === null ? null : (int)$vTotals['updated_at'],
            'purchaseCzk' => null, // Waiting for Shelly tariff state and purchase tariffs.
        ];
    }

    /**
     * Převede časový okamžik na kalendářní den v časové zóně Praha.
     *
     * Vstupy: `$timestamp` (int) – unixový čas analyzovaného měření (s).
     * Výstup: `string` – datum ve formátu YYYY-MM-DD v časové zóně Europe/Prague.
     *
     * @param int $timestamp unixový čas analyzovaného měření (s)
     * @return string datum ve formátu YYYY-MM-DD v časové zóně Europe/Prague
     */
    private function localDay(int $timestamp): string {
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d');
    }

    /** Reconstruct from raw 5m buckets; never sum a previously stored daily row. */
    /**
     * Přepočítá denní bilanci z primárních pětiminutových bloků; nesčítá staré denní agregáty.
     *
     * Vstupy: `$day` (string) – význam parametru podle rozhraní metody.
     * Výstup: `?array` – denní energetický a cenový agregát, nebo null při absenci měření.
     *
     * @param string $day význam parametru podle rozhraní metody
     * @return ?array denní energetický a cenový agregát, nebo null při absenci měření
     */
    private function calculateDaily(string $day): ?array {
        $vStart = new \DateTimeImmutable($day . ' 00:00:00', new \DateTimeZone('Europe/Prague'));
        $from = $vStart->getTimestamp();
        $to = $vStart->modify('+1 day')->getTimestamp();
        $rows = $this->queryRows(self::RES_5M, $from, $to - 1);
        if ($rows === []) return null;
        $vTotals = ['day'=>$day, 'export_mwh'=>0, 'import_mwh'=>0,
            'sale_microczk'=>0, 'unpriced_export_mwh'=>0,
            'coverage_sec'=>0, 'bucket_count'=>count($rows), 'updated_at'=>time()];
        foreach ($rows as $row) {
            $vTotals['export_mwh'] += (int)$row['grid_export_mwh'];
            $vTotals['import_mwh'] += (int)$row['grid_import_mwh'];
            $vTotals['coverage_sec'] += (int)$row['coverage_sec'];
            [$vSale, $nUnpriced] = $this->bucketSale($row);
            $vTotals['sale_microczk'] += $vSale;
            $vTotals['unpriced_export_mwh'] += $nUnpriced;
        }
        return $vTotals;
    }

    /** Legacy 5m buckets used the SPOT price stored with that same bucket. */
    /**
     * Určí oceněnou a neoceněnou dodávku do sítě v jednom historickém bloku.
     *
     * Vstupy: `$row` (array) – databázový nebo agregovaný historický řádek.
     * Výstup: `array` – dvojice spočtené oceněné a neoceněné exportované energie z jednoho bloku.
     *
     * @param array $row databázový nebo agregovaný historický řádek
     * @return array dvojice spočtené oceněné a neoceněné exportované energie z jednoho bloku
     */
    private function bucketSale(array $row): array {
        if (($row['sale_microczk'] ?? null) !== null) {
            return [(int)$row['sale_microczk'], (int)($row['unpriced_export_mwh'] ?? 0)];
        }
        $vExport = (int)($row['grid_export_mwh'] ?? 0);
        if ($vExport <= 0) return [0, 0];
        if (($row['spot_x10000'] ?? null) === null || (int)$row['resolution'] !== self::RES_5M) {
            return [0, $vExport];
        }
        return [(int)round($vExport * (int)$row['spot_x10000'] / 10000), 0];
    }

    /**
     * Aktualizuje denní účet pro dokončený blok historie.
     *
     * Vstupy: `$bucket` (int) – počátek časového bloku (unixové sekundy); `$now` (int) – aktuální unixový čas (s).
     * Výstup: `void` – přepočítá a uloží denní účet za příslušný den; nevrací hodnotu.
     *
     * @param int $bucket počátek časového bloku (unixové sekundy)
     * @param int $now aktuální unixový čas (s)
     * @return void přepočítá a uloží denní účet za příslušný den; nevrací hodnotu
     */
    private function refreshDailyForBucket(int $bucket, int $now): void {
        $vTotals = $this->calculateDaily($this->localDay($bucket));
        if ($vTotals === null) return;
        $vTotals['updated_at'] = $now;
        $oSelect = $this->db->getQueryBuilder();
        $oSelect->select('day')->from('hc_gridsight_daily')
            ->where($oSelect->expr()->eq('day', $oSelect->createNamedParameter($vTotals['day'], IQueryBuilder::PARAM_STR)));
        $bExists = $oSelect->executeQuery()->fetchOne() !== false;
        $oQb = $this->db->getQueryBuilder();
        if ($bExists) {
            $oQb->update('hc_gridsight_daily')
                ->where($oQb->expr()->eq('day', $oQb->createNamedParameter($vTotals['day'], IQueryBuilder::PARAM_STR)));
        } else {
            $oQb->insert('hc_gridsight_daily')
                ->setValue('day', $oQb->createNamedParameter($vTotals['day'], IQueryBuilder::PARAM_STR));
        }
        foreach ($vTotals as $sColumn => $value) {
            if ($sColumn === 'day') continue;
            $vParameter = $oQb->createNamedParameter($value, IQueryBuilder::PARAM_INT);
            if ($bExists) $oQb->set($sColumn, $vParameter);
            else $oQb->setValue($sColumn, $vParameter);
        }
        $oQb->executeStatement();
    }

    /**
     * Připraví diagnostiku historie, databáze a běžícího sběrače.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `array` – diagnostické pole databáze historie, běhu sběrače a časů posledních zápisů.
     * @return array diagnostické pole databáze historie, běhu sběrače a časů posledních zápisů
     */
    public function info(): array {
        $runtime = $this->runtime();
        $now = time();
        $nHeartbeat = (int)($runtime['heartbeat_ts'] ?? 0);
        $nLeaseUntil = (int)($runtime['lease_until'] ?? 0);
        $nStartedAt = (int)($runtime['started_ts'] ?? 0);
        $nLastFlushAt = (int)($runtime['last_flush_ts'] ?? 0);
        $bOnline = $nHeartbeat > 0 && ($now - $nHeartbeat) <= 15;
        // During the first ~11 minutes there may legitimately be no completed bucket yet.
        // After that an online collector without a recent DB flush must not be presented as healthy.
        $bWriteHealthy = !$bOnline || $nStartedAt <= 0 || ($now - $nStartedAt) <= 660
            || ($nLastFlushAt > 0 && ($now - $nLastFlushAt) <= 660);
        return [
            'enabled' => $this->isEnabled(),
            'lastCollectedAt' => $this->getLastCollectedAt(),
            'fiveMinuteBuckets' => $this->countRows(self::RES_5M),
            'rawSamples' => $this->countRows(self::RES_5M), // backward-compatible UI key
            'resolutionPlan' => [300, 900, 3600, 86400],
            'retention' => ['5mDays'=>30, '15mDays'=>365, 'hourlyDays'=>1825, 'dailyDays'=>null, 'snapshotDays'=>7],
            'database' => $this->databaseSizeInfo($now),
            'fastCollector' => [
                'online' => $bOnline,
                'writeHealthy' => $bWriteHealthy,
                'writeProblem' => $bOnline && !$bWriteHealthy,
                'heartbeatAt' => $nHeartbeat,
                'leaseUntil' => $nLeaseUntil,
                'owner' => (string)($runtime['collector_owner'] ?? ''),
                'startedAt' => $nStartedAt,
                'lastPollAt' => (int)($runtime['last_poll_ts'] ?? 0),
                'lastFlushAt' => $nLastFlushAt,
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
    /**
     * Zajistí exkluzivní pronájem sběrače sdílený mezi webovým a cron kontejnerem.
     *
     * Vstupy: `$owner` (string) – identifikátor procesu držícího pronájem sběrače; `$now` (int) – aktuální unixový čas (s); `$leaseSec` (int) – platnost pronájmu sběrače v sekundách.
     * Výstup: `bool` – true pouze pokud sběrač získal exkluzivní pronájem zápisu.
     *
     * @param string $owner identifikátor procesu držícího pronájem sběrače
     * @param int $now aktuální unixový čas (s)
     * @param int $leaseSec platnost pronájmu sběrače v sekundách
     * @return bool true pouze pokud sběrač získal exkluzivní pronájem zápisu
     */
    public function acquireCollectorLease(string $owner, int $now, int $leaseSec = 20): bool {
        $this->ensureRuntimeRow();
        $oQb = $this->db->getQueryBuilder();
        $oQb->update('hc_gridsight_runtime')
            ->set('collector_owner', $oQb->createNamedParameter($owner, IQueryBuilder::PARAM_STR))
            ->set('lease_until', $oQb->createNamedParameter($now + $leaseSec, IQueryBuilder::PARAM_INT))
            ->set('heartbeat_ts', $oQb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('started_ts', $oQb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($oQb->expr()->eq('id', $oQb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($oQb->expr()->orX(
                $oQb->expr()->lt('lease_until', $oQb->createNamedParameter($now, IQueryBuilder::PARAM_INT)),
                $oQb->expr()->eq('collector_owner', $oQb->createNamedParameter($owner, IQueryBuilder::PARAM_STR))
            ));
        return $oQb->executeStatement() === 1;
    }

    /**
     * Obnoví vlastnictví běžícího sběrače.
     *
     * Vstupy: `$owner` (string) – identifikátor procesu držícího pronájem sběrače; `$now` (int) – aktuální unixový čas (s); `$runtime` (array) – diagnostické hodnoty procesu sběrače; `$leaseSec` (int) – platnost pronájmu sběrače v sekundách.
     * Výstup: `bool` – true při úspěšném prodloužení vlastního pronájmu sběrače.
     *
     * @param string $owner identifikátor procesu držícího pronájem sběrače
     * @param int $now aktuální unixový čas (s)
     * @param array $runtime diagnostické hodnoty procesu sběrače
     * @param int $leaseSec platnost pronájmu sběrače v sekundách
     * @return bool true při úspěšném prodloužení vlastního pronájmu sběrače
     */
    public function renewCollectorLease(string $owner, int $now, array $runtime = [], int $leaseSec = 20): bool {
        $this->ensureRuntimeRow();
        $oQb = $this->db->getQueryBuilder();
        $oQb->update('hc_gridsight_runtime')
            ->set('lease_until', $oQb->createNamedParameter($now + $leaseSec, IQueryBuilder::PARAM_INT))
            ->set('heartbeat_ts', $oQb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($oQb->expr()->eq('id', $oQb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($oQb->expr()->eq('collector_owner', $oQb->createNamedParameter($owner, IQueryBuilder::PARAM_STR)));
        foreach ($runtime as $key => $value) {
            if (!in_array($key, ['last_poll_ts','last_flush_ts','poll_ok','poll_errors','buffer_count','current_bucket','last_error'], true)) continue;
            $type = $key === 'last_error' ? IQueryBuilder::PARAM_STR : IQueryBuilder::PARAM_INT;
            $oQb->set($key, $value === null ? $oQb->createNamedParameter(null) : $oQb->createNamedParameter($value, $type));
        }
        return $oQb->executeStatement() === 1;
    }

    /**
     * Uvolní vlastnictví sběrače po ukončení nebo chybě.
     *
     * Vstupy: `$owner` (string) – identifikátor procesu držícího pronájem sběrače.
     * Výstup: `void` – uvolní pronájem daného vlastníka; nevrací hodnotu.
     *
     * @param string $owner identifikátor procesu držícího pronájem sběrače
     * @return void uvolní pronájem daného vlastníka; nevrací hodnotu
     */
    public function releaseCollectorLease(string $owner): void {
        $this->ensureRuntimeRow();
        $oQb = $this->db->getQueryBuilder();
        $oQb->update('hc_gridsight_runtime')
            ->set('lease_until', $oQb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->set('heartbeat_ts', $oQb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
            ->where($oQb->expr()->eq('id', $oQb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($oQb->expr()->eq('collector_owner', $oQb->createNamedParameter($owner, IQueryBuilder::PARAM_STR)))
            ->executeStatement();
    }

    /**
     * Aktualizuje diagnostické údaje o činnosti sběrače.
     *
     * Vstupy: `$values` (array) – mapa ukládaných nastavení nebo diagnostiky.
     * Výstup: `void` – aktualizuje diagnostický řádek sběrače; nevrací hodnotu.
     *
     * @param array $values mapa ukládaných nastavení nebo diagnostiky
     * @return void aktualizuje diagnostický řádek sběrače; nevrací hodnotu
     */
    public function updateRuntime(array $values): void {
        $this->ensureRuntimeRow();
        if ($values === []) return;
        $oQb = $this->db->getQueryBuilder();
        $oQb->update('hc_gridsight_runtime')->where($oQb->expr()->eq('id', $oQb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
        foreach ($values as $key => $value) {
            if (!in_array($key, ['last_poll_ts','last_flush_ts','poll_ok','poll_errors','buffer_count','current_bucket','last_error'], true)) continue;
            $type = $key === 'last_error' ? IQueryBuilder::PARAM_STR : IQueryBuilder::PARAM_INT;
            $oQb->set($key, $value === null ? $oQb->createNamedParameter(null) : $oQb->createNamedParameter($value, $type));
        }
        $oQb->executeStatement();
    }

    /**
     * Bezpečně vloží historický blok do databáze.
     *
     * Vstupy: `$row` (array) – databázový nebo agregovaný historický řádek.
     * Výstup: `void` – zapíše normalizovaný řádek historie; nevrací hodnotu.
     *
     * @param array $row databázový nebo agregovaný historický řádek
     * @return void zapíše normalizovaný řádek historie; nevrací hodnotu
     */
    private function insertHistoryRow(array $row): void {
        $aAllowed = [
            'resolution','ts','source_ts','sample_count','coverage_sec',
            'pv_w','house_w','grid_w','batt_w','soc_x100','batt_temp_x100','rack_temp_x100','inv1_temp_x100','inv2_temp_x100','inv3_temp_x100',
            'grid_l1_w','grid_l2_w','grid_l3_w','house_l1_w','house_l2_w','house_l3_w','ess_grid_w',
            'pv_min_w','pv_max_w','house_min_w','house_max_w','grid_min_w','grid_max_w','batt_min_w','batt_max_w','soc_min_x100','soc_max_x100',
            'forecast_pv_wh','forecast_cons_wh','vrm_pv_wh','vrm_cons_wh','vrm_import_wh','vrm_export_wh','batt_counter_charge_wh','batt_counter_discharge_wh',
            'pv_mwh','house_mwh','grid_import_mwh','grid_export_mwh','batt_charge_mwh','batt_discharge_mwh',
            'sale_microczk','unpriced_export_mwh','hdo_on_sec','hdo_off_sec'
        ];
        $oQb = $this->db->getQueryBuilder();
        $oQb->insert('hc_gridsight_hist');
        foreach ($aAllowed as $key) {
            if (!array_key_exists($key, $row)) continue;
            $value = $row[$key];
            $oQb->setValue($key, $value === null ? $oQb->createNamedParameter(null) : $oQb->createNamedParameter((int)$value, IQueryBuilder::PARAM_INT));
        }
        $oQb->executeStatement();
    }

    /**
     * Vytvoří delší časové agregace z již uložených podrobných měření.
     *
     * Vstupy: `$now` (int) – aktuální unixový čas (s).
     * Výstup: `void` – sestaví vyšší časová rozlišení historie; nevrací hodnotu.
     *
     * @param int $now aktuální unixový čas (s)
     * @return void sestaví vyšší časová rozlišení historie; nevrací hodnotu
     */
    private function buildAggregates(int $now): void {
        $this->aggregateCompleted(self::RES_5M, self::RES_15M, $now);
        $this->aggregateCompleted(self::RES_15M, self::RES_1H, $now);
        $this->aggregateCompleted(self::RES_1H, self::RES_1D, $now);
    }

    /**
     * Sestaví dokončené časové agregace pro zvolené rozlišení.
     *
     * Vstupy: `$sourceResolution` (int) – rozlišení zdrojových měření (s); `$targetResolution` (int) – požadované agregované rozlišení (s); `$now` (int) – aktuální unixový čas (s).
     * Výstup: `void` – zapisuje kompletní intervaly vyššího rozlišení z podrobnějších bloků; nevrací hodnotu.
     *
     * @param int $sourceResolution rozlišení zdrojových měření (s)
     * @param int $targetResolution požadované agregované rozlišení (s)
     * @param int $now aktuální unixový čas (s)
     * @return void zapisuje kompletní intervaly vyššího rozlišení z podrobnějších bloků; nevrací hodnotu
     */
    private function aggregateCompleted(int $sourceResolution, int $targetResolution, int $now): void {
        $bucket = intdiv($now, $targetResolution) * $targetResolution - $targetResolution;
        if ($bucket < 0 || $this->exists($targetResolution, $bucket)) return;
        $rows = $this->queryRows($sourceResolution, $bucket, $bucket + $targetResolution - 1);
        if ($rows === []) return;

        $aAvgCols = ['pv_w','house_w','grid_w','batt_w','soc_x100','batt_temp_x100','rack_temp_x100','inv1_temp_x100','inv2_temp_x100','inv3_temp_x100','grid_l1_w','grid_l2_w','grid_l3_w','house_l1_w','house_l2_w','house_l3_w','ess_grid_w'];
        $aLastCols = ['forecast_pv_wh','forecast_cons_wh','vrm_pv_wh','vrm_import_wh','vrm_cons_wh','vrm_export_wh','batt_counter_charge_wh','batt_counter_discharge_wh'];
        $aSumCols = ['coverage_sec','sample_count','pv_mwh','house_mwh','grid_import_mwh','grid_export_mwh','batt_charge_mwh','batt_discharge_mwh'];
        $aMinCols = ['pv_min_w','house_min_w','grid_min_w','batt_min_w','soc_min_x100'];
        $aMaxCols = ['pv_max_w','house_max_w','grid_max_w','batt_max_w','soc_max_x100'];
        $vOut = ['resolution'=>$targetResolution,'ts'=>$bucket,'source_ts'=>null];
        foreach ($aAvgCols as $col) $vOut[$col] = $this->weightedAverage($rows, $col, 'coverage_sec');
        foreach ($aLastCols as $col) $vOut[$col] = $this->lastNonNull($rows, $col);
        foreach ($aSumCols as $col) $vOut[$col] = array_sum(array_map('intval', array_column($rows, $col)));
        foreach (['hdo_on_sec','hdo_off_sec'] as $col) {
            $vObserved = array_filter($rows, /** Ověří, zda měřený řádek má použitelnou hodnotu ve vybraném sloupci. Vstupy: řádek historie se sledovaným sloupcem. Výstup: `bool` – true/false podle uvedené podmínky. */ static fn(array $row): bool => ($row[$col] ?? null) !== null);
            $vOut[$col] = $vObserved === [] ? null : array_sum(array_map('intval', array_column($vObserved, $col)));
        }
        $vSales = array_map(/** Spočítá oceněnou a neoceněnou energii z jednotlivého bloku historie. Vstupy: řádek jednoho energetického bloku. Výstup: `array` – odvozený datový záznam. */ fn(array $r): array => $this->bucketSale($r), $rows);
        $vOut['sale_microczk'] = array_sum(array_column($vSales, 0));
        $vOut['unpriced_export_mwh'] = array_sum(array_column($vSales, 1));
        foreach ($aMinCols as $col) $vOut[$col] = $this->minNonNull($rows, $col);
        foreach ($aMaxCols as $col) $vOut[$col] = $this->maxNonNull($rows, $col);
        $this->insertHistoryRow($vOut);
    }

    /**
     * Vypočítá časově vážený průměr dostupných hodnot.
     *
     * Vstupy: `$rows` (array) – seznam historických/databázových řádků; `$col` (string) – název číselného sloupce v databázovém řádku; `$weightCol` (string) – název sloupce obsahující váhu/pokrytí měření.
     * Výstup: `?int` – zaokrouhlený vážený průměr nenulových datových položek nebo null bez měření.
     *
     * @param array $rows seznam historických/databázových řádků
     * @param string $col název číselného sloupce v databázovém řádku
     * @param string $weightCol název sloupce obsahující váhu/pokrytí měření
     * @return ?int zaokrouhlený vážený průměr nenulových datových položek nebo null bez měření
     */
    private function weightedAverage(array $rows, string $col, string $weightCol): ?int {
        $nSum = 0.0; $nWeight = 0;
        foreach ($rows as $r) {
            if (($r[$col] ?? null) === null) continue;
            $nW = max(1, (int)($r[$weightCol] ?? 0));
            $nSum += (int)$r[$col] * $nW; $nWeight += $nW;
        }
        return $nWeight <= 0 ? null : (int)round($nSum / $nWeight);
    }
    /**
     * Najde minimální nenulovou ve smyslu dostupnosti (ne NULL) hodnotu.
     *
     * Vstupy: `$rows` (array) – seznam historických/databázových řádků; `$col` (string) – název číselného sloupce v databázovém řádku.
     * Výstup: `?int` – nejnižší dostupné celé číslo nebo null při absenci.
     *
     * @param array $rows seznam historických/databázových řádků
     * @param string $col název číselného sloupce v databázovém řádku
     * @return ?int nejnižší dostupné celé číslo nebo null při absenci
     */
    private function minNonNull(array $rows, string $col): ?int { $v=[]; foreach($rows as $r) if(($r[$col]??null)!==null)$v[]=(int)$r[$col]; return $v===[]?null:min($v); }
    /**
     * Najde maximální dostupnou hodnotu mimo NULL.
     *
     * Vstupy: `$rows` (array) – seznam historických/databázových řádků; `$col` (string) – název číselného sloupce v databázovém řádku.
     * Výstup: `?int` – nejvyšší dostupné celé číslo nebo null při absenci.
     *
     * @param array $rows seznam historických/databázových řádků
     * @param string $col název číselného sloupce v databázovém řádku
     * @return ?int nejvyšší dostupné celé číslo nebo null při absenci
     */
    private function maxNonNull(array $rows, string $col): ?int { $v=[]; foreach($rows as $r) if(($r[$col]??null)!==null)$v[]=(int)$r[$col]; return $v===[]?null:max($v); }
    /**
     * Vrátí poslední dostupnou hodnotu mimo NULL.
     *
     * Vstupy: `$rows` (array) – seznam historických/databázových řádků; `$col` (string) – název číselného sloupce v databázovém řádku.
     * Výstup: `?int` – poslední dostupné celé číslo nebo null při absenci.
     *
     * @param array $rows seznam historických/databázových řádků
     * @param string $col název číselného sloupce v databázovém řádku
     * @return ?int poslední dostupné celé číslo nebo null při absenci
     */
    private function lastNonNull(array $rows, string $col): ?int { for($nI=count($rows)-1;$nI>=0;$nI--) if(($rows[$nI][$col]??null)!==null)return (int)$rows[$nI][$col]; return null; }

    /**
     * Načte historické záznamy ve zvoleném časovém rozsahu a rozlišení.
     *
     * Vstupy: `$resolution` (int) – délka jednoho historického intervalu v sekundách; `$from` (int) – počáteční unixový čas intervalu (s); `$to` (int) – konečný unixový čas intervalu (s).
     * Výstup: `array` – seznam databázových bloků daného rozlišení mezi časy from a to.
     *
     * @param int $resolution délka jednoho historického intervalu v sekundách
     * @param int $from počáteční unixový čas intervalu (s)
     * @param int $to konečný unixový čas intervalu (s)
     * @return array seznam databázových bloků daného rozlišení mezi časy from a to
     */
    private function queryRows(int $resolution, int $from, int $to): array {
        $oQb = $this->db->getQueryBuilder();
        $oQb->select('*')->from('hc_gridsight_hist')
            ->where($oQb->expr()->eq('resolution', $oQb->createNamedParameter($resolution, IQueryBuilder::PARAM_INT)))
            ->andWhere($oQb->expr()->gte('ts', $oQb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
            ->andWhere($oQb->expr()->lte('ts', $oQb->createNamedParameter($to, IQueryBuilder::PARAM_INT)))
            ->orderBy('ts', 'ASC');
        return $oQb->executeQuery()->fetchAllAssociative();
    }

    /** Complete quarter-hour Shelly states; legacy diagnostic snapshots are temporary fallback evidence. */
    /**
     * Zrekonstruuje spolehlivé čtvrthodinové stavy Shelly HDO pro cenovou analýzu.
     *
     * Vstupy: `$from` (int) – počáteční unixový čas intervalu (s); `$to` (int) – konečný unixový čas intervalu (s); `$includeSnapshots` (bool) – zda zahrnout také diagnostické snímky, pokud chybějí agregáty.
     * Výstup: `array` – mapa časových intervalů s dostupnými stavy Shelly HDO a jejich původem.
     *
     * @param int $from počáteční unixový čas intervalu (s)
     * @param int $to konečný unixový čas intervalu (s)
     * @param bool $includeSnapshots zda zahrnout také diagnostické snímky, pokud chybějí agregáty
     * @return array mapa časových intervalů s dostupnými stavy Shelly HDO a jejich původem
     */
    public function hdoEvidence(int $from, int $to, bool $includeSnapshots = true): array {
        $from = intdiv($from, self::RES_15M) * self::RES_15M;
        $to = intdiv($to + self::RES_15M - 1, self::RES_15M) * self::RES_15M;
        $aFive = []; $aFifteen = [];
        foreach ([self::RES_5M, self::RES_15M] as $resolution) {
            $oQb = $this->db->getQueryBuilder();
            $oQb->select('ts','hdo_on_sec','hdo_off_sec')->from('hc_gridsight_hist')
                ->where($oQb->expr()->eq('resolution',$oQb->createNamedParameter($resolution,IQueryBuilder::PARAM_INT)))
                ->andWhere($oQb->expr()->gte('ts',$oQb->createNamedParameter($from,IQueryBuilder::PARAM_INT)))
                ->andWhere($oQb->expr()->lt('ts',$oQb->createNamedParameter($to,IQueryBuilder::PARAM_INT)));
            foreach ($oQb->executeQuery()->fetchAllAssociative() as $row) {
                if ($row['hdo_on_sec'] === null || $row['hdo_off_sec'] === null) continue;
                $ts = (int)$row['ts'];
                if ($resolution === self::RES_5M) $aFive[$ts] = [(int)$row['hdo_on_sec'],(int)$row['hdo_off_sec'],'shelly'];
                else $aFifteen[$ts] = [(int)$row['hdo_on_sec'],(int)$row['hdo_off_sec'],'shelly'];
            }
        }
        // Old snapshots contain only one reading at the end of a five-minute
        // window. Never invent missing windows or extend a state across gaps.
        if ($includeSnapshots) {
            $oQb = $this->db->getQueryBuilder();
            $oQb->select('ts','payload')->from('hc_gridsight_snapshots')
                ->where($oQb->expr()->gte('ts',$oQb->createNamedParameter($from,IQueryBuilder::PARAM_INT)))
                ->andWhere($oQb->expr()->lt('ts',$oQb->createNamedParameter($to,IQueryBuilder::PARAM_INT)));
            foreach ($oQb->executeQuery()->fetchAllAssociative() as $row) {
                $ts = (int)$row['ts'];
                if (isset($aFive[$ts])) continue;
                $status = json_decode((string)$row['payload'],true);
                $vState = is_array($status) ? ShellyHdo::inputState($status, $ts + 300) : null;
                if ($vState !== null) $aFive[$ts] = [$vState ? 300 : 0,$vState ? 0 : 300,'snapshot'];
            }
        }
        $vResult = [];
        for ($nQuarter = $from; $nQuarter < $to; $nQuarter += self::RES_15M) {
            $aSlots = [];
            for ($ts=$nQuarter; $ts<$nQuarter+self::RES_15M; $ts+=self::RES_5M) {
                if (isset($aFive[$ts])) $aSlots[] = $aFive[$ts];
            }
            $vCandidate = count($aSlots) === 3 ? [array_sum(array_column($aSlots,0)),array_sum(array_column($aSlots,1)),
                in_array('snapshot',array_column($aSlots,2),true) ? 'snapshot' : 'shelly'] : ($aFifteen[$nQuarter] ?? null);
            if ($vCandidate === null) continue;
            [$vOn,$vOff,$sSource] = $vCandidate;
            if ($vOn+$vOff < 810 || max($vOn,$vOff) < ($vOn+$vOff)*0.98) continue;
            $vResult[$nQuarter] = ['state'=>$vOn>$vOff,'source'=>$sSource];
        }
        return $vResult;
    }

    /**
     * Převede databázový záznam na veřejnou podobu API.
     *
     * Vstupy: `$r` (array) – význam parametru podle rozhraní metody.
     * Výstup: `array` – veřejný záznam historie s normalizovanými poli pro frontend.
     *
     * @param array $r význam parametru podle rozhraní metody
     * @return array veřejný záznam historie s normalizovanými poli pro frontend
     */
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

    /**
     * Spočítá souhrnné hodnoty pro vybraný výřez historie.
     *
     * Vstupy: `$rows` (array) – seznam historických/databázových řádků.
     * Výstup: `array` – souhrn výkonů a energií přes dané historické bloky.
     *
     * @param array $rows seznam historických/databázových řádků
     * @return array souhrn výkonů a energií přes dané historické bloky
     */
    private function summary(array $rows): array {
        $nSum = /** Sečte hodnoty vybraného energetického sloupce napříč historickými řádky. Vstupy: název energetického sloupce, nad nímž se provádí součet. Výstup: `int` – číselný součet nebo porovnávací výsledek. */ fn(string $vC): int => array_sum(array_map('intval', array_column($rows, $vC)));
        $nLast = $rows !== [] ? $rows[count($rows)-1] : [];
        $vSales = array_map(/** Rozdělí export jednoho bloku na oceněný a neoceněný podíl. Vstupy: řádek jednoho energetického bloku. Výstup: `array` – odvozený datový záznam. */ fn(array $r): array => $this->bucketSale($r), $rows);
        $nUnpriced = array_sum(array_column($vSales, 1));
        return [
            'pvKWh'=>$nSum('pv_mwh')/1000000,
            'houseKWh'=>$nSum('house_mwh')/1000000,
            'gridImportKWh'=>$nSum('grid_import_mwh')/1000000,
            'gridExportKWh'=>$nSum('grid_export_mwh')/1000000,
            'batteryChargeKWh'=>$nSum('batt_charge_mwh')/1000000,
            'batteryDischargeKWh'=>$nSum('batt_discharge_mwh')/1000000,
            'spotSaleValue'=>$nUnpriced > 0 ? null : array_sum(array_column($vSales, 0)) / 1000000,
            'unpricedExportKWh'=>$nUnpriced / 1000000,
            'lastSocPct'=>isset($nLast['soc_x100'])&&$nLast['soc_x100']!==null?((int)$nLast['soc_x100']/100):null,
            'pointCount'=>count($rows),
        ];
    }

    /**
     * Uloží technický snímek zdrojového stavu pro diagnostiku.
     *
     * Vstupy: `$status` (array) – strukturovaná stavová odpověď LINEA; `$bucket` (int) – počátek časového bloku (unixové sekundy).
     * Výstup: `void` – uloží diagnostický snímek odpovědi LINEA; nevrací hodnotu.
     *
     * @param array $status strukturovaná stavová odpověď LINEA
     * @param int $bucket počátek časového bloku (unixové sekundy)
     * @return void uloží diagnostický snímek odpovědi LINEA; nevrací hodnotu
     */
    private function storeRawSnapshot(array $status, int $bucket): void {
        if ($this->snapshotExists($bucket)) return;
        // Prices have their own hourly table; the diagnostic value must not
        // enter any new stored snapshot. Older snapshots remain untouched.
        unset($status['spot'], $status['ess']['decision']['pvSurplusW'],
            $status['units']['spot.currentPrice'], $status['units']['ess.decision.pvSurplusW']);
        $vPayload = json_encode($status, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($vPayload)) return;
        $oQb = $this->db->getQueryBuilder();
        $oQb->insert('hc_gridsight_snapshots')
            ->setValue('ts',$oQb->createNamedParameter($bucket,IQueryBuilder::PARAM_INT))
            ->setValue('api_version',$oQb->createNamedParameter((string)($status['api']['version']??''),IQueryBuilder::PARAM_STR))
            ->setValue('payload',$oQb->createNamedParameter($vPayload,IQueryBuilder::PARAM_STR));
        $oQb->executeStatement();
    }

    /**
     * Vyhodnotí významné změny dostupných stavů a zaznamená události.
     *
     * Vstupy: `$s` (array) – význam parametru podle rozhraní metody; `$now` (int) – aktuální unixový čas (s).
     * Výstup: `void` – porovná dostupné stavové položky a uloží změnové události; nevrací hodnotu.
     *
     * @param array $s význam parametru podle rozhraní metody
     * @param int $now aktuální unixový čas (s)
     * @return void porovná dostupné stavové položky a uloží změnové události; nevrací hodnotu
     */
    private function trackEvents(array $s, int $now): void {
        $vState = [
            'system.stale'=>$s['system']['stale']??null,
            'ess.exportAllowed'=>$s['ess']['decision']['exportAllowed']??null,
            'ess.predictionActive'=>$s['ess']['decision']['predictionActive']??null,
        ];
        // Record meaningful mode transitions, never numeric readings that can
        // change every second. The previous state is already kept in app config.
        foreach (['spotGridCharging','gridCharging','delayCharging','dynamicSocReserve',
            'morningPeakBatterySales','eveningPeakBatterySales','gridConsumption'] as $sName) {
            $vState['ess.switches.'.$sName] = $s['ess']['switches'][$sName] ?? null;
        }
        foreach (['spot','temperatures','forecast','vrm'] as $vBlock) {
            $vState['source.'.$vBlock.'.available'] = $s[$vBlock]['available'] ?? null;
        }
        if (($s['ups']['available']??false)===true) {
            $vState['ups.onBattery']=$s['ups']['data']['onBattery']??null;
            $vState['ups.lowBattery']=$s['ups']['data']['status']['lowBattery']??null;
        }
        if (($s['shelly']['available']??false)===true) {
            foreach (($s['shelly']['data']['smokeDetectors']??[]) as $vSmoke) {
                $sName=trim((string)($vSmoke['name']??'smoke')); $vState['smoke.'.$sName.'.alarm']=$vSmoke['alarm']??null;
            }
        }
        if (($s['climate']['available']??false)===true) {
            foreach (($s['climate']['data']['devices']??[]) as $vClimate) {
                $sName=trim((string)($vClimate['name']??'climate')); $vState['climate.'.$sName.'.error']=$vClimate['error']??null;
            }
        }
        $old=$this->config->getAppValue('lineamonitor','history_event_state','');
        $vPrevious=json_decode($this->config->getAppValue(Application::APP_ID,'history_event_state',$old),true);
        if(!is_array($vPrevious))$vPrevious=[];
        foreach($vState as $key=>$value){
            if($value===null||!array_key_exists($key,$vPrevious)||$vPrevious[$key]===$value)continue;
            [$title,$severity]=$this->eventTitle($key,$value);
            $this->insertEvent($now,$key,$severity,$title,null,$this->stateString($vPrevious[$key]),$this->stateString($value));
        }
        $this->config->setAppValue(Application::APP_ID,'history_event_state',json_encode($vState,JSON_UNESCAPED_UNICODE)?:'{}');
    }

    /**
     * Převede identifikátor události na zobrazený titulek.
     *
     * Vstupy: `$key` (string) – technický identifikátor změnové události; `$value` (mixed) – aktuální hodnota metriky nebo atributu.
     * Výstup: `array` – dvojice lidsky čitelného titulku a doplňujícího popisu události.
     *
     * @param string $key technický identifikátor změnové události
     * @param mixed $value aktuální hodnota metriky nebo atributu
     * @return array dvojice lidsky čitelného titulku a doplňujícího popisu události
     */
    private function eventTitle(string $key, mixed $value): array {
        if($key==='system.stale')return[$value?'LINEA data jsou zastaralá':'LINEA data jsou opět aktuální',$value?'warning':'info'];
        if($key==='ups.onBattery')return[$value?'UPS přešla na baterii':'UPS je zpět na síti',$value?'warning':'info'];
        if($key==='ups.lowBattery')return[$value?'UPS hlásí nízkou baterii':'UPS již nehlásí nízkou baterii',$value?'critical':'info'];
        if(str_starts_with($key,'smoke.'))return[$value?'Kouřové čidlo hlásí ALARM':'Alarm kouřového čidla skončil',$value?'critical':'info'];
        if(str_starts_with($key,'climate.'))return[$value?'Klimatizace hlásí chybu':'Chyba klimatizace skončila',$value?'warning':'info'];
        if($key==='ess.exportAllowed')return[$value?'ESS povolil přetok do sítě':'ESS zakázal přetok do sítě','info'];
        if($key==='ess.predictionActive')return[$value?'ESS aktivoval predikční logiku':'ESS deaktivoval predikční logiku','info'];
        if(str_starts_with($key,'ess.switches.')) {
            $sName = substr($key, strlen('ess.switches.'));
            $aLabels = ['spotGridCharging'=>'SPOT nabíjení ze sítě','gridCharging'=>'Nabíjení ze sítě',
                'delayCharging'=>'Odložené nabíjení','dynamicSocReserve'=>'Dynamická rezerva SOC',
                'morningPeakBatterySales'=>'Ranní prodej z baterie','eveningPeakBatterySales'=>'Večerní prodej z baterie',
                'gridConsumption'=>'Odběr ze sítě'];
            return [($aLabels[$sName] ?? $sName).($value?' zapnuto':' vypnuto'),'info'];
        }
        if(str_starts_with($key,'source.')) {
            $sName = explode('.', $key)[1] ?? 'LINEA';
            return [($value?'Zdroj znovu dostupný: ':'Zdroj nedostupný: ').$sName,$value?'info':'warning'];
        }
        return['Změna stavu LINEA','info'];
    }

    /**
     * Zapíše událost do databáze.
     *
     * Vstupy: `$ts` (int) – unixový čas události či bloku v sekundách; `$type` (string) – kategorie události (např. zařízení nebo ESS); `$severity` (string) – úroveň události (info, warning aj.); `$title` (string) – česky čitelný název události; `$detail` (?string) – volitelný podrobný popis události; `$old` (?string) – původní hodnota před změnou; `$new` (?string) – nová hodnota po změně.
     * Výstup: `void` – uloží událost se závažností, hodnotami před/po změně a časem; nevrací hodnotu.
     *
     * @param int $ts unixový čas události či bloku v sekundách
     * @param string $type kategorie události (např. zařízení nebo ESS)
     * @param string $severity úroveň události (info, warning aj.)
     * @param string $title česky čitelný název události
     * @param ?string $detail volitelný podrobný popis události
     * @param ?string $old původní hodnota před změnou
     * @param ?string $new nová hodnota po změně
     * @return void uloží událost se závažností, hodnotami před/po změně a časem; nevrací hodnotu
     */
    private function insertEvent(int $ts,string $type,string $severity,string $title,?string $detail,?string $old,?string $new): void {
        $oQb=$this->db->getQueryBuilder();
        $oQb->insert('hc_gridsight_events')
            ->setValue('ts',$oQb->createNamedParameter($ts,IQueryBuilder::PARAM_INT))
            ->setValue('type',$oQb->createNamedParameter($type,IQueryBuilder::PARAM_STR))
            ->setValue('severity',$oQb->createNamedParameter($severity,IQueryBuilder::PARAM_STR))
            ->setValue('title',$oQb->createNamedParameter($title,IQueryBuilder::PARAM_STR))
            ->setValue('detail',$detail===null?$oQb->createNamedParameter(null):$oQb->createNamedParameter($detail,IQueryBuilder::PARAM_STR))
            ->setValue('old_value',$old===null?$oQb->createNamedParameter(null):$oQb->createNamedParameter($old,IQueryBuilder::PARAM_STR))
            ->setValue('new_value',$new===null?$oQb->createNamedParameter(null):$oQb->createNamedParameter($new,IQueryBuilder::PARAM_STR));
        $oQb->executeStatement();
    }

    /**
     * Vrátí nejnovější události omezené zadaným časem a počtem.
     *
     * Vstupy: `$from` (int) – počáteční unixový čas intervalu (s); `$to` (int) – konečný unixový čas intervalu (s); `$limit` (int) – nejvyšší počet vracených událostí.
     * Výstup: `array` – seznam posledních událostí podle času a zadaného limitu.
     *
     * @param int $from počáteční unixový čas intervalu (s)
     * @param int $to konečný unixový čas intervalu (s)
     * @param int $limit nejvyšší počet vracených událostí
     * @return array seznam posledních událostí podle času a zadaného limitu
     */
    private function getEvents(int $from,int $to,int $limit): array {
        $oQb=$this->db->getQueryBuilder();
        $oQb->select('*')->from('hc_gridsight_events')
            ->where($oQb->expr()->gte('ts',$oQb->createNamedParameter($from,IQueryBuilder::PARAM_INT)))
            ->andWhere($oQb->expr()->lte('ts',$oQb->createNamedParameter($to,IQueryBuilder::PARAM_INT)))
            ->orderBy('ts','DESC')->setMaxResults($limit);
        return array_map(/** Normalizuje databázový záznam události na veřejná API pole. Vstupy: řádek události včetně času, typu, staré a nové hodnoty. Výstup: `array` – odvozený datový záznam. */ static fn(array $r):array=>['ts'=>(int)$r['ts'],'type'=>$r['type'],'severity'=>$r['severity'],'title'=>$r['title'],'detail'=>$r['detail'],'oldValue'=>$r['old_value'],'newValue'=>$r['new_value']],$oQb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Odstraní historii po uplynutí nastavených retenčních lhůt.
     *
     * Vstupy: `$now` (int) – aktuální unixový čas (s).
     * Výstup: `void` – odstraní záznamy překračující retenční dobu; nevrací hodnotu.
     *
     * @param int $now aktuální unixový čas (s)
     * @return void odstraní záznamy překračující retenční dobu; nevrací hodnotu
     */
    private function prune(int $now): void {
        $this->deleteOlder(self::RES_5M,$now-30*86400);
        $this->deleteOlder(self::RES_15M,$now-365*86400);
        $this->deleteOlder(self::RES_1H,$now-1825*86400);
        $oQb=$this->db->getQueryBuilder();
        $oQb->delete('hc_gridsight_snapshots')->where($oQb->expr()->lt('ts',$oQb->createNamedParameter($now-7*86400,IQueryBuilder::PARAM_INT)))->executeStatement();
    }
    /**
     * Smaže záznamy starší než zvolená hranice a splňující filtr.
     *
     * Vstupy: `$resolution` (int) – délka jednoho historického intervalu v sekundách; `$before` (int) – hranice času, před níž se mažou starší bloky.
     * Výstup: `void` – odstraní historické řádky před hranicí času v daném rozlišení; nevrací hodnotu.
     *
     * @param int $resolution délka jednoho historického intervalu v sekundách
     * @param int $before hranice času, před níž se mažou starší bloky
     * @return void odstraní historické řádky před hranicí času v daném rozlišení; nevrací hodnotu
     */
    private function deleteOlder(int $resolution,int $before): void {
        $oQb=$this->db->getQueryBuilder();
        $oQb->delete('hc_gridsight_hist')->where($oQb->expr()->eq('resolution',$oQb->createNamedParameter($resolution,IQueryBuilder::PARAM_INT)))
            ->andWhere($oQb->expr()->lt('ts',$oQb->createNamedParameter($before,IQueryBuilder::PARAM_INT)))->executeStatement();
    }
    /**
     * Ověří, zda databáze už obsahuje požadovaný historický blok.
     *
     * Vstupy: `$resolution` (int) – délka jednoho historického intervalu v sekundách; `$ts` (int) – unixový čas události či bloku v sekundách.
     * Výstup: `bool` – true, pokud existuje historický blok zvoleného rozlišení a času.
     *
     * @param int $resolution délka jednoho historického intervalu v sekundách
     * @param int $ts unixový čas události či bloku v sekundách
     * @return bool true, pokud existuje historický blok zvoleného rozlišení a času
     */
    private function exists(int $resolution,int $ts): bool {
        $oQb=$this->db->getQueryBuilder();
        $oQb->select('id')->from('hc_gridsight_hist')->where($oQb->expr()->eq('resolution',$oQb->createNamedParameter($resolution,IQueryBuilder::PARAM_INT)))
            ->andWhere($oQb->expr()->eq('ts',$oQb->createNamedParameter($ts,IQueryBuilder::PARAM_INT)))->setMaxResults(1);
        return $oQb->executeQuery()->fetchOne()!==false;
    }
    /**
     * Ověří existenci diagnostického snímku.
     *
     * Vstupy: `$ts` (int) – unixový čas události či bloku v sekundách.
     * Výstup: `bool` – true, pokud již existuje diagnostický snímek v daném čase.
     *
     * @param int $ts unixový čas události či bloku v sekundách
     * @return bool true, pokud již existuje diagnostický snímek v daném čase
     */
    private function snapshotExists(int $ts): bool {
        $oQb=$this->db->getQueryBuilder();$oQb->select('id')->from('hc_gridsight_snapshots')->where($oQb->expr()->eq('ts',$oQb->createNamedParameter($ts,IQueryBuilder::PARAM_INT)))->setMaxResults(1);
        return $oQb->executeQuery()->fetchOne()!==false;
    }
    /**
     * Spočítá počet odpovídajících databázových řádků.
     *
     * Vstupy: `$resolution` (int) – délka jednoho historického intervalu v sekundách.
     * Výstup: `int` – počet záznamů v daném rozlišení historie.
     *
     * @param int $resolution délka jednoho historického intervalu v sekundách
     * @return int počet záznamů v daném rozlišení historie
     */
    private function countRows(int $resolution): int {
        $oQb=$this->db->getQueryBuilder();$oQb->select($oQb->func()->count('*'))->from('hc_gridsight_hist')->where($oQb->expr()->eq('resolution',$oQb->createNamedParameter($resolution,IQueryBuilder::PARAM_INT)));
        $v=$oQb->executeQuery()->fetchOne();return $v===false?0:(int)$v;
    }
    /**
     * Načte aktuální diagnostický stav sběrače.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `array` – databázový stav běhu sběrače a pronájmu (lease).
     * @return array databázový stav běhu sběrače a pronájmu (lease)
     */
    private function runtime(): array {
        $this->ensureRuntimeRow();
        $oQb=$this->db->getQueryBuilder();$oQb->select('*')->from('hc_gridsight_runtime')->where($oQb->expr()->eq('id',$oQb->createNamedParameter(1,IQueryBuilder::PARAM_INT)))->setMaxResults(1);
        $r=$oQb->executeQuery()->fetchAssociative();return is_array($r)?$r:[];
    }
    /**
     * Založí chybějící diagnostický záznam sběrače.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `void` – vytvoří chybějící řádek diagnostiky sběrače; nevrací hodnotu.
     * @return void vytvoří chybějící řádek diagnostiky sběrače; nevrací hodnotu
     */
    private function ensureRuntimeRow(): void {
        $oQb=$this->db->getQueryBuilder();$oQb->select('id')->from('hc_gridsight_runtime')->where($oQb->expr()->eq('id',$oQb->createNamedParameter(1,IQueryBuilder::PARAM_INT)))->setMaxResults(1);
        if($oQb->executeQuery()->fetchOne()!==false)return;
        try{
            $oQb=$this->db->getQueryBuilder();$oQb->insert('hc_gridsight_runtime')
                ->setValue('id',$oQb->createNamedParameter(1,IQueryBuilder::PARAM_INT))
                ->setValue('collector_owner',$oQb->createNamedParameter('',IQueryBuilder::PARAM_STR))
                ->setValue('lease_until',$oQb->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->setValue('heartbeat_ts',$oQb->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->setValue('started_ts',$oQb->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->setValue('last_poll_ts',$oQb->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->setValue('last_flush_ts',$oQb->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->setValue('poll_ok',$oQb->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->setValue('poll_errors',$oQb->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->setValue('buffer_count',$oQb->createNamedParameter(0,IQueryBuilder::PARAM_INT))
                ->setValue('current_bucket',$oQb->createNamedParameter(0,IQueryBuilder::PARAM_INT));
            $oQb->executeStatement();
        }catch(\Throwable){/* concurrent creator won */}
    }

    /**
     * Vrátí odhad velikosti databázových tabulek aplikace.
     *
     * Vstupy: `$now` (int) – aktuální unixový čas (s).
     * Výstup: `array` – velikost databázových tabulek historie a čas jejího měření.
     *
     * @param int $now aktuální unixový čas (s)
     * @return array velikost databázových tabulek historie a čas jejího měření
     */
    private function databaseSizeInfo(int $now): array {
        $nCachedAt = (int)$this->config->getAppValue(Application::APP_ID, 'history_db_size_checked_at', '0');
        $nCachedBytes = (int)$this->config->getAppValue(Application::APP_ID, 'history_db_size_bytes', '0');
        if ($nCachedAt > 0 && ($now - $nCachedAt) < 3600) {
            return ['bytes'=>$nCachedBytes, 'checkedAt'=>$nCachedAt, 'cached'=>true, 'available'=>true];
        }

        try {
            $sPrefix = $this->config->getSystemValueString('dbtableprefix', 'oc_');
            $aTables = array_map(/** Připojí prefix databázových tabulek Nextcloudu k názvu tabulky aplikace. Vstupy: nezprefixovaný název tabulky GridSight. Výstup: `string` – formátovaný text. */ static fn(string $sName): string => $sPrefix . $sName, [
                'hc_gridsight_hist', 'hc_gridsight_events', 'hc_gridsight_snapshots', 'hc_gridsight_runtime', 'hc_gridsight_daily', 'hc_gridsight_spot_hour',
            ]);
            $sPlaceholders = implode(',', array_fill(0, count($aTables), '?'));
            $sSql = 'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS total_bytes '
                . 'FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $sPlaceholders . ')';
            $vResult = $this->db->executeQuery($sSql, $aTables)->fetchOne();
            $nBytes = $vResult === false ? 0 : max(0, (int)$vResult);
            $this->config->setAppValue(Application::APP_ID, 'history_db_size_bytes', (string)$nBytes);
            $this->config->setAppValue(Application::APP_ID, 'history_db_size_checked_at', (string)$now);
            return ['bytes'=>$nBytes, 'checkedAt'=>$now, 'cached'=>false, 'available'=>true];
        } catch (\Throwable $oE) {
            if ($nCachedAt > 0) {
                return ['bytes'=>$nCachedBytes, 'checkedAt'=>$nCachedAt, 'cached'=>true, 'available'=>true];
            }
            return ['bytes'=>null, 'checkedAt'=>0, 'cached'=>false, 'available'=>false];
        }
    }
    /**
     * Převede databázovou hodnotu na celé číslo, aniž by nedostupnost zaměnil za nulu.
     *
     * Vstupy: `$v` (mixed) – zdrojová hodnota z JSON/databáze s neověřeným typem.
     * Výstup: `?int` – číselná hodnota databázového pole nebo null, pokud není platná.
     *
     * @param mixed $v zdrojová hodnota z JSON/databáze s neověřeným typem
     * @return ?int číselná hodnota databázového pole nebo null, pokud není platná
     */
    private function dbIntOrNull(mixed $v): ?int { return $v===null?null:(int)$v; }
    /**
     * Normalizuje stavový text pro srovnávání změn.
     *
     * Vstupy: `$v` (mixed) – zdrojová hodnota z JSON/databáze s neověřeným typem.
     * Výstup: `?string` – normalizovaný řetězec stavové hodnoty nebo null při nedostupnosti.
     *
     * @param mixed $v zdrojová hodnota z JSON/databáze s neověřeným typem
     * @return ?string normalizovaný řetězec stavové hodnoty nebo null při nedostupnosti
     */
    private function stateString(mixed $v): ?string { return $v===null?null:(is_bool($v)?($v?'true':'false'):(string)$v); }
}
