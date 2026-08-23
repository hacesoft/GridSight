<?php /** @var array $settings */ ?>

<!-- ══ METRIKY ══════════════════════════════════════════════════ -->
<div class="metrics" id="metrics">
  <div class="metric m-blue">
    <div class="m-icon">🔌</div>
    <div class="m-label">Odběr ze sítě</div>
    <div class="m-value" id="mSpotreba">–</div>
    <div class="m-sub"   id="mSpotrebaSub"></div>
  </div>
  <div class="metric m-green">
    <div class="m-icon">☀️</div>
    <div class="m-label">Dodávka FVE do sítě</div>
    <div class="m-value" id="mDodavka">–</div>
    <div class="m-sub"   id="mDodavkaSub"></div>
  </div>
  <div class="metric m-amber">
    <div class="m-icon">⚡</div>
    <div class="m-label">Max. odběr / FVE</div>
    <div class="m-value" id="mMax">–</div>
    <div class="m-sub" id="mMaxSub">peak 15 min</div>
  </div>
  <div class="metric m-purple">
    <div class="m-icon">🏠</div>
    <div class="m-label">Vlastní spotřeba FVE</div>
    <div class="m-value" id="mSelfSuf">–</div>
    <div class="m-sub">odhad z bilance</div>
  </div>
  <div class="metric m-green" id="mFveCard" style="display:none">
    <div class="m-icon">🔋</div>
    <div class="m-label">FVE výroba celkem</div>
    <div class="m-value" id="mFveTotal">–</div>
    <div class="m-sub"   id="mFveSub"></div>
  </div>
  <div class="metric m-red-green">
    <div class="m-icon">💰</div>
    <div class="m-label">Bilance (Kč)</div>
    <div class="m-value" id="mBilance">–</div>
    <div class="m-sub"   id="mBilanceSub"></div>
  </div>
</div>

<!-- ══ FINANCE PANEL ════════════════════════════════════════════ -->
<div class="finance-grid" id="finance-panel">
  <div class="fin-card">
    <div class="fin-top"><span class="fin-icon red">💸</span><span class="fin-label">Náklady za odběr</span></div>
    <div class="fin-val red"    id="fNaklady">–</div>
    <div class="fin-sub"        id="fNakladySub"></div>
  </div>
  <div class="fin-card">
    <div class="fin-top"><span class="fin-icon green">💰</span><span class="fin-label">Příjem za export</span></div>
    <div class="fin-val green"  id="fPrijem">–</div>
    <div class="fin-sub"        id="fPrijemSub"></div>
  </div>
  <div class="fin-card">
    <div class="fin-top"><span class="fin-icon purple">🏠</span><span class="fin-label">Úspora vlastní spot.</span></div>
    <div class="fin-val purple" id="fUspora">–</div>
    <div class="fin-sub"        id="fUspSub"></div>
  </div>
  <div class="fin-card">
    <div class="fin-top"><span class="fin-icon amber">📈</span><span class="fin-label">Roční odhad</span></div>
    <div class="fin-val amber"  id="fRocni">–</div>
    <div class="fin-sub">při stejném trendu</div>
  </div>
</div>

<!-- ══ GRAFICKÁ ČÁST ════════════════════════════════════════════ -->

<!-- Graf: Odběr vs Dodávka -->
<div class="card" x-show="layerGrid">
  <div class="card-head">
    <span class="card-title">Odběr ze sítě · Dodávka FVE</span>
    <div class="legend-row">
      <span class="leg"><span class="leg-dot blue"></span>Odběr DCC0</span>
      <span class="leg"><span class="leg-dot green"></span>Dodávka DSC0</span>
    </div>
    <div class="type-sw" id="swMain">
      <button class="sw-btn active" @click="Charts.setType('main','bar',$event)">▮▮</button>
      <button class="sw-btn"        @click="Charts.setType('main','line',$event)">↗</button>
      <button class="sw-btn"        @click="Charts.setType('main','area',$event)">◭</button>
    </div>
  </div>
  <div class="chart-box h280"><canvas id="chartMain"></canvas></div>
</div>

<!-- Graf: FVE trackery (jen pokud Cerbo data) -->
<div class="card" id="card-fve" style="display:none" x-show="layerFve">
  <div class="card-head">
    <span class="card-title">
      FVE výkon · <?= htmlspecialchars($settings['tracker_0_name'] ?? 'Tracker 1') ?>
                + <?= htmlspecialchars($settings['tracker_1_name'] ?? 'Tracker 2') ?>
    </span>
    <div class="legend-row">
      <span class="leg"><span class="leg-dot amber"></span><?= htmlspecialchars($settings['tracker_0_name'] ?? 'T1') ?></span>
      <span class="leg"><span class="leg-dot orange"></span><?= htmlspecialchars($settings['tracker_1_name'] ?? 'T2') ?></span>
      <span class="leg"><span class="leg-dot purple"></span>Baterie SOC %</span>
    </div>
    <div class="type-sw">
      <button class="sw-btn"        @click="Charts.setType('fve','bar',$event)">▮▮</button>
      <button class="sw-btn active" @click="Charts.setType('fve','line',$event)">↗</button>
      <button class="sw-btn"        @click="Charts.setType('fve','area',$event)">◭</button>
    </div>
  </div>
  <div class="chart-box h260"><canvas id="chartFve"></canvas></div>
</div>

<!-- Graf: Bilance -->
<div class="card" id="card-balance">
  <div class="card-head">
    <span class="card-title">Denní bilance · záporné = čistý vývozce FVE</span>
    <div class="type-sw">
      <button class="sw-btn active" @click="Charts.setType('balance','bar',$event)">▮▮</button>
      <button class="sw-btn"        @click="Charts.setType('balance','line',$event)">↗</button>
    </div>
  </div>
  <div class="chart-box h200"><canvas id="chartBalance"></canvas></div>
</div>

<!-- Graf: Hodinový profil -->
<div class="card">
  <div class="card-head">
    <span class="card-title">Průměrný hodinový profil</span>
    <div class="legend-row">
      <span class="leg"><span class="leg-dot blue"></span>Odběr průměr (kW)</span>
      <span class="leg"><span class="leg-dot green"></span>FVE průměr (kW)</span>
    </div>
    <div class="type-sw">
      <button class="sw-btn"        @click="Charts.setType('hourly','bar',$event)">▮▮</button>
      <button class="sw-btn active" @click="Charts.setType('hourly','line',$event)">↗</button>
      <button class="sw-btn"        @click="Charts.setType('hourly','area',$event)">◭</button>
    </div>
  </div>
  <div class="chart-box h220"><canvas id="chartHourly"></canvas></div>
</div>
