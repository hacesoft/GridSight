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
            $seconds = match ($range) {
                '1h' => 3600,
                '6h' => 21600,
                '7d' => 604800,
                '30d' => 2592000,
                default => 86400,
            };
            $to = $now;
            $from = $now - $seconds;
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
