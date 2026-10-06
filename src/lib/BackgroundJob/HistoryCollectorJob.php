<?php

declare(strict_types=1);

namespace OCA\HcGridSight\BackgroundJob;

use OCA\HcGridSight\Service\HistoryCollectorService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class HistoryCollectorJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private HistoryCollectorService $collector,
    ) {
        parent::__construct($time);
        $this->setInterval(60);
        $this->setAllowParallelRuns(false);
    }

    protected function run($argument): void {
        $this->collector->collect();
    }
}
