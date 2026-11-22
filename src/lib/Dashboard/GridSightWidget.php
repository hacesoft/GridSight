<?php

declare(strict_types=1);

namespace OCA\HcGridSight\Dashboard;

use OCA\HcGridSight\AppInfo\Application;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\Util;

class GridSightWidget implements IWidget {
    /**
     * Inicializuje instanci a převezme požadované závislosti.
     *
     * Vstupy: `$l10n` (IL10N) – překladové rozhraní.
     * Výstup: `void` – nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací.
     *
     * @param IL10N $l10n překladové rozhraní
     * @return void nastaví závislosti nebo počáteční stav instance; konstruktor žádnou hodnotu nevrací
     */
    public function __construct(private IL10N $l10n) {
    }

    /**
     * Vrátí jednoznačné technické ID widgetu.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `string` – technický identifikátor widgetu hc_gridsight.
     * @return string technický identifikátor widgetu hc_gridsight
     */
    public function getId(): string { return Application::APP_ID; }
    /**
     * Vrátí titulek widgetu pro přehled Nextcloudu.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `string` – lokalizovaný název widgetu GridSight.
     * @return string lokalizovaný název widgetu GridSight
     */
    public function getTitle(): string { return $this->l10n->t('GridSight'); }
    /**
     * Vrátí pořadí zobrazení widgetu.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `int` – číselné pořadí widgetu na nástěnce.
     * @return int číselné pořadí widgetu na nástěnce
     */
    public function getOrder(): int { return 30; }
    /**
     * Vrátí ikonu widgetu.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `string` – název CSS třídy ikony widgetu.
     * @return string název CSS třídy ikony widgetu
     */
    public function getIconClass(): string { return 'icon-monitoring'; }
    /**
     * Vrátí odkaz na stránku GridSight.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `?string` – URL stránky GridSight, nebo null, pokud není dostupná.
     * @return ?string URL stránky GridSight, nebo null, pokud není dostupná
     */
    public function getUrl(): ?string { return null; }

    /**
     * Připraví data a prostředky pro vykreslení widgetu.
     *
     * Vstupy: žádné vstupní parametry.
     * Výstup: `void` – bez návratové hodnoty; widget je připraven přímo přes rozhraní dashboardu.
     * @return void bez návratové hodnoty; widget je připraven přímo přes rozhraní dashboardu
     */
    public function load(): void {
        Util::addScript(Application::APP_ID, 'dashboard-1.0.2');
        Util::addStyle(Application::APP_ID, 'style-1.0.2');
    }
}
