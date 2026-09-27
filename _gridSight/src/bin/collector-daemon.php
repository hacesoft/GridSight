<?php

declare(strict_types=1);

/**
 * LINEA Monitor fast collector daemon.
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

$ncRoot = '/var/www/html';
require_once $ncRoot . '/lib/base.php';

use OCA\HcGridSight\Service\FastCollectorAccumulator;
use OCA\HcGridSight\Service\HistoryService;
use OCA\HcGridSight\Service\LineaApiService;

$server = \OC::$server;
/** @var LineaApiService $linea */
$linea = $server->get(LineaApiService::class);
/** @var HistoryService $history */
$history = $server->get(HistoryService::class);

$host = gethostname() ?: 'container';
$owner = $host . ':' . getmypid() . ':' . bin2hex(random_bytes(4));
$now = time();
if (!$history->isEnabled()) {
    fwrite(STDERR, "LINEA history is disabled.\n");
    exit(0);
}
if (!$history->acquireCollectorLease($owner, $now)) {
    fwrite(STDERR, "LINEA fast collector is already running.\n");
    exit(0);
}

$running = true;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

$pollOk = 0;
$pollErrors = 0;
$lastPollError = null;
$lastFlushError = null;
$lastWall = microtime(true);
$lastHeartbeat = 0;
$lastEventFingerprint = null;
$currentBucket = intdiv(time(), HistoryService::RES_5M) * HistoryService::RES_5M;
$acc = new FastCollectorAccumulator($currentBucket, HistoryService::RES_5M);

// One-hour ring buffer: 3600 snapshots maximum. It exists only in RAM.
$ring = array_fill(0, 3600, null);
$ringPos = 0;
$ringCount = 0;

$flush = static function () use (&$acc, $history, &$lastFlushError): bool {
    $lastStatus = $acc->getLastStatus();
    if ($lastStatus === null || $acc->getSampleCount() === 0) {
        $lastFlushError = 'flush skipped: no valid non-stale samples in completed 5-minute window';
        fwrite(STDERR, '[' . date('c') . "] $lastFlushError\n");
        return false;
    }
    try {
        $stored = $history->storeAggregateWindow($acc->finalize(), $lastStatus, time());
        $lastFlushError = null;
        fwrite(STDOUT, '[' . date('c') . '] 5-minute aggregate ' . ($stored ? 'stored' : 'already present') . ' for bucket ' . $acc->getBucketTs() . "\n");
        return $stored;
    } catch (\Throwable $e) {
        $lastFlushError = 'flush: ' . mb_substr($e->getMessage(), 0, 500);
        fwrite(STDERR, '[' . date('c') . "] $lastFlushError\n");
        throw $e;
    }
};

register_shutdown_function(static function () use (&$acc, $history, $owner): void {
    try {
        if ($acc->getLastStatus() !== null && $acc->getCoverageSec() >= 30.0) {
            $history->storeAggregateWindow($acc->finalize(), $acc->getLastStatus(), time());
        }
    } catch (\Throwable) {}
    try { $history->releaseCollectorLease($owner); } catch (\Throwable) {}
});

while ($running) {
    $loopStart = microtime(true);
    $bucket = intdiv((int)$loopStart, HistoryService::RES_5M) * HistoryService::RES_5M;

    if ($bucket !== $currentBucket) {
        try { $flush(); } catch (\Throwable) {}
        $currentBucket = $bucket;
        $acc = new FastCollectorAccumulator($currentBucket, HistoryService::RES_5M);
    }

    try {
        $status = $linea->statusForHistory();
        $wall = microtime(true);
        $dt = max(0.0, min(2.5, $wall - $lastWall));
        $lastWall = $wall;
        $acc->add($status, $dt);
        $pollOk++;
        $lastPollError = null;

        // Ring buffer is deliberately in memory only; the database receives
        // only 5-minute aggregate rows.
        $ring[$ringPos] = [
            'ts' => $wall,
            'stale' => (bool)($status['system']['stale'] ?? false),
            'pvW' => $status['energy']['pv']['powerW'] ?? null,
            'houseW' => $status['energy']['house']['powerW'] ?? null,
            'gridW' => $status['energy']['grid']['powerW'] ?? null,
            'batteryW' => $status['energy']['battery']['powerW'] ?? null,
            'socPct' => $status['energy']['battery']['socPct'] ?? null,
        ];
        $ringPos = ($ringPos + 1) % 3600;
        $ringCount = min(3600, $ringCount + 1);

        // Event state is evaluated every second in RAM, but persisted only
        // when a relevant state actually changes.
        $eventState = [
            $status['system']['stale'] ?? null,
            $status['ess']['decision']['exportAllowed'] ?? null,
            $status['ess']['decision']['predictionActive'] ?? null,
            ($status['ups']['available'] ?? false) ? ($status['ups']['data']['onBattery'] ?? null) : null,
            ($status['ups']['available'] ?? false) ? ($status['ups']['data']['status']['lowBattery'] ?? null) : null,
        ];
        if (($status['shelly']['available'] ?? false) === true) {
            foreach (($status['shelly']['data']['smokeDetectors'] ?? []) as $d) $eventState[] = [$d['name'] ?? '', $d['alarm'] ?? null];
        }
        if (($status['climate']['available'] ?? false) === true) {
            foreach (($status['climate']['data']['devices'] ?? []) as $d) $eventState[] = [$d['name'] ?? '', $d['error'] ?? null];
        }
        $fingerprint = sha1(json_encode($eventState, JSON_UNESCAPED_UNICODE) ?: '');
        if ($fingerprint !== $lastEventFingerprint) {
            $history->trackEventsFromStatus($status, (int)$wall);
            $lastEventFingerprint = $fingerprint;
        }
    } catch (\Throwable $e) {
        $pollErrors++;
        $lastPollError = 'poll: ' . mb_substr($e->getMessage(), 0, 500);
        // Do not integrate the missing time. Next successful poll is clamped
        // to max 2.5 s, so an outage cannot create fictitious energy.
        $lastWall = microtime(true);
    }

    $now = time();
    if (($now - $lastHeartbeat) >= 10) {
        if (!$history->isEnabled()) break;
        $ok = $history->renewCollectorLease($owner, $now, [
            'last_poll_ts' => $now,
            'poll_ok' => $pollOk,
            'poll_errors' => $pollErrors,
            'buffer_count' => $ringCount,
            'current_bucket' => $currentBucket,
            'last_error' => $lastFlushError ?? $lastPollError,
        ]);
        if (!$ok) break;
        $lastHeartbeat = $now;
    }

    $elapsed = microtime(true) - $loopStart;
    $sleepUs = (int)max(0, round((1.0 - $elapsed) * 1_000_000));
    if ($sleepUs > 0) usleep($sleepUs);
}

try { $flush(); } catch (\Throwable) {}
try { $history->releaseCollectorLease($owner); } catch (\Throwable) {}
