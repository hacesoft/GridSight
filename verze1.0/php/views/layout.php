<!DOCTYPE html>
<html lang="cs">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>GridSight · <?= ucfirst($page) ?></title>
  <link rel="stylesheet" href="/assets/css/style.css">

  <!--
    HTMX  – dynamické HTML aktualizace přes HTML atributy, bez psaní JS.
            Např: <button hx-post="/api.php" hx-target="#result"> → pošle request a vloží odpověď.
    Alpine.js – reaktivní stav přímo v HTML atributech (x-data, x-show, @click).
            Nahrazuje 90 % toho, co by jinak bylo v JS souborech.
    Chart.js  – grafy. Minimální JS v assets/js/charts.js.
  -->
  <script src="https://unpkg.com/htmx.org@2.0.4/dist/htmx.min.js" defer></script>
  <script src="https://unpkg.com/alpinejs@3.14.1/dist/cdn.min.js"  defer></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>

<body x-data="app()" x-init="init()">

<!-- ── Loading bar ── -->
<div class="loading-bar" x-show="loading" x-transition.opacity></div>

<!-- ══════ HEADER ══════════════════════════════════════════════════ -->
<header class="header">
  <div class="header-logo">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
    </svg>
    <span class="logo-name">GridSight</span>
    <span class="logo-sub">Energetická bilance</span>
  </div>

  <nav class="header-nav">
    <a href="/?page=dashboard" class="hnav <?= $page==='dashboard'?'active':'' ?>">📊 Dashboard</a>
    <a href="/?page=files"     class="hnav <?= $page==='files'    ?'active':'' ?>">📁 Soubory</a>
    <a href="/?page=compare"   class="hnav <?= $page==='compare'  ?'active':'' ?>">⚖️ Srovnání</a>
    <a href="/?page=settings"  class="hnav <?= $page==='settings' ?'active':'' ?>">⚙️ Nastavení</a>
  </nav>

  <div class="header-status">
    <span class="status-dot" :class="cerboOnline ? 'ok' : 'off'"></span>
    <span x-text="cerboOnline ? 'Cerbo GX online' : 'Cerbo GX offline'"></span>
    <span class="range-badge" x-text="rangeLabel" x-show="rangeLabel"></span>
  </div>
</header>

<!-- ══════ LAYOUT ══════════════════════════════════════════════════ -->
<div class="layout">

  <!-- ── SIDEBAR ───────────────────────────────────────────────── -->
  <aside class="sidebar">

    <?php if ($page === 'dashboard'): ?>

    <div class="sb-block">
      <p class="sb-label">Časový rozsah</p>
      <div class="pills">
        <?php foreach ([
          'today'=>'Dnes','week'=>'Týden','month'=>'Měsíc',
          'last30'=>'30 dní','quarter'=>'Kvartál','year'=>'Rok',
          'all'=>'Vše','custom'=>'Vlastní'
        ] as $k => $v): ?>
          <span class="pill"
                :class="{active: quickRange==='<?= $k ?>'}"
                @click="setRange('<?= $k ?>')"><?= $v ?></span>
        <?php endforeach; ?>
      </div>
      <div x-show="quickRange==='custom'" x-transition class="custom-range">
        <div class="field">
          <label>Od</label>
          <input type="date" class="inp" x-model="dateFrom" @change="reload()">
        </div>
        <div class="field">
          <label>Do</label>
          <input type="date" class="inp" x-model="dateTo"   @change="reload()">
        </div>
      </div>
    </div>

    <div class="sb-block">
      <p class="sb-label">Seskupení dat</p>
      <div class="pills">
        <?php foreach (['15min'=>'15 min','hour'=>'Hodina','day'=>'Den','week'=>'Týden','month'=>'Měsíc'] as $k=>$v): ?>
          <span class="pill" :class="{active: group==='<?= $k ?>'}" @click="setGroup('<?= $k ?>')"><?= $v ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="sb-block">
      <p class="sb-label">Vrstvy</p>
      <label class="toggle"><input type="checkbox" x-model="layerGrid"    @change="reload()">  Síť – odběr / dodávka</label>
      <label class="toggle"><input type="checkbox" x-model="layerFve"     @change="reload()">  FVE + Baterie (Cerbo)</label>
      <label class="toggle"><input type="checkbox" x-model="layerBalance" @change="toggleBalance()"> Bilance (Kč)</label>
      <label class="toggle"><input type="checkbox" x-model="layerFinance" @change="toggleFinance()"> Finance panel</label>
    </div>

    <div class="sb-block sb-meta-block">
      <p class="sb-label">Info</p>
      <div class="meta-row"><span>Rozsah dat</span><span x-text="dataRange">–</span></div>
      <div class="meta-row"><span>Záznamy</span><span x-text="recordCount">–</span></div>
      <div class="meta-row"><span>Poslední Modbus</span><span x-text="lastModbus || 'žádná data'">–</span></div>
    </div>

    <?php endif; ?>

  </aside>

  <!-- ── MAIN ──────────────────────────────────────────────────── -->
  <main class="main">
    <?php require __DIR__ . "/{$page}.php"; ?>
  </main>

