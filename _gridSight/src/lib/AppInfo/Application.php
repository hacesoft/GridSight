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
    public const VERSION = '0.8.0-dev.11';
    public const EVENT_PREFIX = self::APP_ID . ':';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerDashboardWidget(GridSightWidget::class);
    }

    public function boot(IBootContext $context): void {
    }
}
