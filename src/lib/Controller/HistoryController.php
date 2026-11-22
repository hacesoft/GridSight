<?php

/**
 * Hacesoft GridSight – typová legenda proměnných (maďarská notace).
 * Historie: 2026-11-21 – bezpečné sjednocení interních lokálních proměnných.
 * Historie: 2026-11-22 – ověření typů, významových komentářů a shody názvů vlastností.
 * n – číslo (celé, desetinné, čas nebo jednotky).
 * v – smíšená nebo zatím neurčená hodnota.
 * Výjimka: názvy parametrů PHP metod a veřejných vlastností se nemění,
 * protože jsou součástí rozhraní Nextcloud, DI a pojmenovaných argumentů.
 */

declare(strict_types=1);

namespace OCA\HcGridSight\Controller;

use OCA\HcGridSight\AppInfo\Application;
use OCA\HcGridSight\Service\HistoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class HistoryController extends Controller {
    /**
     * Inicializuje instanci a převezme požadované závislosti.
     *
     * Vstupy: `$request` (IRequest) – HTTP požadavek Nextcloudu; `$history` (HistoryService) – přístup k historii a sběrači.
     * Výstup: `void` – nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací.
     *
     * @param IRequest $request HTTP požadavek Nextcloudu
     * @param HistoryService $history přístup k historii a sběrači
     * @return void nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací
     */
    public function __construct(IRequest $request, private HistoryService $history) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Vrátí historii pro zvolený interval ve formátu Nextcloud JSON.
     *
     * Vstupy: `$range` (string) – symbolický interval historie (today, 3m, all aj.); `$from` (int) – počáteční unixový čas intervalu (s); `$to` (int) – konečný unixový čas intervalu (s).
     * Výstup: `DataResponse` – Nextcloud JSON s historií, body grafů a událostmi pro časový rozsah.
     *
     * @param string $range symbolický interval historie (today, 3m, all aj.)
     * @param int $from počáteční unixový čas intervalu (s)
     * @param int $to konečný unixový čas intervalu (s)
     * @return DataResponse Nextcloud JSON s historií, body grafů a událostmi pro časový rozsah
     */
    #[NoAdminRequired]
    public function get(string $range = '24h', int $from = 0, int $to = 0): DataResponse {
        $nNow = time();
        if ($from <= 0 || $to <= 0) {
            $vBounds=\OCA\HcGridSight\Service\HistoryRange::bounds($range,$nNow,$range==='all'?$this->history->earliestTimestamp():0);
            $from=$vBounds['from']; $to=$vBounds['to'];
        }
        return new DataResponse(['ok'=>true,'from'=>$from,'to'=>$to,'data'=>$this->history->getHistory($from,$to)]);
    }

    /**
     * Vrátí stav sběrače a dostupnost uložených historických měření.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `DataResponse` – Nextcloud JSON s diagnostikou historie a databáze.
     * @return DataResponse Nextcloud JSON s diagnostikou historie a databáze
     */
    #[NoAdminRequired]
    public function info(): DataResponse {
        return new DataResponse(['ok'=>true,'data'=>$this->history->info()]);
    }

    /**
     * Vrátí součty a ocenění energie za lokální kalendářní den.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `DataResponse` – Nextcloud JSON s denními součty importu, exportu a prodeje.
     * @return DataResponse Nextcloud JSON s denními součty importu, exportu a prodeje
     */
    #[NoAdminRequired]
    public function daily(): DataResponse {
        return new DataResponse(['ok'=>true,'data'=>$this->history->todayAccounting()]);
    }
}
