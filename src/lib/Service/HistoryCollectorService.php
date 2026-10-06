<?php

declare(strict_types=1);

namespace OCA\HcGridSight\Service;

use OCA\HcGridSight\AppInfo\Application;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class HistoryCollectorService {
    public function __construct(
        private LineaApiService $linea,
        private HistoryService $history,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {}

    public function collect(): array {
        if (!$this->history->isEnabled()) return ['ok'=>true,'stored'=>false,'disabled'=>true];
        try {
            $status = $this->linea->statusForHistory();
            $stored = $this->history->storeSnapshot($status);
            $this->markAvailability(true);
            return ['ok'=>true,'stored'=>$stored,'collector'=>$this->history->info()];
        } catch (\Throwable $e) {
            $this->markAvailability(false, $e->getMessage());
            $this->logger->warning('LINEA history collector failed', ['exception'=>$e]);
            return ['ok'=>false,'stored'=>false,'error'=>$e->getMessage(),'collector'=>$this->history->info()];
        }
    }

    private function markAvailability(bool $online, ?string $error = null): void {
        $old = $this->config->getAppValue(Application::APP_ID, 'collector_api_online', 'unknown');
        $new = $online ? '1' : '0';
        $this->config->setAppValue(Application::APP_ID, 'collector_api_online', $new);
        if ($error !== null) $this->config->setAppValue(Application::APP_ID, 'collector_last_error', mb_substr($error, 0, 500));
        elseif ($online) $this->config->deleteAppValue(Application::APP_ID, 'collector_last_error');
        // Online/offline event can be added later without coupling collection failures to DB writes.
    }
}
