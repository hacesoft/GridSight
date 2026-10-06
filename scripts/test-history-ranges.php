<?php
// Run with PHP 8.3+: php scripts/test-history-ranges.php
require __DIR__.'/../src/lib/Service/HistoryRange.php';
use OCA\HcGridSight\Service\HistoryRange;
$zone=new DateTimeZone('Europe/Prague');
$check=static function(string $now,string $range,string $from,string $to) use ($zone): void {
    $bounds=HistoryRange::bounds($range,(new DateTimeImmutable($now,$zone))->getTimestamp());
    foreach (['from'=>$from,'to'=>$to] as $key=>$expected) {
        $actual=(new DateTimeImmutable('@'.$bounds[$key]))->setTimezone($zone)->format('Y-m-d H:i:s');
        if ($actual!==$expected) throw new RuntimeException("$range $key: $actual != $expected");
    }
};
$check('2026-10-05 09:27:33','today','2026-10-05 00:00:00','2026-10-05 09:27:33');
$check('2026-10-05 09:27:33','yesterday','2026-10-04 00:00:00','2026-10-04 23:59:59');
$check('2026-10-05 09:27:33','this-year','2026-01-01 00:00:00','2026-10-05 09:27:33');
$check('2026-10-05 09:27:33','12m','2025-10-05 09:27:33','2026-10-05 09:27:33');
$check('2026-10-05 09:27:33','this-half-year','2026-07-01 00:00:00','2026-10-05 09:27:33');
$check('2026-05-31 12:00:00','3m','2026-02-28 12:00:00','2026-05-31 12:00:00');
$check('2024-02-29 12:00:00','12m','2023-02-28 12:00:00','2024-02-29 12:00:00');
foreach (['2026-03-30 12:00:00'=>23,'2026-10-26 12:00:00'=>25] as $now=>$hours) {
    $bounds=HistoryRange::bounds('yesterday',(new DateTimeImmutable($now,$zone))->getTimestamp());
    if ($bounds['to']-$bounds['from']+1!==$hours*3600) throw new RuntimeException('DST day length');
}
$bounds=HistoryRange::bounds('all',1791185253,1700000000);
if ($bounds['from']!==1700000000) throw new RuntimeException('All must begin at earliest retained record.');
echo "PASS: calendar/rolling ranges, month-end clamping, DST and all history\n";
