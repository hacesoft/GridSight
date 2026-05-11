/**
 * GridSight · charts.js
 * ══════════════════════════════════════════════════════════
 * Veškerá logika grafů. PHP/HTML se tohoto souboru nedotýká.
 * Volá se přes window.Charts.renderAll(data, appState)
 */

const Charts = (() => {
  'use strict';

  // ── Registry aktivních grafů ──────────────────────────────────
  const registry = {};

  // ── Barvy ─────────────────────────────────────────────────────
  const C = {
    blue:    '#3d8ef0', blueA:   'rgba(61,142,240,.15)',
    green:   '#3dca6e', greenA:  'rgba(61,202,110,.13)',
    amber:   '#f0a020', amberA:  'rgba(240,160,32,.12)',
    red:     '#e84545', redA:    'rgba(232,69,69,.12)',
    purple:  '#9b76f5', purpleA: 'rgba(155,118,245,.13)',
    orange:  '#f07840', orangeA: 'rgba(240,120,64,.12)',
    cyan:    '#20c8d8',
    grid:    'rgba(42,48,80,.6)',
    tick:    '#6b748f',
  };

  // ── Typy grafů (globální stav) ────────────────────────────────
  const types = {
    main:    'bar',
    fve:     'line',
    balance: 'bar',
    hourly:  'line',
    cmpSp:   'bar',
    cmpDo:   'bar',
  };

  // Poslední data pro překreslení při změně typu
  let lastData  = null;
  let lastState = null;

  // ── Helpers ───────────────────────────────────────────────────
  function destroy(id) {
    if (registry[id]) { registry[id].destroy(); delete registry[id]; }
  }

  function el(id) { return document.getElementById(id); }

  function baseOpts(yLabel = '', extra = {}) {
    return {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 280 },
      plugins: {
        legend: { display: false },
        tooltip: {
          mode: 'index', intersect: false,
          callbacks: {
            label: ctx => {
              const v = ctx.parsed?.y;
              if (v == null) return '';
              return ` ${ctx.dataset.label}: ${Number(v).toFixed(3)} ${ctx.dataset.unit || ''}`;
            }
          }
        }
      },
      scales: {
        x: {
          ticks: { color: C.tick, font: { size: 10 }, maxRotation: 45, autoSkip: true, maxTicksLimit: 22 },
          grid:  { color: C.grid },
        },
        y: {
          ticks: { color: C.tick, font: { size: 10 } },
          grid:  { color: C.grid },
          title: { display: !!yLabel, text: yLabel, color: C.tick, font: { size: 11 } },
        },
        ...extra
      }
    };
  }

  function make(canvasId, type, datasets, labels, opts) {
    destroy(canvasId);
    const canvas = el(canvasId);
    if (!canvas) return;
    registry[canvasId] = new Chart(canvas, { type, data: { labels, datasets }, options: opts });
    return registry[canvasId];
  }

  /** Sestaví dataset podle aktuálního typu grafu */
  function ds(label, data, color, colorA, unit = 'kWh', isLine = false, isArea = false, extra = {}) {
    return {
      label, data, unit,
      backgroundColor: isLine ? (isArea ? colorA : 'transparent') : color,
      borderColor:     color,
      borderWidth:     isLine ? 2 : 0,
      fill:            isArea,
      tension:         0.4,
      pointRadius:     0,
      pointHoverRadius:4,
      borderRadius:    isLine ? 0 : 3,
      ...extra
    };
  }

  // ── Metriky ───────────────────────────────────────────────────
  function updateMetrics(data) {
    const t  = data.totals  || {};
    const f  = data.finance || {};
    const mt = data.mppt_totals || [];

    function set(id, val) { const e = el(id); if (e) e.textContent = val ?? '–'; }

    const sp = t.spotreba ?? 0;
    const do_ = t.dodavka  ?? 0;

    set('mSpotreba', sp.toFixed(1) + ' kWh');
    set('mSpotrebaSub', t.days ? (sp / t.days).toFixed(2) + ' kWh/den avg' : '');

    set('mDodavka', do_.toFixed(1) + ' kWh');
    set('mDodavkaSub', t.days ? (do_ / t.days).toFixed(2) + ' kWh/den avg' : '');

    set('mMax', (t.max_sp ?? 0).toFixed(2) + ' / ' + (t.max_do ?? 0).toFixed(2) + ' kW');

    // Vlastní spotřeba odhad
    const fveTotal = mt.reduce((s, r) => s + (r.yield_kwh ?? 0), 0);
    if (fveTotal > 0) {
      const ownP = Math.max(0, ((fveTotal - do_) / fveTotal) * 100);
      set('mSelfSuf', ownP.toFixed(0) + ' %');
    } else {
      const selfEst = sp > 0 ? Math.max(0, (1 - do_ / (sp + do_)) * 100) : 0;
      set('mSelfSuf', selfEst.toFixed(0) + ' % est.');
    }

    // FVE karta
    const fveCard = el('mFveCard');
    if (fveCard) {
      fveCard.style.display = fveTotal > 0 ? '' : 'none';
      set('mFveTotal', fveTotal.toFixed(1) + ' kWh');
      const names = mt.map((r, i) => `T${i}: ${(r.yield_kwh ?? 0).toFixed(1)}`).join(' + ');
      set('mFveSub', names);
    }

    // Bilance
    const bil = f.bilance_czk ?? null;
    const bilEl = el('mBilance');
    if (bilEl && bil !== null) {
      bilEl.textContent = (bil >= 0 ? '+' : '') + bil.toFixed(0) + ' Kč';
      bilEl.style.color = bil >= 0 ? 'var(--green)' : 'var(--red)';
    }
    set('mBilanceSub', f.rocni_odhad_czk != null
      ? 'Roční odhad: ' + (f.rocni_odhad_czk >= 0 ? '+' : '') + f.rocni_odhad_czk.toFixed(0) + ' Kč'
      : '');

    // Finance panel
    set('fNaklady',    f.naklady_czk != null ? f.naklady_czk.toFixed(1) + ' Kč' : '–');
    set('fNakladySub', sp.toFixed(1) + ' kWh × ' + (f.tariff_import ?? 0) + ' Kč');
    set('fPrijem',     f.prijem_czk  != null ? f.prijem_czk.toFixed(1)  + ' Kč' : '–');
    set('fPrijemSub',  do_.toFixed(1) + ' kWh × ' + (f.tariff_export ?? 0) + ' Kč');
    set('fUspora',     f.uspora_czk  != null ? f.uspora_czk.toFixed(1)  + ' Kč' : '–');
    set('fUspSub',     f.own_consumption_kwh != null
      ? (f.own_consumption_kwh.toFixed(1) + ' kWh × ' + (f.tariff_own ?? 0) + ' Kč') : 'data z MPPT chybí');
    set('fRocni', f.rocni_odhad_czk != null
      ? (f.rocni_odhad_czk >= 0 ? '+' : '') + f.rocni_odhad_czk.toFixed(0) + ' Kč/rok' : '–');
  }

  // ── Hlavní grafy ──────────────────────────────────────────────
  function drawMain(grid) {
    if (!el('chartMain')) return;
    const labels = grid.map(r => r.period ?? r.ts ?? '');
    const sp     = grid.map(r => r.spotreba ?? r.DCC0 ?? 0);
    const dv     = grid.map(r => r.dodavka  ?? r.DSC0 ?? 0);
    const t      = types.main;
    const line   = t !== 'bar';
    const area   = t === 'area';
    make('chartMain', line ? 'line' : 'bar',
      [ ds('Odběr (DCC0)',   sp, C.blue,  C.blueA,  'kWh', line, area),
        ds('Dodávka (DSC0)', dv, C.green, C.greenA, 'kWh', line, area) ],
      labels, baseOpts('kWh'));
  }

  function drawBalance(grid) {
    if (!el('chartBalance')) return;
    const labels = grid.map(r => r.period ?? r.ts ?? '');
    const bal    = grid.map(r => +((r.spotreba ?? 0) - (r.dodavka ?? 0)).toFixed(3));
    const t      = types.balance;
    const line   = t !== 'bar';
    const colors = bal.map(v => v >= 0 ? C.amber : C.green);
    make('chartBalance', line ? 'line' : 'bar',
      [{ label:'Bilance', data: bal, unit:'kWh',
         backgroundColor: line ? C.amberA : colors,
         borderColor: line ? C.amber : colors,
         borderWidth: line ? 2 : 0,
         fill: false, tension: 0.4, pointRadius: 0, borderRadius: 3 }],
      labels, baseOpts('kWh'));
  }

  function drawHourly(hourly) {
    if (!el('chartHourly')) return;
    const labels = hourly.map(h => h.hour + ':00');
    const t      = types.hourly;
    const line   = t !== 'bar';
    const area   = t === 'area';
    make('chartHourly', line ? 'line' : 'bar',
      [ ds('Odběr', hourly.map(h => h.avg_sp), C.blue,  C.blueA,  'kW', line, area, { pointRadius: 2 }),
        ds('FVE',   hourly.map(h => h.avg_do), C.green, C.greenA, 'kW', line, area, { pointRadius: 2 }) ],
      labels, baseOpts('kW (průměr)'));
  }

  function drawFve(mppt, system) {
    const fveCard = el('card-fve');
    if (!el('chartFve') || (!mppt.length && !system.length)) {
      if (fveCard) fveCard.style.display = 'none';
      return;
    }
    if (fveCard) fveCard.style.display = '';

    const t    = types.fve;
    const line = t !== 'bar';
    const area = t === 'area';

    // Získáme data z mppt nebo system
    let labels, t0, t1, soc;
    if (mppt.length) {
      const m0 = mppt.filter(r => r.tracker_id === 0 || r.tracker_id === '0');
      const m1 = mppt.filter(r => r.tracker_id === 1 || r.tracker_id === '1');
      labels   = [...new Set(mppt.map(r => r.period ?? r.ts))];
      const byPer = (arr) => Object.fromEntries(arr.map(r => [r.period ?? r.ts, r]));
      const b0 = byPer(m0), b1 = byPer(m1);
      t0  = labels.map(l => (b0[l]?.avg_power ?? b0[l]?.pv_power) ?? null);
      t1  = labels.map(l => (b1[l]?.avg_power ?? b1[l]?.pv_power) ?? null);
      soc = null;
    } else {
      labels = system.map(r => r.period ?? r.ts ?? '');
      t0     = system.map(r => r.avg_pv ?? null);
      t1     = null;
      soc    = system.map(r => r.avg_soc ?? null);
    }

    const datasets = [];
    if (t0)  datasets.push(ds('Tracker 1', t0, C.amber,  C.amberA,  'W', line, area));
    if (t1)  datasets.push(ds('Tracker 2', t1, C.orange, C.orangeA, 'W', line, area));
    if (soc) datasets.push({
      label: 'SOC (%)', data: soc, unit: '%',
      borderColor: C.purple, backgroundColor: 'transparent',
      borderWidth: 2, fill: false, tension: 0.4, pointRadius: 0,
      yAxisID: 'y2'
    });

    make('chartFve', line ? 'line' : 'bar', datasets, labels,
      baseOpts('W', soc ? {
        y2: { type: 'linear', position: 'right', min: 0, max: 100,
               ticks: { color: C.tick, font: { size: 10 } },
               grid: { drawOnChartArea: false },
               title: { display: true, text: 'SOC %', color: C.tick } }
      } : {}));
  }

  // ── Compare grafy ─────────────────────────────────────────────
  function renderCompare(A, B, cmpState) {
    const maxLen = Math.max(A.grid.length, B.grid.length);
    const labels = Array.from({ length: maxLen }, (_, i) => i + 1);

    const spA = A.grid.map(r => r.spotreba ?? 0);
    const spB = B.grid.map(r => r.spotreba ?? 0);
    const dvA = A.grid.map(r => r.dodavka  ?? 0);
    const dvB = B.grid.map(r => r.dodavka  ?? 0);

    const lineS = types.cmpSp !== 'bar';
    const lineD = types.cmpDo !== 'bar';

    destroy('chartCmpSp');
    if (el('chartCmpSp')) {
      registry['chartCmpSp'] = new Chart(el('chartCmpSp'), {
        type: lineS ? 'line' : 'bar',
        data: { labels, datasets: [
          ds(A.label || 'Období A', spA, C.blue,   C.blueA,   'kWh', lineS, false),
          ds(B.label || 'Období B', spB, C.purple, C.purpleA, 'kWh', lineS, false),
        ]},
        options: { ...baseOpts('kWh'),
          plugins: { legend: { display: true, labels: { color: C.tick, font: { size: 11 } } } } }
      });
    }

    destroy('chartCmpDo');
    if (el('chartCmpDo')) {
      registry['chartCmpDo'] = new Chart(el('chartCmpDo'), {
        type: lineD ? 'line' : 'bar',
        data: { labels, datasets: [
          ds(A.label || 'Období A', dvA, C.green, C.greenA, 'kWh', lineD, false),
          ds(B.label || 'Období B', dvB, C.amber, C.amberA, 'kWh', lineD, false),
        ]},
        options: { ...baseOpts('kWh'),
          plugins: { legend: { display: true, labels: { color: C.tick, font: { size: 11 } } } } }
      });
    }
  }

  // ── Hlavní entry point ────────────────────────────────────────
  function renderAll(data, state) {
    lastData  = data;
    lastState = state;
    const grid   = data.grid   || [];
    const hourly = data.hourly || [];
    const mppt   = data.mppt   || [];
    const system = data.system || [];

    updateMetrics(data);
    drawMain(grid);
    drawBalance(grid);
    drawHourly(hourly);
    drawFve(mppt, system);
  }

  // ── Přepnutí typu grafu ───────────────────────────────────────
  function setType(key, type, btn) {
    types[key] = type;

    // Aktualizuj aktivní tlačítko
    if (btn) {
      const sw = btn.closest('.type-sw');
      if (sw) sw.querySelectorAll('.sw-btn').forEach(b => b.classList.toggle('active', b === btn));
    }

    // Překresli příslušný graf
    if (!lastData) return;
    const g = lastData.grid || [];
    if (key === 'main')    drawMain(g);
    if (key === 'balance') drawBalance(g);
    if (key === 'hourly')  drawHourly(lastData.hourly || []);
    if (key === 'fve')     drawFve(lastData.mppt || [], lastData.system || []);
    if (key === 'cmpSp' || key === 'cmpDo') {
      // Compare překreslení probíhá přes renderCompare volaném z Alpine
    }
  }

  // ── Toast helper (dostupný globálně) ─────────────────────────
  window.showToast = (msg, type = 'ok') => {
    window._app?.toast(msg, type);
  };

  // Public API
  return { renderAll, renderCompare, setType };

})();

// Dostupné globálně pro Alpine.js a inline atributy
window.Charts = Charts;
