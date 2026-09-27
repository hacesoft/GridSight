<?php

declare(strict_types=1);

namespace OCA\HcGridSight\Dashboard;

use OCA\HcGridSight\AppInfo\Application;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\Util;

class GridSightWidget implements IWidget {
    public function __construct(private IL10N $l10n) {
    }

    public function getId(): string { return Application::APP_ID; }
    public function getTitle(): string { return $this->l10n->t('GridSight'); }
    public function getOrder(): int { return 30; }
    public function getIconClass(): string { return 'icon-monitoring'; }
    public function getUrl(): ?string { return null; }

    public function load(): void {
        Util::addScript(Application::APP_ID, 'dashboard-0.8.0-dev.11');
        Util::addStyle(Application::APP_ID, 'style-0.8.0-dev.11');
    }
}
