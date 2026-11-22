<?php

/**
 * Hacesoft GridSight – typová legenda proměnných (maďarská notace).
 * Historie: 2026-11-21 – bezpečné sjednocení interních lokálních proměnných.
 * Historie: 2026-11-22 – ověření typů, významových komentářů a shody názvů vlastností.
 * s – textový řetězec / identifikátor nebo cesta.
 * n – číslo (celé, desetinné, čas nebo jednotky).
 * b – logická hodnota.
 * o – instance objektu nebo výjimka.
 * v – smíšená nebo zatím neurčená hodnota.
 * Výjimka: názvy parametrů PHP metod a veřejných vlastností se nemění,
 * protože jsou součástí rozhraní Nextcloud, DI a pojmenovaných argumentů.
 */

declare(strict_types=1);

/**
 * Rychlý sběrač historie LINEA (CLI služba Nextcloudu).
 * Vstup: konfigurované čtecí LINEA API, nastavení historie a případné signály SIGTERM/SIGINT.
 * Výstup: pětiminutové agregáty a diagnostika databáze; návratový kód procesu.
 *
 * Polls public read-only LINEA API approximately once per second, keeps a
 * one-hour circular buffer in RAM, calculates time-weighted statistics and
 * energy continuously, and writes only a completed 5-minute aggregate to the
 * Nextcloud database.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

$sNcRoot = '/var/www/html';
require_once $sNcRoot . '/lib/base.php';

use OCA\HcGridSight\Service\FastCollectorAccumulator;
use OCA\HcGridSight\Service\HistoryService;
use OCA\HcGridSight\Service\LineaApiService;

$oServer = \OC::$server;
/** @var LineaApiService $oLinea */
$oLinea = $oServer->get(LineaApiService::class);
/** @var HistoryService $oHistory */
$oHistory = $oServer->get(HistoryService::class);

$sHost = gethostname() ?: 'container';
$sOwner = $sHost . ':' . getmypid() . ':' . bin2hex(random_bytes(4));
$nNow = time();
if (!$oHistory->isEnabled()) {
    fwrite(STDERR, "LINEA history is disabled.\n");
    exit(0);
}
if (!$oHistory->acquireCollectorLease($sOwner, $nNow)) {
    fwrite(STDERR, "LINEA fast collector is already running.\n");
    exit(0);
}

$bRunning = true;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, /** Zpracuje signál pro bezpečné ukončení sběrače. Vstupy: žádné. Výstup: `void` – hodnota nebo účinek dle volající operace. */ static function () use (&$bRunning): void { $bRunning = false; });
    pcntl_signal(SIGINT, /** Zpracuje signál pro bezpečné ukončení sběrače. Vstupy: žádné. Výstup: `void` – hodnota nebo účinek dle volající operace. */ static function () use (&$bRunning): void { $bRunning = false; });
}

$nPollOk = 0;
$nPollErrors = 0;
$sLastPollError = null;
$sLastFlushError = null;
$nLastWall = microtime(true);
$nLastHeartbeat = 0;
$vLastEventFingerprint = null;
$nCurrentBucket = intdiv(time(), HistoryService::RES_5M) * HistoryService::RES_5M;
$oAcc = new FastCollectorAccumulator($nCurrentBucket, HistoryService::RES_5M);

// One-hour ring buffer: 3600 snapshots maximum. It exists only in RAM.
$aRing = array_fill(0, 3600, null);
$nRingPos = 0;
$nRingCount = 0;

/**
 * Uloží dokončený agregát, pokud v okně existují platné a nezastaralé vzorky.
 * Vstup: stav akumulátoru a HistoryService; výstup: bool úspěchu uložení.
 */
$fnFlush = /** Dokončí pětiminutové okno a uloží agregát do databáze. Vstupy: zachycené `$oAcc` (akumulované hodnoty), `$oHistory` (úložiště), referenční `$sLastFlushError` (diagnostika chyby); bez přímých parametrů. Výstup: `bool` – true při vložení nového agregátu, false při prázdném či již uloženém okně; při selhání vyhodí výjimku. */ static function () use (&$oAcc, $oHistory, &$sLastFlushError): bool {
    $vLastStatus = $oAcc->getLastStatus();
    if ($vLastStatus === null || $oAcc->getSampleCount() === 0) {
        $sLastFlushError = 'flush skipped: no valid non-stale samples in completed 5-minute window';
        fwrite(STDERR, '[' . date('c') . "] $sLastFlushError\n");
        return false;
    }
    try {
        $vStored = $oHistory->storeAggregateWindow($oAcc->finalize(), $vLastStatus, time());
        $sLastFlushError = null;
        fwrite(STDOUT, '[' . date('c') . '] 5-minute aggregate ' . ($vStored ? 'stored' : 'already present') . ' for bucket ' . $oAcc->getBucketTs() . "\n");
        return $vStored;
    } catch (\Throwable $oE) {
        $sLastFlushError = 'flush: ' . mb_substr($oE->getMessage(), 0, 500);
        fwrite(STDERR, '[' . date('c') . "] $sLastFlushError\n");
        throw $oE;
    }
};

