<?php

declare(strict_types=1);

namespace OCA\HcGridSight\Service;

use OCA\HcGridSight\AppInfo\Application;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class CollectorSupervisorService {
    public function __construct(
        private HistoryService $history,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {}

    public function ensureRunning(): array {
        if ($this->config->getAppValue(Application::APP_ID, 'collector_supervisor_paused', '0') === '1') {
            return ['ok'=>true,'started'=>false,'reason'=>'install-paused'];
        }
        if (!$this->history->isEnabled()) return ['ok'=>true,'started'=>false,'reason'=>'history-disabled'];
        $info = $this->history->info();
        if (($info['fastCollector']['online'] ?? false) === true) return ['ok'=>true,'started'=>false,'reason'=>'already-online'];

        $script = '/var/www/html/custom_apps/' . Application::APP_ID . '/bin/collector-daemon.php';
        if (!is_file($script)) return ['ok'=>false,'started'=>false,'reason'=>'collector-script-missing'];

        $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
        if (!function_exists('exec') || in_array('exec', $disabled, true)) {
            $this->config->setAppValue(Application::APP_ID, 'collector_supervisor_error', 'PHP exec() is disabled; start collector from install.sh or container startup.');
            return ['ok'=>false,'started'=>false,'reason'=>'exec-disabled'];
        }

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' >>/tmp/hc_gridsight-collector.log 2>&1 </dev/null &';
        @exec($cmd);
        $this->config->setAppValue(Application::APP_ID, 'collector_supervisor_last_start', (string)time());
        $this->logger->info('LINEA fast history collector start requested by watchdog');
        return ['ok'=>true,'started'=>true];
    }
}
