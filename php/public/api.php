<?php
declare(strict_types=1);

// ── Autoload + třídy ──────────────────────────────────────────────
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/DB.php';
require_once __DIR__ . '/../src/Parser.php';
require_once __DIR__ . '/../src/Finance.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    $result = match($action) {
        'data'         => apiData(),
        'compare'      => apiCompare(),
        'range'        => apiRange(),
        'files'        => apiFiles(),
        'upload'       => apiUpload(),
        'delete_file'  => apiDeleteFile(),
        'settings'     => $method === 'POST' ? apiSaveSettings() : apiGetSettings(),
        'modbus_status'=> apiModbusStatus(),
        default        => throw new \InvalidArgumentException("Neznámá akce: '{$action}'"),
    };
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

// ──────────────────────────────────────────────────────────────────
// API handlers
// ──────────────────────────────────────────────────────────────────

function apiData(): array
{
    $from  = $_GET['from']  ?? '2000-01-01T00:00:00';
    $to    = $_GET['to']    ?? '2099-12-31T23:59:59';
    $group = $_GET['group'] ?? 'day';

    $totals   = DB::totals($from, $to);
    $mpptTots = DB::mpptTotals($from, $to);
    $hasMppt  = DB::hasMppt($from, $to);

    return [
        'grid'        => DB::queryGrid($from, $to, $group),
        'hourly'      => DB::queryHourly($from, $to),
        'totals'      => $totals,
        'mppt'        => $hasMppt ? DB::queryMppt($from, $to, $group)   : [],
        'system'      => $hasMppt ? DB::querySystem($from, $to, $group) : [],
        'mppt_totals' => $mpptTots,
        'finance'     => Finance::calculate($totals, $mpptTots),
        'has_mppt'    => $hasMppt,
    ];
}

function apiCompare(): array
{
    $aFrom = $_GET['a_from'] ?? ''; $aTo = $_GET['a_to'] ?? '';
    $bFrom = $_GET['b_from'] ?? ''; $bTo = $_GET['b_to'] ?? '';
    $group = $_GET['group']  ?? 'day';

    if (!$aFrom || !$aTo || !$bFrom || !$bTo) {
        throw new \InvalidArgumentException('Chybí parametry: a_from, a_to, b_from, b_to');
    }

    $period = function(string $f, string $t) use ($group): array {
        [$f2, $t2] = ["{$f}T00:00:00", "{$t}T23:59:59"];
        $tot = DB::totals($f2, $t2);
        $mt  = DB::mpptTotals($f2, $t2);
        return [
            'label'       => "{$f} – {$t}",
            'totals'      => $tot,
            'mppt_totals' => $mt,
            'grid'        => DB::queryGrid($f2, $t2, $group),
            'finance'     => Finance::calculate($tot, $mt),
        ];
    };

    return ['A' => $period($aFrom, $aTo), 'B' => $period($bFrom, $bTo)];
}

function apiRange(): array
{
    [$min, $max] = DB::dateRange();
    return ['min' => $min, 'max' => $max];
}

function apiFiles(): array
{
    return DB::listFiles();
}

function apiUpload(): array
{
    if (empty($_FILES['file'])) {
        throw new \RuntimeException('Žádný soubor nebyl nahrán');
    }
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new \RuntimeException('Chyba při nahrávání souboru (kód ' . $f['error'] . ')');
    }

    $fname = basename($f['name']);
    if (!str_ends_with(strtolower($fname), '.xlsx')) {
        throw new \RuntimeException('Podporovaný formát: REP_DATA_*.xlsx');
    }

    $dataDir = rtrim(getenv('DATA_DIR') ?: '/data', '/');
    $dest    = "{$dataDir}/{$fname}";

    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        throw new \RuntimeException("Nepodařilo se uložit soubor do {$dataDir}");
    }

    $parsed = Parser::parseXlsx($dest);
    $count  = DB::upsertGrid($parsed['rows'], $fname);
    DB::registerFile($fname, $count, $parsed['from'], $parsed['to']);

    return [
        'ok'       => true,
        'filename' => $fname,
        'records'  => $count,
        'from'     => $parsed['from'],
        'to'       => $parsed['to'],
    ];
}

function apiDeleteFile(): array
{
    $fn = $_GET['filename'] ?? '';
    if (!$fn) throw new \InvalidArgumentException('Chybí parametr filename');
    DB::deleteFile($fn);
    return ['ok' => true];
}

function apiGetSettings(): array
{
    $s = DB::allSettings();
    $s['modbus_last_record'] = DB::lastModbus();
    $s['php_version']        = PHP_VERSION;
    return $s;
}

function apiSaveSettings(): array
{
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $data = array_merge($body, $_POST);
    foreach ($data as $k => $v) {
        DB::set((string)$k, (string)$v);
    }
    return ['ok' => true, 'saved' => count($data)];
}

function apiModbusStatus(): array
{
    return [
        'last_record'    => DB::lastModbus(),
        'cerbo_host'     => DB::get('cerbo_host'),
        'tracker_0_name' => DB::get('tracker_0_name', 'Tracker 1'),
        'tracker_1_name' => DB::get('tracker_1_name', 'Tracker 2'),
    ];
}
