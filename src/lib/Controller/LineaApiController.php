<?php

/**
 * Hacesoft GridSight – typová legenda proměnných (maďarská notace).
 * Historie: 2026-11-21 – bezpečné sjednocení interních lokálních proměnných.
 * Historie: 2026-11-22 – ověření typů, významových komentářů a shody názvů vlastností.
 * o – instance objektu nebo výjimka.
 * v – smíšená nebo zatím neurčená hodnota.
 * Výjimka: názvy parametrů PHP metod a veřejných vlastností se nemění,
 * protože jsou součástí rozhraní Nextcloud, DI a pojmenovaných argumentů.
 */

declare(strict_types=1);

namespace OCA\HcGridSight\Controller;

use OCA\HcGridSight\AppInfo\Application;
use OCA\HcGridSight\Service\LineaApiService;
use OCA\HcGridSight\Service\LineaApiUnavailableException;
use OCA\HcGridSight\Service\TemperatureReadings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class LineaApiController extends Controller {
    /**
     * Inicializuje instanci a převezme požadované závislosti.
     *
     * Vstupy: `$request` (IRequest) – HTTP požadavek Nextcloudu; `$linea` (LineaApiService) – klient HTTP rozhraní Node-RED LINEA.
     * Výstup: `void` – nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací.
     *
     * @param IRequest $request HTTP požadavek Nextcloudu
     * @param LineaApiService $linea klient HTTP rozhraní Node-RED LINEA
     * @return void nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací
     */
    public function __construct(
        IRequest $request,
        private LineaApiService $linea,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Předá výsledek kontroly API LINEA ve strukturované odpovědi.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `DataResponse` – JSON health obálka LINEA s kódem nedostupnosti API, pokud zdroj neodpovídá.
     * @return DataResponse JSON health obálka LINEA s kódem nedostupnosti API, pokud zdroj neodpovídá
     */
    #[NoAdminRequired]
    public function health(): DataResponse {
        try {
            return new DataResponse(['ok' => true, 'data' => $this->linea->health()]);
        } catch (LineaApiUnavailableException $oE) {
            // Očekávaný přechodný výpadek API: strojově rozpoznatelný kód, bez cURL detailů.
            return new DataResponse(['ok' => false, 'code' => 'linea_api_unavailable'], 503);
        } catch (\Throwable $oE) {
            return new DataResponse(['ok' => false, 'code' => 'linea_api_error', 'error' => $oE->getMessage()], 502);
        }
    }

    /**
     * Předá aktuální stav LINEA ve strukturované HTTP odpovědi.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `DataResponse` – JSON obálka normalizovaného stavu LINEA s údajem o dostupnosti.
     * @return DataResponse JSON obálka normalizovaného stavu LINEA s údajem o dostupnosti
     */
    #[NoAdminRequired]
    public function status(): DataResponse {
        try {
            $vStatus = $this->linea->status();
            // Internal LINEA control value is not part of the GridSight API.
            unset($vStatus['ess']['decision']['pvSurplusW'], $vStatus['units']['ess.decision.pvSurplusW']);
            $vStatus['hcTemperatures'] = TemperatureReadings::fromStatus($vStatus);
            return new DataResponse(['ok' => true, 'data' => $vStatus]);
        } catch (LineaApiUnavailableException $oE) {
            // Očekávaný přechodný výpadek API: strojově rozpoznatelný kód, bez cURL detailů.
            return new DataResponse(['ok' => false, 'code' => 'linea_api_unavailable'], 503);
        } catch (\Throwable $oE) {
            return new DataResponse(['ok' => false, 'code' => 'linea_api_error', 'error' => $oE->getMessage()], 502);
        }
    }
}
