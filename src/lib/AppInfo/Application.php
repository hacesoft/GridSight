<?php

declare(strict_types=1);

namespace OCA\HcGridSight\AppInfo;

use OCA\HcGridSight\Dashboard\GridSightWidget;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'hc_gridsight';
    public const VERSION = '1.0.2';
    public const EVENT_PREFIX = self::APP_ID . ':';

    /**
     * Inicializuje instanci a převezme požadované závislosti.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `void` – nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací.
     * @return void nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací
     */
    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    /**
     * Registruje služby a integrační body aplikace při spuštění Nextcloudu.
     *
     * Vstupy: `$context` (IRegistrationContext) – registrační kontext Nextcloudu.
     * Výstup: `void` – registruje služby aplikace, bez návratové hodnoty.
     *
     * @param IRegistrationContext $context registrační kontext Nextcloudu
     * @return void registruje služby aplikace, bez návratové hodnoty
     */
    public function register(IRegistrationContext $context): void {
        $context->registerDashboardWidget(GridSightWidget::class);
    }

    /**
     * Dokončí inicializaci aplikace a případné napojení služeb.
     *
     * Vstupy: `$context` (IBootContext) – registrační kontext Nextcloudu.
     * Výstup: `void` – dokončí start aplikace; nevrací hodnotu.
     *
     * @param IBootContext $context registrační kontext Nextcloudu
     * @return void dokončí start aplikace; nevrací hodnotu
     */
    public function boot(IBootContext $context): void {
    }
}
