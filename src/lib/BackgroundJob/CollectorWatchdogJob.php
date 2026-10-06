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
    public function __construct(
        ITimeFactory $time,
        private CollectorSupervisorService $supervisor,
    ) {
        parent::__construct($time);
        $this->setInterval(300);
        $this->setAllowParallelRuns(false);
    }

    protected function run($argument): void {
        $this->supervisor->ensureRunning();
    }
}
