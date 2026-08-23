<?php /** @var array $settings */ ?>

<div x-data="settingsPage()" x-init="load()">

<!-- Cerbo GX / Modbus -->
<div class="card">
  <div class="card-head"><span class="card-title">☀️ Cerbo GX – Modbus TCP</span></div>
  <div class="settings-grid">
    <div class="field">
      <label>IP adresa Cerbo GX</label>
      <input class="inp" type="text" x-model="s.cerbo_host" placeholder="192.168.1.100">
      <span class="hint">Cerbo display → Settings → Ethernet/WiFi → IP Address</span>
    </div>
    <div class="field">
      <label>Port (výchozí 502)</label>
      <input class="inp" type="number" x-model="s.cerbo_port">
    </div>
    <div class="field">
      <label>Unit ID – Tracker 1</label>
      <input class="inp" type="number" x-model="s.modbus_unit_t0">
      <span class="hint">Cerbo → Settings → Services → Modbus TCP → Available services</span>
    </div>
    <div class="field">
      <label>Unit ID – Tracker 2</label>
      <input class="inp" type="number" x-model="s.modbus_unit_t1">
    </div>
    <div class="field">
      <label>Unit ID – Systém (grid/SOC)</label>
      <input class="inp" type="number" x-model="s.modbus_unit_system">
    </div>
    <div class="field">
      <label>Interval sběru dat (s)</label>
      <input class="inp" type="number" x-model="s.modbus_interval" min="60" step="60">
      <span class="hint">Výchozí 300 = každých 5 minut</span>
    </div>
    <div class="field">
      <label>Název Trackeru 1</label>
      <input class="inp" type="text" x-model="s.tracker_0_name" placeholder="Tracker 1 (JV)">
    </div>
    <div class="field">
      <label>Název Trackeru 2</label>
      <input class="inp" type="text" x-model="s.tracker_1_name" placeholder="Tracker 2 (JZ)">
    </div>
  </div>
  <div class="modbus-status card-inner">
    <span class="status-dot" :class="modbusOk?'ok':'off'"></span>
    <span x-text="modbusOk ? 'Poslední záznam: '+lastRecord : 'Žádná Modbus data (Python service běží?)'"></span>
  </div>
</div>

<!-- Tarify -->
<div class="card">
  <div class="card-head"><span class="card-title">💰 Tarify a ceny energie</span></div>
  <div class="settings-grid">
    <div class="field">
      <label>Cena odběru ze sítě (Kč/kWh)</label>
      <input class="inp" type="number" step="0.01" x-model="s.tariff_import">
      <span class="hint">Váš aktuální tarif (VT nebo jednotarifl)</span>
    </div>
    <div class="field">
      <label>Výkupní cena dodávky do sítě (Kč/kWh)</label>
      <input class="inp" type="number" step="0.01" x-model="s.tariff_export">
      <span class="hint">Ze smlouvy s obchodníkem / OTE</span>
    </div>
    <div class="field">
      <label>Cena vlastní spotřeby FVE (Kč/kWh)</label>
      <input class="inp" type="number" step="0.01" x-model="s.tariff_own">
      <span class="hint">= ušetřená cena (zpravidla = cena odběru)</span>
    </div>
  </div>
  <div class="tariff-preview">
    <strong>Příklad:</strong> při dodávce 100 kWh do sítě =
    <span x-text="(100 * parseFloat(s.tariff_export||0)).toFixed(0)"></span> Kč příjmu.
    Při vlastní spotřebě 100 kWh =
    <span x-text="(100 * parseFloat(s.tariff_own||0)).toFixed(0)"></span> Kč úspory.
  </div>
</div>

<!-- Ostatní -->
<div class="card">
  <div class="card-head"><span class="card-title">⚙️ Ostatní nastavení</span></div>
  <div class="settings-grid">
    <div class="field">
      <label>EAN / číslo odběrného místa</label>
      <input class="inp" type="text" x-model="s.ean" placeholder="859182400212009995">
    </div>
    <div class="field">
      <label>Sledovaná složka (auto-import XLSX)</label>
      <input class="inp" type="text" x-model="s.watch_folder" placeholder="/data/incoming">
      <span class="hint">Python service hlídá složku a importuje nové REP_DATA_*.xlsx</span>
    </div>
  </div>
</div>

<!-- Uložit -->
<div class="card-actions">
  <button class="btn primary lg" @click="save()" :disabled="saving">
    <span x-text="saving ? '⏳ Ukládám...' : '💾 Uložit nastavení'"></span>
  </button>
  <span class="save-ok" x-show="saved" x-transition>✓ Uloženo</span>
</div>

<!-- Info -->
<div class="card info-card">
  <div class="card-head"><span class="card-title">ℹ️ Jak nastavit HTMX / Alpine.js / Modbus</span></div>
  <div class="info-grid">
    <div class="info-item">
      <div class="info-title">🦎 HTMX</div>
      <div class="info-text">Dynamické HTML bez psaní JavaScriptu. <code>hx-get</code>, <code>hx-post</code>, <code>hx-target</code> atributy v HTML volají PHP API.</div>
    </div>
    <div class="info-item">
      <div class="info-title">🏔️ Alpine.js</div>
      <div class="info-text">Reaktivní stav přímo v HTML atributech (<code>x-data</code>, <code>x-show</code>, <code>@click</code>). Není třeba psát JS třídy ani frameworky.</div>
    </div>
    <div class="info-item">
      <div class="info-title">🐍 Python Modbus service</div>
      <div class="info-text">Běží v samostatném Docker kontejneru, každých N sekund čte Cerbo GX přes Modbus TCP a ukládá do sdílené SQLite DB.</div>
    </div>
    <div class="info-item">
      <div class="info-title">📊 Chart.js</div>
      <div class="info-text">Grafy jsou renderovány na canvas elementech. Minimální JS kód v <code>/assets/js/charts.js</code>.</div>
    </div>
  </div>
</div>

</div><!-- /x-data -->

<script>
function settingsPage() {
  return {
    s: {}, saving: false, saved: false,
    lastRecord: null, modbusOk: false,

    async load() {
      this.s = await fetch('/api.php?action=settings').then(r=>r.json());
      this.lastRecord = this.s.modbus_last_record?.slice(0,16).replace('T',' ') ?? null;
      if (this.lastRecord) {
        this.modbusOk = (Date.now() - new Date(this.s.modbus_last_record).getTime()) < 30*60*1000;
      }
    },

    async save() {
      this.saving = true;
      const r = await fetch('/api.php?action=settings', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify(this.s),
      }).then(x=>x.json());
      this.saving = false;
      if (r.ok) { this.saved = true; setTimeout(()=>this.saved=false,3000); }
      window._app?.toast(r.ok ? '✓ Nastavení uloženo' : '✗ Chyba: '+r.error, r.ok?'ok':'err');
    },
  };
}
</script>
