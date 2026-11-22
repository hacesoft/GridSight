<?php

/**
 * Hacesoft GridSight – typová legenda proměnných (maďarská notace).
 * Historie: 2026-11-21 – bezpečné sjednocení interních lokálních proměnných.
 * Historie: 2026-11-22 – ověření typů, významových komentářů a shody názvů vlastností.
 * s – textový řetězec / identifikátor nebo cesta.
 * v – smíšená nebo zatím neurčená hodnota.
 * Výjimka: názvy parametrů PHP metod a veřejných vlastností se nemění,
 * protože jsou součástí rozhraní Nextcloud, DI a pojmenovaných argumentů.
 */

declare(strict_types=1);
namespace OCA\HcGridSight\Service;

/** Calendar boundaries always use the connection's Prague timezone. */
final class HistoryRange {
    /**
     * Spočítá časové hranice historie s ohledem na český kalendářní den a vybrané období.
     *
     * Vstupy: `$range` (string) – symbolický interval historie (today, 3m, all aj.); `$now` (int) – aktuální unixový čas (s); `$earliest` (int) – nejstarší dostupný historický unixový čas (s).
     * Výstup: `array` – hranice from/to jako unixové sekundy pro požadované období.
     *
     * @param string $range symbolický interval historie (today, 3m, all aj.)
     * @param int $now aktuální unixový čas (s)
     * @param int $earliest nejstarší dostupný historický unixový čas (s)
     * @return array hranice from/to jako unixové sekundy pro požadované období
     */
    public static function bounds(string $range, int $now, int $earliest = 0): array {
        $date=(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('Europe/Prague'));
        $oMidnight=$date->setTime(0,0);
        $nTo=$now;
        $nFrom=match ($range) {
            'today'=>$oMidnight->getTimestamp(),
            'yesterday'=>$oMidnight->modify('-1 day')->getTimestamp(),
            'this-half-year'=>$date->setDate((int)$date->format('Y'), (int)$date->format('n')<=6?1:7,1)->setTime(0,0)->getTimestamp(),
            'this-year'=>$date->setDate((int)$date->format('Y'),1,1)->setTime(0,0)->getTimestamp(),
            '3m'=>self::monthsBack($date,3)->getTimestamp(),
            '6m'=>self::monthsBack($date,6)->getTimestamp(),
            '12m'=>self::monthsBack($date,12)->getTimestamp(),
            '2y'=>self::monthsBack($date,24)->getTimestamp(),
            'all'=>$earliest>0?min($earliest,$now):$oMidnight->getTimestamp(),
            '1h'=>$now-3600,
            '6h'=>$now-21600,
            '7d'=>$now-604800,
            '30d'=>$now-2592000,
            default=>$now-86400,
        };
        if ($range==='yesterday') $nTo=$oMidnight->getTimestamp()-1;
        return ['from'=>$nFrom,'to'=>$nTo];
    }

    /** Clamp e.g. May 31 minus three months to February's last day. */
    /**
     * Posune datum o celé měsíce a upraví den tak, aby vždy existoval v cílovém měsíci.
     *
     * Vstupy: `$date` (\DateTimeImmutable) – datum YYYY-MM-DD nebo datový objekt dle typu; `$months` (int) – počet kalendářních měsíců k odečtení.
     * Výstup: `\DateTimeImmutable` – datum posunuté o zadané měsíce s korekcí posledního dne měsíce.
     *
     * @param \DateTimeImmutable $date datum YYYY-MM-DD nebo datový objekt dle typu
     * @param int $months počet kalendářních měsíců k odečtení
     * @return \DateTimeImmutable datum posunuté o zadané měsíce s korekcí posledního dne měsíce
     */
    private static function monthsBack(\DateTimeImmutable $date, int $months): \DateTimeImmutable {
        $oTarget=$date->setDate((int)$date->format('Y'),(int)$date->format('n'),1)->modify('-'.$months.' months');
        return $oTarget->setDate((int)$oTarget->format('Y'),(int)$oTarget->format('n'),min((int)$date->format('j'),(int)$oTarget->format('t')));
    }
}
