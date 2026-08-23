<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/DB.php';
require_once __DIR__ . '/../src/Finance.php';

// ── Routing ───────────────────────────────────────────────────────
$validPages = ['dashboard', 'files', 'compare', 'settings'];
$page = $_GET['page'] ?? 'dashboard';
if (!in_array($page, $validPages, true)) $page = 'dashboard';

// ── Data pro šablonu ──────────────────────────────────────────────
[$dateMin, $dateMax] = DB::dateRange();
$dateMin  = $dateMin ? substr($dateMin, 0, 10) : date('Y-m-01');
$dateMax  = $dateMax ? substr($dateMax, 0, 10) : date('Y-m-d');
$settings = DB::allSettings();

// ── Render ────────────────────────────────────────────────────────
require __DIR__ . '/../views/layout.php';
