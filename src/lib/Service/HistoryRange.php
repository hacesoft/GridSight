<?php

declare(strict_types=1);
namespace OCA\HcGridSight\Service;

/** Calendar boundaries always use the connection's Prague timezone. */
final class HistoryRange {
    public static function bounds(string $range, int $now, int $earliest = 0): array {
        $date=(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('Europe/Prague'));
        $midnight=$date->setTime(0,0);
        $to=$now;
        $from=match ($range) {
            'today'=>$midnight->getTimestamp(),
            'yesterday'=>$midnight->modify('-1 day')->getTimestamp(),
            'this-half-year'=>$date->setDate((int)$date->format('Y'), (int)$date->format('n')<=6?1:7,1)->setTime(0,0)->getTimestamp(),
            'this-year'=>$date->setDate((int)$date->format('Y'),1,1)->setTime(0,0)->getTimestamp(),
            '3m'=>self::monthsBack($date,3)->getTimestamp(),
            '6m'=>self::monthsBack($date,6)->getTimestamp(),
            '12m'=>self::monthsBack($date,12)->getTimestamp(),
            '2y'=>self::monthsBack($date,24)->getTimestamp(),
            'all'=>$earliest>0?min($earliest,$now):$midnight->getTimestamp(),
            '1h'=>$now-3600,
            '6h'=>$now-21600,
            '7d'=>$now-604800,
            '30d'=>$now-2592000,
            default=>$now-86400,
        };
        if ($range==='yesterday') $to=$midnight->getTimestamp()-1;
        return ['from'=>$from,'to'=>$to];
    }

    /** Clamp e.g. May 31 minus three months to February's last day. */
    private static function monthsBack(\DateTimeImmutable $date, int $months): \DateTimeImmutable {
        $target=$date->setDate((int)$date->format('Y'),(int)$date->format('n'),1)->modify('-'.$months.' months');
        return $target->setDate((int)$target->format('Y'),(int)$target->format('n'),min((int)$date->format('j'),(int)$target->format('t')));
    }
}
