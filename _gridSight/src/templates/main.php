<?php

declare(strict_types=1);

use OCA\HcGridSight\AppInfo\Application;
use OCP\Util;

// Shared App Core 0.18.0-dev.2: jediný podporovaný statický start.
// Pořadí assetů je závazné: Core CSS, app CSS, Core JS, app JS.
Util::addStyle('hc_shared_app_core', 'workspace');
Util::addStyle(Application::APP_ID, 'style-0.8.0-dev.11');
Util::addScript('hc_shared_app_core', 'hc_shared_app_core');
Util::addScript(Application::APP_ID, 'app-0.8.0-dev.11');
?>
<div
    id="linea-monitor"
    data-app-version="<?php p($_['appVersion']); ?>"
    data-required-core-version="<?php p($_['requiredCoreVersion']); ?>"
    data-required-core-api-version="<?php p($_['requiredCoreApiVersion']); ?>"
    data-core-download-url="<?php p($_['coreDownloadUrl']); ?>"
></div>

<template id="lm-app-shell-template">
    <header class="lm-header hc-shared-app-core-layout__header">
        <div class="lm-title-wrap">
            <div class="lm-brand" aria-hidden="true">⚡</div>
            <div class="lm-title-text">
                <h2>
                    <span class="lm-title-name">GridSight</span>
                    <span class="lm-title-version">v<?php p($_['appVersion']); ?></span>
                    <span id="lm-source-age" class="lm-title-source-age" title="<?php p($l->t('Age of the main LINEA source snapshot.')); ?>">—</span>
                </h2>
                <span id="lm-api-version" hidden>LINEA API —</span>
            </div>
        </div>
        <div class="lm-header-actions">
            <span id="lm-auto-refresh" class="lm-auto-refresh" title="<?php p($l->t('Automatic refresh updates data without reloading the page.')); ?>">⟳ <?php p($l->t('Auto')); ?> 5 min · <span id="lm-auto-refresh-countdown">5:00</span></span>
            <span id="lm-connection" class="lm-status lm-status-wait"><span class="lm-dot"></span><?php p($l->t('Connecting')); ?></span>
            <div id="lm-core-toolbar" class="lm-core-toolbar-host"></div>
        </div>
    </header>

    <nav class="lm-tabs hc-shared-app-core-layout__toolbar" aria-label="<?php p($l->t('LINEA sections')); ?>">
        <button type="button" class="lm-tab active" data-tab="live"><?php p($l->t('LIVE')); ?></button>
        <button type="button" class="lm-tab" data-tab="history"><?php p($l->t('HISTORY')); ?></button>
        <button type="button" class="lm-tab" data-tab="ess">ESS</button>
        <button type="button" class="lm-tab" data-tab="collector"><?php p($l->t('Collection')); ?></button>
        <button type="button" class="lm-tab" data-tab="events"><?php p($l->t('Event log')); ?></button>
        <button type="button" class="lm-tab" data-tab="analytics"><?php p($l->t('ANALYTICS')); ?></button>
        <button type="button" class="lm-tab" data-tab="settings"><?php p($l->t('Settings')); ?></button>
    </nav>

    <main class="lm-main hc-shared-app-core-layout__content">
      <section class="lm-view hc-shared-app-core-view">
        <div class="lm-scroll-owner hc-shared-app-core-scroll-area">
        <section id="lm-tab-live" class="lm-tab-panel active">
            <div id="lm-error" class="lm-alert" hidden></div>

            <section class="lm-energy-cockpit">
                <article class="lm-card lm-energy-panel lm-energy-grid">
                    <div class="lm-energy-head">
                        <div><span class="lm-energy-icon">⚡</span><div><h3><?php p($l->t('Grid')); ?></h3><small><?php p($l->t('To / from grid')); ?></small></div></div>
                        <div class="lm-energy-now"><strong><span id="lm-grid-power">—</span> W</strong><small id="lm-grid-badge">—</small></div>
                    </div>
                    <div class="lm-phase-with-temp lm-grid-phases">
                        <div><span>L1</span><strong id="lm-grid-l1">—</strong></div>
                        <div><span>L2</span><strong id="lm-grid-l2">—</strong></div>
                        <div><span>L3</span><strong id="lm-grid-l3">—</strong></div>
                    </div>
                    <div class="lm-energy-footer lm-grid-today">
                        <span class="lm-grid-out"><em><i aria-hidden="true">←</i> <?php p($l->t('To grid')); ?></em><b id="lm-vrm-export">—</b></span>
                        <span class="lm-grid-in"><em><i aria-hidden="true">→</i> <?php p($l->t('From grid')); ?></em><b id="lm-vrm-import">—</b></span>
                        <span class="lm-grid-balance"><?php p($l->t('Balance')); ?> <b id="lm-grid-day-balance">—</b></span>
                    </div>
                    <span id="lm-grid-direction" hidden>W</span><span id="lm-grid-tech-total" hidden>—</span>
                </article>

                <article class="lm-card lm-energy-panel lm-energy-house">
                    <div class="lm-energy-head">
                        <div><span class="lm-energy-icon">🏠</span><div><h3><?php p($l->t('House consumption')); ?></h3><small>L1 · L2 · L3 + <?php p($l->t('temperatures')); ?></small></div></div>
                        <div class="lm-energy-now"><strong><span id="lm-house-power">—</span> W</strong><small><?php p($l->t('instantaneous')); ?></small></div>
                    </div>
                    <div class="lm-phase-with-temp">
                        <div><span>L1</span><strong id="lm-house-l1">—</strong><small id="lm-house-temp-l1">🌡 —</small></div>
                        <div><span>L2</span><strong id="lm-house-l2">—</strong><small id="lm-house-temp-l2">🌡 —</small></div>
                        <div><span>L3</span><strong id="lm-house-l3">—</strong><small id="lm-house-temp-l3">🌡 —</small></div>
                    </div>
                    <div class="lm-energy-footer lm-daily-footer">
                        <span>🌡 Rack <b id="lm-house-rack-temp">—</b></span>
                        <span><?php p($l->t('Consumed today')); ?> <b id="lm-vrm-cons">—</b></span>
                        <span><?php p($l->t('Day estimate')); ?> <b id="lm-forecast-cons">—</b></span>
                    </div>
                </article>

                <article class="lm-card lm-energy-panel lm-energy-pv">
                    <div class="lm-energy-head">
                        <div><span class="lm-energy-icon">☀</span><div><h3><?php p($l->t('PV production')); ?></h3><small><?php p($l->t('PV strings')); ?> / MPPT</small></div></div>
                        <div class="lm-energy-now"><strong><span id="lm-pv-power">—</span> W</strong><small><?php p($l->t('instantaneous')); ?></small></div>
                    </div>
                    <div id="lm-pv-strings" class="lm-energy-lines"><div class="lm-empty">—</div></div>
                    <div class="lm-energy-footer lm-daily-footer lm-pv-daily-footer">
                        <span><?php p($l->t('Produced today')); ?> <b id="lm-vrm-pv">—</b></span>
                        <span><?php p($l->t('Day estimate')); ?> <b id="lm-forecast-pv">—</b></span>
                    </div>
                    <span id="lm-pv-yield" hidden>—</span>
                </article>

                <article class="lm-card lm-energy-panel lm-energy-battery">
                    <div class="lm-energy-head">
                        <div><span class="lm-energy-icon">🔋</span><div><h3><?php p($l->t('Battery')); ?></h3></div></div>
                        <div class="lm-energy-now"><strong><span id="lm-battery-soc">—</span> %</strong><small id="lm-battery-power">—</small></div>
                    </div>
                    <div class="lm-battery-bar"><i id="lm-battery-fill"></i></div>
                    <div class="lm-battery-facts">
                        <div><span><?php p($l->t('Current')); ?></span><b id="lm-batt-current">—</b></div>
                        <div><span><?php p($l->t('Voltage')); ?></span><b id="lm-batt-voltage">—</b></div>
                        <div><span>🌡 Rack</span><b id="lm-batt-rack-temp">—</b></div>
                        <div><span>BatteryLife SOC</span><b id="lm-batt-limit">—</b></div>
                    </div>
                    <div class="lm-battery-energy">
                        <span>↥ <?php p($l->t('Charged since restart')); ?> <b id="lm-vrm-batt-charge">—</b></span>
                        <span>↧ <?php p($l->t('Discharged since restart')); ?> <b id="lm-vrm-batt-discharge">—</b></span>
                        <span>↔ <?php p($l->t('Battery balance since restart')); ?> <b id="lm-battery-energy-balance">—</b></span>
                    </div>
                </article>
            </section>



            <section class="lm-live-secondary-grid">
                <section class="lm-card lm-spot-panel">
                    <div class="lm-card-head"><h3><span class="lm-section-icon">💰</span><?php p($l->t('SPOT, weather and daylight')); ?></h3><span class="lm-pill lm-pill-readonly">READ-ONLY</span></div>
                    <div class="lm-spot-main">
                        <div class="lm-spot-price"><small><?php p($l->t('Current SPOT price')); ?></small><strong id="lm-spot">—</strong></div>
                        <div class="lm-spot-statuses">
                            <div class="lm-spot-sale-row">
                                <span><?php p($l->t('Sale')); ?> <b id="lm-spot-export-state" class="lm-spot-permission">—</b></span>
                                <strong id="lm-spot-sale-value" title="<?php p($l->t('Sum of measured export multiplied by the SPOT price at each sample. Updated after each completed five-minute interval.')); ?>"><?php p($l->t('Today')); ?> —</strong>
                            </div>
                            <span><?php p($l->t('SPOT grid charging')); ?> <b id="lm-spot-grid-charge-state">—</b></span>
                            <span><?php p($l->t('SPOT threshold')); ?> <b id="lm-spot-threshold">—</b></span>
                        </div>
                    </div>
                    <div class="lm-spot-forecast">
                        <span>☀ <?php p($l->t('PV forecast')); ?> <b id="lm-spot-pv-forecast">—</b></span>
                        <span>🏠 <?php p($l->t('Consumption forecast')); ?> <b id="lm-spot-cons-forecast">—</b></span>
                    </div>
                    <div id="lm-spot-price-chart" class="lm-spot-price-chart"><div class="lm-empty">—</div></div>
                    <div id="lm-forecast-series-note" class="lm-forecast-series-note">—</div>
                    <div class="lm-weather-strip">
                        <div><span>☁</span><small><?php p($l->t('Weather')); ?></small><strong id="lm-weather-today">—</strong></div>
                        <div><span>🌅</span><small><?php p($l->t('Sun')); ?></small><strong id="lm-sun">—</strong></div>
                        <div><span>☀</span><small><?php p($l->t('Day length')); ?></small><strong id="lm-day-length">—</strong></div>
                        <div><span>🌧</span><small><?php p($l->t('Rain probability')); ?></small><strong id="lm-weather-rain">—</strong></div>
                    </div>
                    <span id="lm-weather-trend" hidden>—</span>
                </section>

                <section class="lm-card lm-climate-panel">
                    <div class="lm-card-head"><h3><span class="lm-section-icon">❄</span><?php p($l->t('Climate')); ?> <span class="lm-help-tip" tabindex="0" title="<?php p($l->t('Daikin Onecta: state, temperatures, setpoint, energy, error and firmware.')); ?>">?</span></h3><span id="lm-climate-updated" class="lm-age">—</span></div>
                    <div id="lm-climate" class="lm-climate-grid"><div class="lm-empty">—</div></div>
                    <section class="lm-ups-compact" aria-label="UPS">
                        <div class="lm-ups-title"><strong>🔋 UPS</strong><span id="lm-ups-age" class="lm-age">—</span></div>
                        <div id="lm-ups-summary" class="lm-ups-summary"><div class="lm-empty">—</div></div>
                        <details class="lm-details lm-ups-details"><summary><?php p($l->t('UPS details')); ?> <span>▸</span></summary><div id="lm-ups-details" class="lm-list"><div class="lm-empty">—</div></div></details>
                    </section>
                </section>

                <article class="lm-card lm-shelly-ups-card">
                    <div class="lm-card-head"><h3><span class="lm-section-icon">🔥</span>Shelly / <?php p($l->t('Sensors')); ?> / <?php p($l->t('Devices')); ?> <span class="lm-help-tip" tabindex="0" title="<?php p($l->t('Shelly smoke detectors first, then other Shelly devices and UPS operating data from NUT.')); ?>">?</span></h3><span id="lm-shelly-updated" class="lm-age">—</span></div>
                    <div class="lm-subhead"><strong>🔥 <?php p($l->t('Smoke detectors')); ?></strong></div>
                    <div id="lm-smoke" class="lm-list"><div class="lm-empty">—</div></div>
                    <div class="lm-separator"></div>
                    <div class="lm-subhead"><strong>🔌 <?php p($l->t('Other Shelly devices')); ?></strong></div>
                    <div id="lm-shelly-primary" class="lm-switch-grid lm-shelly-primary"><div class="lm-empty">—</div></div>
                    <details class="lm-details lm-shelly-more"><summary><?php p($l->t('Other Shelly devices')); ?> · <span id="lm-shelly-more-count">0</span></summary><div id="lm-shelly" class="lm-switch-grid"><div class="lm-empty">—</div></div></details>
                </article>
            </section>

        </section>

        <section id="lm-tab-history" class="lm-tab-panel">
            <div class="lm-history-toolbar">
                <div>
                    <h3><?php p($l->t('History')); ?></h3>
                    <p><?php p($l->t('The fast collector reads LINEA about once per second, keeps data in memory and stores only time-weighted five-minute aggregates in the database. LIVE remains a read-only view of the current LINEA API.')); ?></p>
                </div>
                <div class="lm-history-actions">
                    <div class="lm-range-tabs" role="group" aria-label="<?php p($l->t('History range')); ?>">
                        <button type="button" data-history-range="1h">1 h</button>
                        <button type="button" data-history-range="6h">6 h</button>
                        <button type="button" data-history-range="24h" class="active">24 h</button>
                        <button type="button" data-history-range="7d"><?php p($l->t('7 days')); ?></button>
                        <button type="button" data-history-range="30d"><?php p($l->t('30 days')); ?></button>
                    </div>
                </div>
            </div>
            <div id="lm-history-error" class="lm-alert" hidden></div>
            <section class="lm-card lm-history-overview">
                <div class="lm-card-head"><div><h3>📈 <?php p($l->t('Energy flow history')); ?></h3><small><?php p($l->t('Energy overview for the selected period')); ?></small></div><span id="lm-history-meta" class="lm-age">—</span></div>
                <div class="lm-history-summary lm-history-summary-inline">
                    <article><span>☀ <?php p($l->t('PV production')); ?></span><strong id="lm-h-pv-kwh">—</strong><small>kWh</small></article>
                    <article><span>🏠 <?php p($l->t('Consumption')); ?></span><strong id="lm-h-house-kwh">—</strong><small>kWh</small></article>
                    <article><span>⚡ ↓ <?php p($l->t('Purchase')); ?></span><strong id="lm-h-import-kwh">—</strong><small>kWh</small></article>
                    <article><span>⚡ ↑ <?php p($l->t('Sale')); ?></span><strong id="lm-h-export-kwh">—</strong><small>kWh</small></article>
                    <article><span>💰 <?php p($l->t('Sale')); ?> · SPOT</span><strong id="lm-h-export-value">—</strong><small>CZK</small></article>
                    <article><span>🔋 ↥ <?php p($l->t('Charged to battery')); ?></span><strong id="lm-h-batt-charge-kwh">—</strong><small>kWh</small></article>
                    <article><span>🔋 ↧ <?php p($l->t('Discharged from battery')); ?></span><strong id="lm-h-batt-discharge-kwh">—</strong><small>kWh</small></article>
                </div>
                <div class="lm-history-chart-tools"><div class="lm-chart-legend"><button type="button" data-series="pv" class="pv active"><?php p($l->t('PV')); ?></button><button type="button" data-series="house" class="house active"><?php p($l->t('House')); ?></button><button type="button" data-series="grid" class="grid active"><?php p($l->t('Grid')); ?></button><button type="button" data-series="export" class="export active"><?php p($l->t('Sale power')); ?></button><button type="button" data-series="battery" class="battery active"><?php p($l->t('Battery')); ?></button><button type="button" data-series="soc" class="soc active">🔋 SOC</button><button type="button" data-series="spot" class="spot active">💰 SPOT</button><button type="button" data-series="batteryTemp" class="battery-temp active"><?php p($l->t('Battery temperature')); ?></button><button type="button" data-series="rackTemp" class="rack-temp active"><?php p($l->t('Rack temperature')); ?></button><button type="button" data-series="inverter1Temp" class="inverter-1-temp active"><?php p($l->t('Inverter')); ?> 1</button><button type="button" data-series="inverter2Temp" class="inverter-2-temp active"><?php p($l->t('Inverter')); ?> 2</button><button type="button" data-series="inverter3Temp" class="inverter-3-temp active"><?php p($l->t('Inverter')); ?> 3</button><button type="button" data-series="forecastPv" class="forecast-pv active">☀ <?php p($l->t('PV forecast')); ?></button><button type="button" data-series="forecastHouse" class="forecast-house active">🏠 <?php p($l->t('Consumption forecast')); ?></button></div><button type="button" id="lm-history-zoom-reset" class="lm-zoom-reset" hidden>↺ <?php p($l->t('Reset')); ?></button></div>
                <div id="lm-history-power-chart" class="lm-chart"><div class="lm-empty"><?php p($l->t('No historical data have been stored yet.')); ?></div></div>
            </section>
        </section>
        <section id="lm-tab-ess" class="lm-tab-panel">
            <article class="lm-card lm-ess-page">
                <div class="lm-card-head"><div><h3>🔋 ESS</h3><small>LINEA / GridSight · <?php p($l->t('Read-only')); ?></small></div><span id="lm-ess-state" class="lm-pill">—</span></div>
                <p id="lm-ess-reason" class="lm-ess-reason">—</p>
                <div class="lm-ess-overview">
                    <div><span>Grid point</span><strong id="lm-grid-point">—</strong></div>
                    <div><span><?php p($l->t('Prediction')); ?></span><strong id="lm-prediction">—</strong></div>
                </div>
                <div class="lm-ess-page-grid">
                    <section><h4><?php p($l->t('Switches')); ?></h4><dl id="lm-ess-switches" class="lm-kv lm-kv-compact"></dl></section>
                    <section><h4><?php p($l->t('Settings')); ?></h4><dl id="lm-ess-settings" class="lm-kv lm-kv-compact"></dl></section>
                </div>
                <section class="lm-ess-times"><h4><?php p($l->t('Times')); ?></h4>
                    <dl class="lm-kv lm-kv-compact">
                        <div><dt>Delay Charging</dt><dd id="lm-delay-window">—</dd></div>
                        <div><dt><?php p($l->t('Morning peak')); ?></dt><dd id="lm-morning-hours">—</dd></div>
                        <div><dt><?php p($l->t('Evening peak')); ?></dt><dd id="lm-evening-hours">—</dd></div>
                    </dl>
                </section>
            </article>
        </section>

        <section id="lm-tab-collector" class="lm-tab-panel">
            <section class="lm-card lm-collector-card">
                <div class="lm-card-head"><h3>🛠 <?php p($l->t('Technical history collection data')); ?></h3><span id="lm-history-state" class="lm-pill">—</span></div>
                    <dl class="lm-kv">
                        <div><dt>Fast collector</dt><dd id="lm-history-fast-state">—</dd></div>
                        <div><dt><?php p($l->t('Last 5-minute aggregate')); ?> <span class="lm-help-tip" tabindex="0" title="<?php p($l->t('Time of the last successful write of a completed five-minute interval to the Nextcloud database.')); ?>">?</span></dt><dd id="lm-history-last">—</dd></div>
                        <div><dt><?php p($l->t('5-minute blocks in DB')); ?></dt><dd id="lm-history-count">—</dd></div>
                        <div><dt>RAM buffer <span class="lm-help-tip" tabindex="0" title="<?php p($l->t('Number of one-second samples currently held only in memory. Maximum is 3600, approximately the last hour.')); ?>">?</span></dt><dd id="lm-history-buffer">—</dd></div>
                        <div><dt><?php p($l->t('Successful polls / errors')); ?></dt><dd id="lm-history-polls">—</dd></div>
                        <div><dt><?php p($l->t('Chart resolution')); ?></dt><dd id="lm-history-resolution">—</dd></div>
                        <div><dt><?php p($l->t('GridSight database size')); ?></dt><dd id="lm-history-db-size">—</dd></div>
                        <div><dt><?php p($l->t('Database size checked')); ?></dt><dd id="lm-history-db-size-checked">—</dd></div>
                    </dl>
                    <p class="lm-help"><?php p($l->t('Database size is a cached technical value and is refreshed at most once per hour, not with every sample.')); ?></p>
                    <p class="lm-help"><?php p($l->t('The database does not receive a random instantaneous state. Power, SOC and phases are calculated as time-weighted averages; minimum and maximum are preserved and energy is integrated. Outages and stale data are excluded from energy.')); ?></p>
            </section>
        </section>
        <section id="lm-tab-events" class="lm-tab-panel">
            <article class="lm-card lm-history-events-card"><div class="lm-card-head"><div><h3><?php p($l->t('Event log')); ?></h3><small><?php p($l->t('Last 100 state changes, independent of the chart range.')); ?></small></div><span class="lm-help-tip" tabindex="0" title="<?php p($l->t('Changes of important states captured while storing history: UPS, smoke, stale data and selected ESS states.')); ?>">?</span></div><div id="lm-history-events" class="lm-event-list"><div class="lm-empty"><?php p($l->t('No events yet.')); ?></div></div></article>
        </section>
        <section id="lm-tab-analytics" class="lm-tab-panel">
            <section class="lm-card lm-analytics-intro"><div class="lm-card-head"><h3>📊 ANALYTICS</h3><span class="lm-pill"><?php p($l->t('In preparation')); ?></span></div><p><?php p($l->t('This section will build on the same grouped energy model as LIVE and HISTORY. Long-term production, consumption, grid, battery, SPOT prices, sold energy and invoice comparison will be added here without duplicating the LIVE dashboard.')); ?></p></section>
            <section class="lm-analytics-roadmap"><article class="lm-card"><h3>⚡ <?php p($l->t('Energy')); ?></h3><p><?php p($l->t('Day, week, month and year summaries, self-sufficiency, PV use and battery cycles.')); ?></p></article><article class="lm-card"><h3>💰 SPOT / <?php p($l->t('Economy')); ?></h3><p><?php p($l->t('Purchase and sale value by time, price and energy flow.')); ?></p></article><article class="lm-card"><h3>🧾 <?php p($l->t('Invoices')); ?></h3><p><?php p($l->t('Comparison of calculated costs with real invoices and tariff components.')); ?></p></article></section>
        </section>
        <section id="lm-tab-settings" class="lm-tab-panel">
            <article class="lm-card lm-settings-page"><div class="lm-card-head"><h3><?php p($l->t('LINEA connection')); ?></h3></div><div id="lm-settings-page-host"><div class="lm-empty"><?php p($l->t('Loading…')); ?></div></div></article>
        </section>
        </div>
      </section>
    </main>

    <template id="lm-settings-dialog-template">
      <div class="lm-settings-dialog-content">
        <div id="lm-settings-form-host"></div>
        <p class="lm-help"><?php p($l->t('History uses a fast 1-second collector. Second-level values remain in RAM; one aggregate is written to the database every 5 minutes. A Nextcloud background job only supervises whether the collector is running and attempts to restart it after a restart.')); ?></p>
        <p class="lm-help"><?php p($l->t('The browser does not connect to Node-RED directly. Nextcloud reads LINEA API server-side.')); ?></p>
        <p class="lm-help"><strong><?php p($l->t('Supported public API:')); ?></strong> LINEA API 1.x · read-only. <?php p($l->t('The internal data schema number is not shown in the user interface.')); ?></p>
        <div id="lm-test-result" class="lm-test-result" role="status" aria-live="polite"></div>
        <section id="lm-core-about" class="lm-about"></section>
      </div>
    </template>
</template>
