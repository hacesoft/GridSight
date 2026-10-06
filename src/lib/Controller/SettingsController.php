<?php

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
        $userId = $this->userSession->getUser()?->getUID();
        foreach (self::CHART_SERIES as $series) {
            $values['chart_' . $series] = $userId === null ||
                $this->config->getUserValue($userId, Application::APP_ID, 'chart_' . $series, '1') !== '0';
        }
        return new DataResponse(['values' => $values]);
    }

    #[NoAdminRequired]
    public function save(array $values = [], string $namespace = ''): DataResponse {
        if ($namespace !== self::SETTINGS_NAMESPACE) {
            return new DataResponse(['error' => 'Invalid settings namespace.'], 400);
        }

        try {
            $userId = $this->userSession->getUser()?->getUID();
            if ($userId === null) return new DataResponse(['error' => 'A user session is required.'], 401);
            if (!$this->isAdmin() && (array_key_exists('baseUrl', $values) || array_key_exists('historyEnabled', $values))) {
                return new DataResponse(['error' => 'Administrator privileges are required for global LINEA settings.'], 403);
            }
            foreach (self::CHART_SERIES as $series) {
                $key = 'chart_' . $series;
                if (!array_key_exists($key, $values)) continue;
                if (!is_bool($values[$key])) throw new \InvalidArgumentException('Invalid chart series preference: ' . $key);
            }
            foreach (self::CHART_SERIES as $series) {
                $key = 'chart_' . $series;
                if (!array_key_exists($key, $values)) continue;
                $this->config->setUserValue($userId, Application::APP_ID, $key, $values[$key] ? '1' : '0');
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
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[NoAdminRequired]
    public function testConnection(string $baseUrl = ''): DataResponse {
        if (!$this->isAdmin()) {
            return new DataResponse(['ok' => false, 'error' => 'Administrator privileges are required.'], 403);
        }

        try {
            $health = trim($baseUrl) !== '' ? $this->linea->healthAt($baseUrl) : $this->linea->health();
            return new DataResponse(['ok' => true, 'health' => $health]);
        } catch (\Throwable $e) {
            return new DataResponse(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    private function isAdmin(): bool {
        $userId = $this->userSession->getUser()?->getUID();
        return $userId !== null && $this->groupManager->isAdmin($userId);
    }
}
