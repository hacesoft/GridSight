<?php

/**
 * Hacesoft GridSight – typová legenda proměnných (maďarská notace).
 * Historie: 2026-11-21 – bezpečné sjednocení interních lokálních proměnných.
 * Historie: 2026-11-22 – ověření typů, významových komentářů a shody názvů vlastností.
 * n – číslo (celé, desetinné, čas nebo jednotky).
 * o – instance objektu nebo výjimka.
 * v – smíšená nebo zatím neurčená hodnota.
 * Výjimka: názvy parametrů PHP metod a veřejných vlastností se nemění,
 * protože jsou součástí rozhraní Nextcloud, DI a pojmenovaných argumentů.
 */

declare(strict_types=1);

namespace OCA\HcGridSight\Service;

use OCA\HcGridSight\AppInfo\Application;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class HistoryCollectorService {
    /**
     * Inicializuje instanci a převezme požadované závislosti.
     *
     * Vstupy: `$linea` (LineaApiService) – klient HTTP rozhraní Node-RED LINEA; `$history` (HistoryService) – přístup k historii a sběrači; `$config` (IConfig) – konfigurace aplikace Nextcloud; `$logger` (LoggerInterface) – záznam provozních událostí.
     * Výstup: `void` – nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací.
     *
     * @param LineaApiService $linea klient HTTP rozhraní Node-RED LINEA
     * @param HistoryService $history přístup k historii a sběrači
     * @param IConfig $config konfigurace aplikace Nextcloud
     * @param LoggerInterface $logger záznam provozních událostí
     * @return void nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací
     */
    public function __construct(
        private LineaApiService $linea,
        private HistoryService $history,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * Zpracuje aktuální měření a provede uložení dokončeného časového bloku.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `array` – stav sběru včetně úspěchu a případné diagnostiky; ukládání provádí HistoryService.
     * @return array stav sběru včetně úspěchu a případné diagnostiky; ukládání provádí HistoryService
     */
    public function collect(): array {
        if (!$this->history->isEnabled()) return ['ok'=>true,'stored'=>false,'disabled'=>true];
        try {
            $vStatus = $this->linea->statusForHistory();
            $vStored = $this->history->storeSnapshot($vStatus);
            $this->markAvailability(true);
            return ['ok'=>true,'stored'=>$vStored,'collector'=>$this->history->info()];
        } catch (\Throwable $oE) {
            $this->markAvailability(false, $oE->getMessage());
            $this->logger->warning('LINEA history collector failed', ['exception'=>$oE]);
            return ['ok'=>false,'stored'=>false,'error'=>$oE->getMessage(),'collector'=>$this->history->info()];
        }
    }

    /**
     * Uloží informaci o dostupnosti nebo stáří měřených dat.
     *
     * Vstupy: `$online` (bool) – stav dostupnosti LINEA; `$error` (?string) – volitelný popis poslední chyby LINEA.
     * Výstup: `void` – zapíše stav dostupnosti LINEA do konfigurace a událostí; nevrací hodnotu.
     *
     * @param bool $online stav dostupnosti LINEA
     * @param ?string $error volitelný popis poslední chyby LINEA
     * @return void zapíše stav dostupnosti LINEA do konfigurace a událostí; nevrací hodnotu
     */
    private function markAvailability(bool $online, ?string $error = null): void {
        $vOld = $this->config->getAppValue(Application::APP_ID, 'collector_api_online', 'unknown');
        $sNew = $online ? '1' : '0';
        $this->config->setAppValue(Application::APP_ID, 'collector_api_online', $sNew);
        if ($error !== null) $this->config->setAppValue(Application::APP_ID, 'collector_last_error', mb_substr($error, 0, 500));
        elseif ($online) $this->config->deleteAppValue(Application::APP_ID, 'collector_last_error');
        // Online/offline event can be added later without coupling collection failures to DB writes.
    }
}
