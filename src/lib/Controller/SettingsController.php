<?php

/**
 * Hacesoft GridSight – typová legenda proměnných (maďarská notace).
 * Historie: 2026-11-21 – bezpečné sjednocení interních lokálních proměnných.
 * Historie: 2026-11-22 – ověření typů, významových komentářů a shody názvů vlastností.
 * s – textový řetězec / identifikátor nebo cesta.
 * o – instance objektu nebo výjimka.
 * v – smíšená nebo zatím neurčená hodnota.
 * Výjimka: názvy parametrů PHP metod a veřejných vlastností se nemění,
 * protože jsou součástí rozhraní Nextcloud, DI a pojmenovaných argumentů.
 */

declare(strict_types=1);

namespace OCA\HcGridSight\Controller;

use OCA\HcGridSight\AppInfo\Application;
use OCA\HcGridSight\Service\HistoryService;
use OCA\HcGridSight\Service\LineaApiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

class SettingsController extends Controller {
    private const SETTINGS_NAMESPACE = 'hc_gridsight_main';
    private const CHART_SERIES = ['pv','house','grid','export','battery','soc','spot','batteryTemp','rackTemp','inverter1Temp','inverter2Temp','inverter3Temp','forecastPv','forecastHouse'];

    /**
     * Inicializuje instanci a převezme požadované závislosti.
     *
     * Vstupy: `$request` (IRequest) – HTTP požadavek Nextcloudu; `$linea` (LineaApiService) – klient HTTP rozhraní Node-RED LINEA; `$history` (HistoryService) – přístup k historii a sběrači; `$userSession` (IUserSession) – aktuální uživatelská relace; `$groupManager` (IGroupManager) – správa skupin a přístupových práv; `$config` (IConfig) – konfigurace aplikace Nextcloud.
     * Výstup: `void` – nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací.
     *
     * @param IRequest $request HTTP požadavek Nextcloudu
     * @param LineaApiService $linea klient HTTP rozhraní Node-RED LINEA
     * @param HistoryService $history přístup k historii a sběrači
     * @param IUserSession $userSession aktuální uživatelská relace
     * @param IGroupManager $groupManager správa skupin a přístupových práv
     * @param IConfig $config konfigurace aplikace Nextcloud
     * @return void nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací
     */
    public function __construct(
        IRequest $request,
        private LineaApiService $linea,
        private HistoryService $history,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IConfig $config,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Načte administrátorská a osobní nastavení GridSight.
     *
     * Vstupy: `$namespace` (string) – technický prostor nastavení hc_gridsight_main.
     * Výstup: `DataResponse` – JSON s nastavením LINEA a uživatelskými přepínači grafů.
     *
     * @param string $namespace technický prostor nastavení hc_gridsight_main
     * @return DataResponse JSON s nastavením LINEA a uživatelskými přepínači grafů
     */
    #[NoAdminRequired]
    public function get(string $namespace = ''): DataResponse {
        if ($namespace !== self::SETTINGS_NAMESPACE) {
            return new DataResponse(['error' => 'Invalid settings namespace.'], 400);
        }

        $values = [
            'baseUrl' => $this->linea->getBaseUrl(),
            'refreshSeconds' => $this->linea->getRefreshSeconds(),
            'historyEnabled' => $this->history->isEnabled(),
            'canConfigure' => $this->isAdmin(),
        ];
        $sUserId = $this->userSession->getUser()?->getUID();
         $values['history3dInvert'] = $sUserId !== null && $this->config->getUserValue($sUserId, Application::APP_ID, 'history3dInvert', '0') === '1';
        foreach (self::CHART_SERIES as $vSeries) {
            $values['chart_' . $vSeries] = $sUserId === null ||
                $this->config->getUserValue($sUserId, Application::APP_ID, 'chart_' . $vSeries, '1') !== '0';
        }
        return new DataResponse(['values' => $values]);
    }

    /**
     * Ověří oprávnění a uloží požadované nastavení GridSight.
     *
     * Vstupy: `$values` (array) – mapa ukládaných nastavení nebo diagnostiky; `$namespace` (string) – technický prostor nastavení hc_gridsight_main.
     * Výstup: `DataResponse` – JSON s potvrzeným nastavením nebo chybou přístupových práv.
     *
     * @param array $values mapa ukládaných nastavení nebo diagnostiky
     * @param string $namespace technický prostor nastavení hc_gridsight_main
     * @return DataResponse JSON s potvrzeným nastavením nebo chybou přístupových práv
     */
    #[NoAdminRequired]
    public function save(array $values = [], string $namespace = ''): DataResponse {
        if ($namespace !== self::SETTINGS_NAMESPACE) {
            return new DataResponse(['error' => 'Invalid settings namespace.'], 400);
        }

        try {
            $sUserId = $this->userSession->getUser()?->getUID();
            if ($sUserId === null) return new DataResponse(['error' => 'A user session is required.'], 401);
            if (!$this->isAdmin() && (array_key_exists('baseUrl', $values) || array_key_exists('historyEnabled', $values))) {
                return new DataResponse(['error' => 'Administrator privileges are required for global LINEA settings.'], 403);
            }
            if (array_key_exists('history3dInvert', $values)) {
                if (!is_bool($values['history3dInvert'])) throw new \InvalidArgumentException('Invalid 3D mouse preference');
                $this->config->setUserValue($sUserId, Application::APP_ID, 'history3dInvert', $values['history3dInvert'] ? '1' : '0');
            }
            foreach (self::CHART_SERIES as $vSeries) {
                $sKey = 'chart_' . $vSeries;
                if (!array_key_exists($sKey, $values)) continue;
                if (!is_bool($values[$sKey])) throw new \InvalidArgumentException('Invalid chart series preference: ' . $sKey);
            }
            foreach (self::CHART_SERIES as $vSeries) {
                $sKey = 'chart_' . $vSeries;
                if (!array_key_exists($sKey, $values)) continue;
                $this->config->setUserValue($sUserId, Application::APP_ID, $sKey, $values[$sKey] ? '1' : '0');
            }
            if (array_key_exists('refreshSeconds', $values)) {
                $this->linea->setRefreshSeconds((int)$values['refreshSeconds']);
            }

            if ($this->isAdmin()) {
                if (array_key_exists('baseUrl', $values)) {
                    $this->linea->setBaseUrl((string)$values['baseUrl']);
                }
                if (array_key_exists('historyEnabled', $values)) {
                    $this->history->setEnabled((bool)$values['historyEnabled']);
                }
            }

            return $this->get(self::SETTINGS_NAMESPACE);
        } catch (\Throwable $oE) {
            return new DataResponse(['error' => $oE->getMessage()], 400);
        }
    }

    /**
     * Provede test připojení k zadanému nebo uloženému LINEA API.
     *
     * Vstupy: `$baseUrl` (string) – základní URL LINEA pro kontrolu spojení.
     * Výstup: `DataResponse` – JSON s výsledkem ověření LINEA health na požadované URL.
     *
     * @param string $baseUrl základní URL LINEA pro kontrolu spojení
     * @return DataResponse JSON s výsledkem ověření LINEA health na požadované URL
     */
    #[NoAdminRequired]
    public function testConnection(string $baseUrl = ''): DataResponse {
        if (!$this->isAdmin()) {
            return new DataResponse(['ok' => false, 'error' => 'Administrator privileges are required.'], 403);
        }

        try {
            $vHealth = trim($baseUrl) !== '' ? $this->linea->healthAt($baseUrl) : $this->linea->health();
            return new DataResponse(['ok' => true, 'health' => $vHealth]);
        } catch (\Throwable $oE) {
            return new DataResponse(['ok' => false, 'error' => $oE->getMessage()], 502);
        }
    }

    /**
     * Ověří oprávnění přihlášeného uživatele ke správě společných dat.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `bool` – true pouze u uživatele oprávněného provádět administrátorské změny.
     * @return bool true pouze u uživatele oprávněného provádět administrátorské změny
     */
    private function isAdmin(): bool {
        $sUserId = $this->userSession->getUser()?->getUID();
        return $sUserId !== null && $this->groupManager->isAdmin($sUserId);
    }
}
