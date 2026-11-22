<?php
/**
 * GridSight – typová legenda proměnných (maďarská notace).
 * Historie: 2026-11-22 – ověření notace a českých komentářů metod.
 * a – pole či seznam; o – objekt nebo instance třídy.
 * Výjimka: veřejné parametry metod a klíče API zůstávají nezměněny.
 */

declare(strict_types=1);

namespace OCA\HcGridSight\Controller;

use OCA\HcGridSight\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

final class PageController extends Controller {
    /**
     * Inicializuje instanci a převezme požadované závislosti.
     *
     * Vstupy: `$request` (IRequest) – HTTP požadavek Nextcloudu.
     * Výstup: `void` – nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací.
     *
     * @param IRequest $request HTTP požadavek Nextcloudu
     * @return void nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací
     */
    public function __construct(IRequest $request) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Vytvoří stránku GridSight a předá šabloně potřebné údaje.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `TemplateResponse` – HTML odpověď se vstupní stránkou aplikace.
     * @return TemplateResponse HTML odpověď se vstupní stránkou aplikace
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        $aContract = json_decode(
            (string)file_get_contents(__DIR__ . '/../../appinfo/hc_shared_app_core.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return new TemplateResponse(Application::APP_ID, 'main', [
            'appVersion' => Application::VERSION,
            'requiredCoreVersion' => (string)$aContract['requiredVersion'],
            'requiredCoreApiVersion' => (int)($aContract['requiredApiVersion'] ?? 1),
            'coreDownloadUrl' => (string)$aContract['downloadUrl'],
        ]);
    }
}