register_shutdown_function(/** Ošetří ukončení procesu a uvolnění prostředků. Vstupy: žádné. Výstup: `void` – hodnota nebo účinek dle volající operace. */ static function () use (&$oAcc, $oHistory, $sOwner): void {
    try {
        if ($oAcc->getLastStatus() !== null && $oAcc->getCoverageSec() >= 30.0) {
            $oHistory->storeAggregateWindow($oAcc->finalize(), $oAcc->getLastStatus(), time());
        }
    } catch (\Throwable) {}
    try { $oHistory->releaseCollectorLease($sOwner); } catch (\Throwable) {}
});

while ($bRunning) {
    $nLoopStart = microtime(true);
    $nBucket = intdiv((int)$nLoopStart, HistoryService::RES_5M) * HistoryService::RES_5M;

    if ($nBucket !== $nCurrentBucket) {
        try { $fnFlush(); } catch (\Throwable) {}
        $nCurrentBucket = $nBucket;
        $oAcc = new FastCollectorAccumulator($nCurrentBucket, HistoryService::RES_5M);
    }

    try {
        $vStatus = $oLinea->statusForHistory();
        $nWall = microtime(true);
        $vDt = max(0.0, min(2.5, $nWall - $nLastWall));
        $nLastWall = $nWall;
        $oAcc->add($vStatus, $vDt);
        $nPollOk++;
        $sLastPollError = null;

        // Ring buffer is deliberately in memory only; the database receives
        // only 5-minute aggregate rows.
        $aRing[$nRingPos] = [
            'ts' => $nWall,
            'stale' => (bool)($vStatus['system']['stale'] ?? false),
            'pvW' => $vStatus['energy']['pv']['powerW'] ?? null,
            'houseW' => $vStatus['energy']['house']['powerW'] ?? null,
            'gridW' => $vStatus['energy']['grid']['powerW'] ?? null,
            'batteryW' => $vStatus['energy']['battery']['powerW'] ?? null,
            'socPct' => $vStatus['energy']['battery']['socPct'] ?? null,
        ];
        $nRingPos = ($nRingPos + 1) % 3600;
        $nRingCount = min(3600, $nRingCount + 1);

        // Event state is evaluated every second in RAM, but persisted only
        // when a relevant state actually changes.
        $aEventState = [
            $vStatus['system']['stale'] ?? null,
            $vStatus['ess']['decision']['exportAllowed'] ?? null,
            $vStatus['ess']['decision']['predictionActive'] ?? null,
            ($vStatus['ups']['available'] ?? false) ? ($vStatus['ups']['data']['onBattery'] ?? null) : null,
            ($vStatus['ups']['available'] ?? false) ? ($vStatus['ups']['data']['status']['lowBattery'] ?? null) : null,
        ];
        if (($vStatus['shelly']['available'] ?? false) === true) {
            foreach (($vStatus['shelly']['data']['smokeDetectors'] ?? []) as $vD) $aEventState[] = [$vD['name'] ?? '', $vD['alarm'] ?? null];
        }
        if (($vStatus['climate']['available'] ?? false) === true) {
            foreach (($vStatus['climate']['data']['devices'] ?? []) as $vD) $aEventState[] = [$vD['name'] ?? '', $vD['error'] ?? null];
        }
        $vFingerprint = sha1(json_encode($aEventState, JSON_UNESCAPED_UNICODE) ?: '');
        if ($vFingerprint !== $vLastEventFingerprint) {
            $oHistory->trackEventsFromStatus($vStatus, (int)$nWall);
            $vLastEventFingerprint = $vFingerprint;
        }
    } catch (\Throwable $oE) {
        $nPollErrors++;
        $sLastPollError = 'poll: ' . mb_substr($oE->getMessage(), 0, 500);
        // Do not integrate the missing time. Next successful poll is clamped
        // to max 2.5 s, so an outage cannot create fictitious energy.
        $nLastWall = microtime(true);
    }

    $nNow = time();
    if (($nNow - $nLastHeartbeat) >= 10) {
        if (!$oHistory->isEnabled()) break;
        $bOk = $oHistory->renewCollectorLease($sOwner, $nNow, [
            'last_poll_ts' => $nNow,
            'poll_ok' => $nPollOk,
            'poll_errors' => $nPollErrors,
            'buffer_count' => $nRingCount,
            'current_bucket' => $nCurrentBucket,
            'last_error' => $sLastFlushError ?? $sLastPollError,
        ]);
        if (!$bOk) break;
        $nLastHeartbeat = $nNow;
    }

    $nElapsed = microtime(true) - $nLoopStart;
    $nSleepUs = (int)max(0, round((1.0 - $nElapsed) * 1_000_000));
    if ($nSleepUs > 0) usleep($nSleepUs);
}

try { $fnFlush(); } catch (\Throwable) {}
try { $oHistory->releaseCollectorLease($sOwner); } catch (\Throwable) {}
