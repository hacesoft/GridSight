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

namespace OCA\HcGridSight\Service;

use OCA\HcGridSight\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUserSession;
use RuntimeException;

class LineaApiService {
    private const DEFAULT_URL = 'http://node-red:1880';

    /**
     * Inicializuje instanci a převezme požadované závislosti.
     *
     * Vstupy: `$clientService` (IClientService) – HTTP klientská služba Nextcloudu; `$config` (IConfig) – konfigurace aplikace Nextcloud; `$userSession` (IUserSession) – aktuální uživatelská relace.
     * Výstup: `void` – nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací.
     *
     * @param IClientService $clientService HTTP klientská služba Nextcloudu
     * @param IConfig $config konfigurace aplikace Nextcloud
     * @param IUserSession $userSession aktuální uživatelská relace
     * @return void nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací
     */
    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
        private IUserSession $userSession,
    ) {}

    /**
     * Vrátí administrátorem nastavenou základní URL LINEA API.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `string` – základní HTTP(S) adresa Node-RED LINEA bez koncového lomítka.
     * @return string základní HTTP(S) adresa Node-RED LINEA bez koncového lomítka
     */
    public function getBaseUrl(): string {
        $vValue = trim($this->config->getAppValue(Application::APP_ID, 'linea_base_url', ''));
        if ($vValue === '') {
            $vValue = trim($this->config->getAppValue(Application::APP_ID, 'history_base_url', ''));
        }
        // Původní App ID zůstává po ručním přejmenování tabulek vypnuté.
        // Čtení jeho nastavení zachová LINEA URL bez zásahu instalátoru do DB.
        if ($vValue === '') {
            $vValue = trim($this->config->getAppValue('lineamonitor', 'linea_base_url', ''));
        }
        if ($vValue === '') {
            $vValue = trim($this->config->getAppValue('lineamonitor', 'history_base_url', self::DEFAULT_URL));
        }
        return rtrim($vValue !== '' ? $vValue : self::DEFAULT_URL, '/');
    }

    /**
     * Ověří a uloží základní URL LINEA API.
     *
     * Vstupy: `$url` (string) – adresa HTTP(S) serveru LINEA.
     * Výstup: `void` – uloží ověřenou LINEA URL do konfigurace; nevrací hodnotu.
     *
     * @param string $url adresa HTTP(S) serveru LINEA
     * @return void uloží ověřenou LINEA URL do konfigurace; nevrací hodnotu
     */
    public function setBaseUrl(string $url): void {
        $url = $this->validateUrl($url);
        $this->config->setAppValue(Application::APP_ID, 'linea_base_url', $url);
        // Keep the legacy key synchronized for rollback compatibility with 0.6.0 and older collectors.
        $this->config->setAppValue(Application::APP_ID, 'history_base_url', $url);
    }

    /**
     * Vrátí adresu zdroje pro oddělený historický sběr.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `string` – adresa LINEA použitá sběračem historie.
     * @return string adresa LINEA použitá sběračem historie
     */
    public function getHistoryBaseUrl(): string {
        return $this->getBaseUrl();
    }

    /**
     * Vrátí nastavený interval obnovování živých hodnot.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `int` – uživatelský obnovovací interval v rozsahu 1–60 sekund.
     * @return int uživatelský obnovovací interval v rozsahu 1–60 sekund
     */
    public function getRefreshSeconds(): int {
        $sUid = $this->userSession->getUser()?->getUID() ?? '';
        $vOld = $this->config->getUserValue($sUid, 'lineamonitor', 'refresh_seconds', '2');
        $vValue = (int)$this->config->getUserValue($sUid, Application::APP_ID, 'refresh_seconds', $vOld);
        return max(1, min(60, $vValue));
    }

    /**
     * Ověří a uloží interval obnovování živých hodnot.
     *
     * Vstupy: `$seconds` (int) – perioda aktualizace v sekundách.
     * Výstup: `void` – uloží interval omezený na 1–60 sekund; nevrací hodnotu.
     *
     * @param int $seconds perioda aktualizace v sekundách
     * @return void uloží interval omezený na 1–60 sekund; nevrací hodnotu
     */
    public function setRefreshSeconds(int $seconds): void {
        $sUid = $this->userSession->getUser()?->getUID() ?? '';
        $seconds = max(1, min(60, $seconds));
        $this->config->setUserValue($sUid, Application::APP_ID, 'refresh_seconds', (string)$seconds);
    }

    /**
     * Ověří dostupnost a kompatibilitu čtecího rozhraní LINEA API.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `array` – pole údajů /api/v1/health LINEA; při výpadku vyhodí LineaApiUnavailableException.
     * @return array pole údajů /api/v1/health LINEA; při výpadku vyhodí LineaApiUnavailableException
     */
    public function health(): array { return $this->request($this->getBaseUrl(), '/api/v1/health'); }

    /**
     * Ověří čtecí API na konkrétní zadané URL.
     *
     * Vstupy: `$baseUrl` (string) – základní URL LINEA pro kontrolu spojení.
     * Výstup: `array` – pole health API ověřené pro zadanou URL; při výpadku vyhodí LineaApiUnavailableException.
     *
     * @param string $baseUrl základní URL LINEA pro kontrolu spojení
     * @return array pole health API ověřené pro zadanou URL; při výpadku vyhodí LineaApiUnavailableException
     */
    public function healthAt(string $baseUrl): array {
        return $this->request($this->validateUrl($baseUrl), '/api/v1/health');
    }

    /**
     * Načte a ověří aktuální stav z API LINEA.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `array` – pole aktuálního stavu /api/v1/status; při výpadku vyhodí LineaApiUnavailableException.
     * @return array pole aktuálního stavu /api/v1/status; při výpadku vyhodí LineaApiUnavailableException
     */
    public function status(): array {
        return $this->request($this->getBaseUrl(), '/api/v1/status');
    }

    /**
     * Načte aktuální stav vhodný pro historický sběr.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `array` – aktuální stav LINEA získaný pro samostatný sběrač historie.
     * @return array aktuální stav LINEA získaný pro samostatný sběrač historie
     */
    public function statusForHistory(): array { return $this->request($this->getHistoryBaseUrl(), '/api/v1/status'); }

    /**
     * Ověří bezpečný a očekávaný tvar adresy API.
     *
     * Vstupy: `$url` (string) – adresa HTTP(S) serveru LINEA.
     * Výstup: `string` – normalizovaná HTTP(S) URL; při neplatném schématu vyhodí výjimku.
     *
     * @param string $url adresa HTTP(S) serveru LINEA
     * @return string normalizovaná HTTP(S) URL; při neplatném schématu vyhodí výjimku
     */
    private function validateUrl(string $url): string {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            throw new RuntimeException('LINEA URL must start with http:// or https://');
        }
        return rtrim($url, '/');
    }

    /**
     * Provede HTTP požadavek na LINEA API a převede chyby spojení.
     *
     * Vstupy: `$baseUrl` (string) – základní URL LINEA pro kontrolu spojení; `$path` (string) – relativní cesta API nebo úplná cesta k XLSX souboru podle metody.
     * Výstup: `array` – dekódované JSON pole LINEA; chybu transportu převede na LineaApiUnavailableException.
     *
     * @param string $baseUrl základní URL LINEA pro kontrolu spojení
     * @param string $path relativní cesta API nebo úplná cesta k XLSX souboru podle metody
     * @return array dekódované JSON pole LINEA; chybu transportu převede na LineaApiUnavailableException
     */
    private function request(string $baseUrl, string $path): array {
        $oClient = $this->clientService->newClient();
        try {
            $oResponse = $oClient->get(rtrim($baseUrl, '/') . $path, [
                'timeout' => 5,
                'connect_timeout' => 3,
                // HTTP 4xx/5xx se musí vyhodnotit podle kódu, nikoli jako výpadek sítě.
                'http_errors' => false,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (\Throwable $oE) {
            // Nepředávat technický popis transportní chyby klientovi.
            throw new LineaApiUnavailableException('LINEA API is temporarily unavailable', 0, $oE);
        }
        $nStatusCode = $oResponse->getStatusCode();
        // Při aktualizaci Node-RED může proxy vracet 502/503/504.
        if (in_array($nStatusCode, [502, 503, 504], true)) {
            throw new LineaApiUnavailableException('LINEA API is temporarily unavailable');
        }
        if ($nStatusCode < 200 || $nStatusCode >= 300) throw new RuntimeException('LINEA API returned HTTP ' . $nStatusCode);
        $vDecoded = json_decode((string)$oResponse->getBody(), true);
        if (!is_array($vDecoded)) throw new RuntimeException('LINEA API returned invalid JSON');
        return $vDecoded;
    }
}
