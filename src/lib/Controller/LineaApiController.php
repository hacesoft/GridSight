<?php

declare(strict_types=1);

namespace OCA\HcGridSight\Controller;

use OCA\HcGridSight\AppInfo\Application;
use OCA\HcGridSight\Service\LineaApiService;
use OCA\HcGridSight\Service\TemperatureReadings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class LineaApiController extends Controller {
    public function __construct(
        IRequest $request,
        private LineaApiService $linea,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function health(): DataResponse {
        try {
            return new DataResponse(['ok' => true, 'data' => $this->linea->health()]);
        } catch (\Throwable $e) {
            return new DataResponse(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }

    #[NoAdminRequired]
    public function status(): DataResponse {
        try {
            $status = $this->linea->status();
            // Internal LINEA control value is not part of the GridSight API.
            unset($status['ess']['decision']['pvSurplusW'], $status['units']['ess.decision.pvSurplusW']);
            $status['hcTemperatures'] = TemperatureReadings::fromStatus($status);
            return new DataResponse(['ok' => true, 'data' => $status]);
        } catch (\Throwable $e) {
            return new DataResponse(['ok' => false, 'error' => $e->getMessage()], 502);
        }
    }
}
