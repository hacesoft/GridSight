<?php

declare(strict_types=1);

namespace OCA\HcGridSight\BackgroundJob;

use OCA\HcGridSight\Service\HistoryCollectorService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class HistoryCollectorJob extends TimedJob {
    /**
     * Inicializuje instanci a převezme požadované závislosti.
     *
     * Vstupy: `$time` (ITimeFactory) – továrna časových hodnot Nextcloudu; `$collector` (HistoryCollectorService) – služba sběru historie.
     * Výstup: `void` – nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací.
     *
     * @param ITimeFactory $time továrna časových hodnot Nextcloudu
     * @param HistoryCollectorService $collector služba sběru historie
     * @return void nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací
     */
    public function __construct(
        ITimeFactory $time,
        private HistoryCollectorService $collector,
    ) {
        parent::__construct($time);
        $this->setInterval(60);
        $this->setAllowParallelRuns(false);
    }

    /**
     * Spustí danou naplánovanou úlohu a provede dohled nad sběrem.
     *
     * Vstupy: `$argument` (mixed) – volitelný argument plánované úlohy Nextcloud.
     * Výstup: `void` – provede naplánovaný běh sběrače či watchdogu; nevrací hodnotu.
     *
     * @param mixed $argument volitelný argument plánované úlohy Nextcloud
     * @return void provede naplánovaný běh sběrače či watchdogu; nevrací hodnotu
     */
    protected function run($argument): void {
        $this->collector->collect();
    }
}
