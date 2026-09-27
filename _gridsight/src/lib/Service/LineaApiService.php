<?php

declare(strict_types=1);

namespace OCA\HcGridSight\Service;

use OCA\HcGridSight\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUserSession;
use RuntimeException;

class LineaApiService {
    private const DEFAULT_URL = 'http://node-red:1880';

    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
        private IUserSession $userSession,
    ) {}

    public function getBaseUrl(): string {
        $value = trim($this->config->getAppValue(Application::APP_ID, 'linea_base_url', ''));
        if ($value === '') {
            $value = trim($this->config->getAppValue(Application::APP_ID, 'history_base_url', ''));
        }
        // Původní App ID zůstává po ručním přejmenování tabulek vypnuté.
        // Čtení jeho nastavení zachová LINEA URL bez zásahu instalátoru do DB.
        if ($value === '') {
            $value = trim($this->config->getAppValue('lineamonitor', 'linea_base_url', ''));
        }
        if ($value === '') {
            $value = trim($this->config->getAppValue('lineamonitor', 'history_base_url', self::DEFAULT_URL));
        }
        return rtrim($value !== '' ? $value : self::DEFAULT_URL, '/');
    }

    public function setBaseUrl(string $url): void {
        $url = $this->validateUrl($url);
        $this->config->setAppValue(Application::APP_ID, 'linea_base_url', $url);
        // Keep the legacy key synchronized for rollback compatibility with 0.6.0 and older collectors.
        $this->config->setAppValue(Application::APP_ID, 'history_base_url', $url);
    }

    public function getHistoryBaseUrl(): string {
        return $this->getBaseUrl();
    }

    public function getRefreshSeconds(): int {
        $uid = $this->userSession->getUser()?->getUID() ?? '';
        $old = $this->config->getUserValue($uid, 'lineamonitor', 'refresh_seconds', '2');
        $value = (int)$this->config->getUserValue($uid, Application::APP_ID, 'refresh_seconds', $old);
        return max(1, min(60, $value));
    }

    public function setRefreshSeconds(int $seconds): void {
        $uid = $this->userSession->getUser()?->getUID() ?? '';
        $seconds = max(1, min(60, $seconds));
        $this->config->setUserValue($uid, Application::APP_ID, 'refresh_seconds', (string)$seconds);
    }

    public function health(): array { return $this->request($this->getBaseUrl(), '/api/v1/health'); }

    public function healthAt(string $baseUrl): array {
        return $this->request($this->validateUrl($baseUrl), '/api/v1/health');
    }

    public function status(): array {
        return $this->request($this->getBaseUrl(), '/api/v1/status');
    }

    public function statusForHistory(): array { return $this->request($this->getHistoryBaseUrl(), '/api/v1/status'); }

    private function validateUrl(string $url): string {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            throw new RuntimeException('LINEA URL must start with http:// or https://');
        }
        return rtrim($url, '/');
    }

    private function request(string $baseUrl, string $path): array {
        $client = $this->clientService->newClient();
        try {
            $response = $client->get(rtrim($baseUrl, '/') . $path, [
                'timeout' => 5,
                'connect_timeout' => 3,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('LINEA API is not reachable: ' . $e->getMessage(), 0, $e);
        }
        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) throw new RuntimeException('LINEA API returned HTTP ' . $statusCode);
        $decoded = json_decode((string)$response->getBody(), true);
        if (!is_array($decoded)) throw new RuntimeException('LINEA API returned invalid JSON');
        return $decoded;
    }
}
