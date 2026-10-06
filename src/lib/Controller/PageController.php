<?php

declare(strict_types=1);

namespace OCA\HcGridSight\Controller;

use OCA\HcGridSight\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

final class PageController extends Controller {
    public function __construct(IRequest $request) {
        parent::__construct(Application::APP_ID, $request);
    }

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
