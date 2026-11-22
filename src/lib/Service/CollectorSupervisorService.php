<?php

/**
 * Hacesoft GridSight – typová legenda proměnných (maďarská notace).
 * Historie: 2026-11-21 – bezpečné sjednocení interních lokálních proměnných.
 * Historie: 2026-11-22 – ověření typů, významových komentářů a shody názvů vlastností.
 * s – textový řetězec / identifikátor nebo cesta.
 * b – logická hodnota.
 * v – smíšená nebo zatím neurčená hodnota.
 * Výjimka: názvy parametrů PHP metod a veřejných vlastností se nemění,
 * protože jsou součástí rozhraní Nextcloud, DI a pojmenovaných argumentů.
 */

declare(strict_types=1);

namespace OCA\HcGridSight\Service;

use OCA\HcGridSight\AppInfo\Application;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class CollectorSupervisorService {
    /**
     * Inicializuje instanci a převezme požadované závislosti.
     *
     * Vstupy: `$history` (HistoryService) – přístup k historii a sběrači; `$config` (IConfig) – konfigurace aplikace Nextcloud; `$logger` (LoggerInterface) – záznam provozních událostí.
     * Výstup: `void` – nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací.
     *
     * @param HistoryService $history přístup k historii a sběrači
     * @param IConfig $config konfigurace aplikace Nextcloud
     * @param LoggerInterface $logger záznam provozních událostí
     * @return void nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací
     */
    public function __construct(
        private HistoryService $history,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * Zkontroluje živý sběrač a podle potřeby zajistí jeho běh.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `array` – diagnostika a výsledek pokusu o spuštění běžícího sběrače.
     * @return array diagnostika a výsledek pokusu o spuštění běžícího sběrače
     */
    public function ensureRunning(): array {
        if ($this->config->getAppValue(Application::APP_ID, 'collector_supervisor_paused', '0') === '1') {
            return ['ok'=>true,'started'=>false,'reason'=>'install-paused'];
        }
        if (!$this->history->isEnabled()) return ['ok'=>true,'started'=>false,'reason'=>'history-disabled'];
        $vInfo = $this->history->info();
        if (($vInfo['fastCollector']['online'] ?? false) === true) return ['ok'=>true,'started'=>false,'reason'=>'already-online'];

        $sScript = '/var/www/html/custom_apps/' . Application::APP_ID . '/bin/collector-daemon.php';
        if (!is_file($sScript)) return ['ok'=>false,'started'=>false,'reason'=>'collector-script-missing'];

        $aDisabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
        if (!function_exists('exec') || in_array('exec', $aDisabled, true)) {
            $this->config->setAppValue(Application::APP_ID, 'collector_supervisor_error', 'PHP exec() is disabled; start collector from install.sh or container startup.');
            return ['ok'=>false,'started'=>false,'reason'=>'exec-disabled'];
        }

        $sCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($sScript) . ' >>/tmp/hc_gridsight-collector.log 2>&1 </dev/null &';
        @exec($sCmd);
        $this->config->setAppValue(Application::APP_ID, 'collector_supervisor_last_start', (string)time());
        $this->logger->info('LINEA fast history collector start requested by watchdog');
        return ['ok'=>true,'started'=>true];
    }
}
