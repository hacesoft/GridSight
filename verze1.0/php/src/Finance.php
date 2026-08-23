<?php
declare(strict_types=1);

/**
 * GridSight · Finance.php
 * ══════════════════════════════════════════════════════════
 * Výpočet finanční bilance FVE instalace.
 *
 * Veličiny:
 *   spotreba    = kWh odebráno ze sítě    (ze smart metru DCC0)
 *   dodavka     = kWh dodáno do sítě      (ze smart metru DSC0, = FVE přebytek)
 *   fve_total   = kWh vyrobeno FVE        (z MPPT yield_total – nebo 0 pokud nedostupné)
 *   own_cons    = kWh FVE spotřebováno doma  = fve_total – dodavka
 *
 * Finance:
 *   naklady     = spotreba × tariff_import
 *   prijem      = dodavka  × tariff_export
 *   uspora      = own_cons × tariff_own     (ušetřená cena za neodebranou elektřinu)
 *   hodnota     = prijem + uspora           (celková hodnota FVE za období)
 *   bilance     = hodnota – naklady         (co jsi získal/ztratil oproti síti)
 */
class Finance
{
    public static function calculate(array $totals, array $mpptTotals): array
    {
        $imp = (float) DB::get('tariff_import', '5.50');
        $exp = (float) DB::get('tariff_export', '2.30');
        $own = (float) DB::get('tariff_own',    '5.50');

        $spotreba = (float)($totals['spotreba'] ?? 0);
        $dodavka  = (float)($totals['dodavka']  ?? 0);
        $days     = (int)($totals['days']        ?? 0);

        // FVE výroba z MPPT trackerů (yield_kwh = max(total)-min(total) za období)
        $fveTotal = 0.0;
        foreach ($mpptTotals as $t) $fveTotal += (float)($t['yield_kwh'] ?? 0);

        // Vlastní spotřeba FVE = co nevyšlo do sítě
        $ownCons = $fveTotal > 0 ? max(0.0, $fveTotal - $dodavka) : null;

        $naklady  = round($spotreba * $imp, 1);
        $prijem   = round($dodavka  * $exp, 1);
        $uspora   = $ownCons !== null ? round($ownCons * $own, 1) : null;
        $hodnota  = round($prijem + ($uspora ?? 0), 1);
        $bilance  = round($hodnota - $naklady, 1);

        // Roční odhad (pokud máme alespoň 7 dní dat)
        $rocniOdhad = $days >= 7
            ? round($bilance / $days * 365, 0)
            : null;

        return [
            'tariff_import'       => $imp,
            'tariff_export'       => $exp,
            'tariff_own'          => $own,
            'spotreba_kwh'        => $spotreba,
            'dodavka_kwh'         => $dodavka,
            'fve_total_kwh'       => $fveTotal,
            'own_consumption_kwh' => $ownCons,
            'naklady_czk'         => $naklady,
            'prijem_czk'          => $prijem,
            'uspora_czk'          => $uspora,
            'hodnota_czk'         => $hodnota,
            'bilance_czk'         => $bilance,
            'rocni_odhad_czk'     => $rocniOdhad,
            'days'                => $days,
        ];
    }
}
