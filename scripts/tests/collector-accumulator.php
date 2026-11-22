<?php
declare(strict_types=1);
/**
 * Regresní test GridSight: maďarská notace nesmí rozpojit deklarace vlastností
 * akumulátoru a jejich použití při zpracování živých měření.
 * Vstup: syntetický stav LINEA; výstup: PASS/FAIL pro agregaci a výnos.
 */
set_error_handler(static function (int $nSeverity, string $sMessage): never {
    throw new ErrorException($sMessage, 0, $nSeverity);
});
require_once __DIR__.'/../../src/lib/Service/ShellyHdo.php';
require_once __DIR__.'/../../src/lib/Service/TemperatureReadings.php';
require_once __DIR__.'/../../src/lib/Service/FastCollectorAccumulator.php';
use OCA\HcGridSight\Service\FastCollectorAccumulator;

/** Ověří porovnanou podmínku a při nesouladu zastaví vydání; vstup bool + text, výstup void. */
function requireTest(bool $bCondition, string $sError): void {
    if (!$bCondition) { throw new RuntimeException('FAIL: '.$sError); }
}
$oAccumulator = new FastCollectorAccumulator(1750000000, 300);
$aStatus = [
  'system' => ['stale'=>false, 'sourceTimestamp'=>'2026-11-22T07:00:00Z'],
  'energy' => [
     'pv'=>['powerW'=>3000],
     'house'=>['powerW'=>2000],
     'grid'=>['powerW'=>-1000],
     'battery'=>['powerW'=>100,'socPct'=>55],
  ],
  'spot'=>['available'=>true,'currentPrice'=>2.5],
];
$oAccumulator->add($aStatus,1.0);
$oAccumulator->add($aStatus,1.0);
$aRow=$oAccumulator->finalize();
requireTest($oAccumulator->getSampleCount()===2,'počet vzorků');
requireTest(abs($oAccumulator->getCoverageSec()-2.0)<0.001,'pokrytí');
requireTest($aRow['grid_export_mwh']===556,'dodávka do sítě v mWh');
requireTest($aRow['sale_microczk']===1389,'výnos v microKč');
requireTest($aRow['spot_hour_x10000']===25000,'oddělená hodinová SPOT cena');
requireTest($aRow['pv_w']===3000,'průměrný výkon FVE');
requireTest($aRow['sample_count']===2,'počet v uložené řádce');

$oStale = new FastCollectorAccumulator(1750000300,300);
$aStatus['system']['stale']=true;
$oStale->add($aStatus,1.0);
requireTest($oStale->getSampleCount()===0,'zastaralý stav se nesmí započítat');
$aStatus['system']['stale']=false;
$aStatus['spot']['available']=false;
$oNoPrice=new FastCollectorAccumulator(1750000600,300);
$oNoPrice->add($aStatus,1.0);
$aUnpriced=$oNoPrice->finalize();
requireTest($aUnpriced['sale_microczk']===0,'bez ceny SPOT není vymyšlený výnos');
requireTest($aUnpriced['unpriced_export_mwh']>0,'export bez ceny musí být označen neoceněně');

$aStatus['spot']=['available'=>true,'currentPrice'=>-2.0];
$oNegative=new FastCollectorAccumulator(1750000900,300);
$oNegative->add($aStatus,1.0);
requireTest($oNegative->finalize()['sale_microczk']<0,'záporná SPOT cena se nesmí převést na kladnou');
print "PASS: agregace, ochrana zastaralých měření, záporná cena, shoda vlastností a jejich použití\n";
