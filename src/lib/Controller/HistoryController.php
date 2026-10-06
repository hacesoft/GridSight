<?php

declare(strict_types=1);

namespace OCA\HcGridSight\Controller;

use OCA\HcGridSight\AppInfo\Application;
use OCA\HcGridSight\Service\HistoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class HistoryController extends Controller {
    public function __construct(IRequest $request, private HistoryService $history) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function get(string $range = '24h', int $from = 0, int $to = 0): DataResponse {
        $now = time();
        if ($from <= 0 || $to <= 0) {
            $bounds=\OCA\HcGridSight\Service\HistoryRange::bounds($range,$now,$range==='all'?$this->history->earliestTimestamp():0);
            $from=$bounds['from']; $to=$bounds['to'];
        }
        return new DataResponse(['ok'=>true,'from'=>$from,'to'=>$to,'data'=>$this->history->getHistory($from,$to)]);
    }

    #[NoAdminRequired]
    public function info(): DataResponse {
        return new DataResponse(['ok'=>true,'data'=>$this->history->info()]);
    }

    #[NoAdminRequired]
    public function daily(): DataResponse {
        return new DataResponse(['ok'=>true,'data'=>$this->history->todayAccounting()]);
    }
}
