<?php

declare(strict_types=1);

namespace OCA\HcGridSight\BackgroundJob;

use OCA\HcGridSight\Service\CollectorSupervisorService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Lightweight watchdog for the 1-second history collector daemon.
 *
 * Nextcloud 35 TimedJob requires ITimeFactory to be passed to the parent
 * constructor. The previous 0.2.1 implementation called parent::__construct()
 * without it, which caused cron.php to fail with ArgumentCountError.
 */
class CollectorWatchdogJob extends TimedJob {
    /**
     * Inicializuje instanci a převezme požadované závislosti.
     *
     * Vstupy: `$time` (ITimeFactory) – továrna časových hodnot Nextcloudu; `$supervisor` (CollectorSupervisorService) – dohled a spouštění sběrače.
     * Výstup: `void` – nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací.
     *
     * @param ITimeFactory $time továrna časových hodnot Nextcloudu
     * @param CollectorSupervisorService $supervisor dohled a spouštění sběrače
     * @return void nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací
     */
    public function __construct(
        ITimeFactory $time,
        private CollectorSupervisorService $supervisor,
    ) {
        parent::__construct($time);
        $this->setInterval(300);
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
        $this->supervisor->ensureRunning();
    }
}