</div>

<!-- ── Toast ─────────────────────────────────────────────────────── -->
<div class="toast" :class="['toast', toastType, toastShow ? 'show' : '']" x-text="toastMsg"></div>

<!-- ── JS ────────────────────────────────────────────────────────── -->
<script src="/assets/js/charts.js"></script>
<script>
function app() {
  return {
    // Stav rozsahu
    quickRange: 'last30',
    group:      'day',
    dateFrom:   '<?= $dateMin ?>',
    dateTo:     '<?= $dateMax ?>',
    rangeLabel: '',

    // Vrstvy
    layerGrid:    true,
    layerFve:     true,
    layerBalance: true,
    layerFinance: true,

    // UI stav
    loading:      false,
    cerboOnline:  false,
    lastModbus:   null,
    dataRange:    '–',
    recordCount:  '–',
    toastShow:    false,
    toastMsg:     '',
    toastType:    'ok',
    chartTypes: { main:'bar', hourly:'line', balance:'bar', fve:'line', cmpSp:'bar', cmpDo:'bar' },

    init() {
      <?php if ($page === 'dashboard'): ?>
      this.setRange('last30');
      this.checkCerbo();
      setInterval(() => this.checkCerbo(), 60000);
      <?php endif; ?>
      window._app = this;
    },

    setRange(q) {
      this.quickRange = q;
      if (q === 'custom') return;
      const now   = new Date();
      const today = now.toISOString().slice(0,10);
      const ago   = n => { const d = new Date(now); d.setDate(d.getDate()-n); return d.toISOString().slice(0,10); };
      const map   = {
        today:   [today, today],
        week:    [ago(6), today],
        month:   [today.slice(0,7)+'-01', today],
        last30:  [ago(29), today],
        quarter: [ago(89), today],
        year:    [today.slice(0,4)+'-01-01', today],
        all:     ['2000-01-01', '2099-12-31'],
      };
      [this.dateFrom, this.dateTo] = map[q] ?? [today, today];
      this.rangeLabel = this.dateFrom + ' – ' + this.dateTo;
      this.reload();
    },

    setGroup(g) { this.group = g; this.reload(); },

    async reload() {
      if (!this.dateFrom || !this.dateTo) return;
      this.loading = true;
      this.rangeLabel = this.dateFrom + ' – ' + this.dateTo;
      try {
        const url  = `/api.php?action=data&from=${this.dateFrom}T00:00:00&to=${this.dateTo}T23:59:59&group=${this.group}`;
        const data = await fetch(url).then(r => r.json());
        if (data.error) throw new Error(data.error);
        Charts.renderAll(data, this);
        this.recordCount = (data.grid?.length ?? 0) + ' zázn.';
        this.dataRange   = (data.totals?.first_ts ?? '–').slice(0,7) + ' – ' + (data.totals?.last_ts ?? '–').slice(0,7);
      } catch(e) { this.toast('Chyba: ' + e.message, 'err'); }
      this.loading = false;
    },

    toggleBalance() {
      const el = document.getElementById('card-balance');
      if (el) el.style.display = this.layerBalance ? '' : 'none';
    },
    toggleFinance() {
      const el = document.getElementById('finance-panel');
      if (el) el.style.display = this.layerFinance ? '' : 'none';
    },

    async checkCerbo() {
      try {
        const r = await fetch('/api.php?action=modbus_status').then(x => x.json());
        this.lastModbus = r.last_record ? r.last_record.slice(0,16).replace('T',' ') : null;
        if (r.last_record) {
          this.cerboOnline = (Date.now() - new Date(r.last_record).getTime()) < 30*60*1000;
        }
      } catch {}
    },

    toast(msg, type = 'ok') {
      this.toastMsg  = msg;
      this.toastType = type;
      this.toastShow = true;
      setTimeout(() => this.toastShow = false, 3500);
    },
  };
}
</script>
</body>
</html>
