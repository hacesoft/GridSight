<!-- soubor: compare.php -->
<div x-data="comparePage()">

<div class="card">
  <div class="card-head"><span class="card-title">⚖️ Srovnání dvou období</span></div>
  <div class="cmp-form">
    <div class="cmp-period">
      <div class="cmp-badge blue">Období A</div>
      <div class="field-row">
        <div class="field"><label>Od</label><input type="date" class="inp" x-model="aFrom"></div>
        <div class="field"><label>Do</label><input type="date" class="inp" x-model="aTo"></div>
      </div>
    </div>
    <div class="cmp-vs">VS</div>
    <div class="cmp-period">
      <div class="cmp-badge purple">Období B</div>
      <div class="field-row">
        <div class="field"><label>Od</label><input type="date" class="inp" x-model="bFrom"></div>
        <div class="field"><label>Do</label><input type="date" class="inp" x-model="bTo"></div>
      </div>
    </div>
    <div class="field">
      <label>Seskupení</label>
      <select class="inp" x-model="group">
        <option value="day">Den</option>
        <option value="week">Týden</option>
        <option value="month">Měsíc</option>
        <option value="hour">Hodina</option>
      </select>
    </div>
    <button class="btn primary" @click="runCompare()" :disabled="loading">
      <span x-text="loading ? '⏳ Načítám...' : '↗ Porovnat'"></span>
    </button>
  </div>
</div>

<div x-show="results" x-transition>

  <!-- Srovnávací metriky -->
  <div class="cmp-metrics" id="cmpMetrics"></div>

  <!-- Graf: odběr -->
  <div class="card">
    <div class="card-head">
      <span class="card-title">Odběr ze sítě – srovnání</span>
      <div class="type-sw">
        <button class="sw-btn active" @click="Charts.setType('cmpSp','bar',$event)">▮▮</button>
        <button class="sw-btn"        @click="Charts.setType('cmpSp','line',$event)">↗</button>
      </div>
    </div>
    <div class="chart-box h260"><canvas id="chartCmpSp"></canvas></div>
  </div>

  <!-- Graf: dodávka FVE -->
  <div class="card">
    <div class="card-head">
      <span class="card-title">Dodávka FVE – srovnání</span>
      <div class="type-sw">
        <button class="sw-btn active" @click="Charts.setType('cmpDo','bar',$event)">▮▮</button>
        <button class="sw-btn"        @click="Charts.setType('cmpDo','line',$event)">↗</button>
      </div>
    </div>
    <div class="chart-box h260"><canvas id="chartCmpDo"></canvas></div>
  </div>

  <!-- Finance srovnání -->
  <div class="card">
    <div class="card-head"><span class="card-title">💰 Finanční srovnání</span></div>
    <div id="cmpFinTable"></div>
  </div>

</div><!-- /results -->

</div><!-- /x-data -->

<script>
function comparePage() {
  return {
    aFrom: '', aTo: '', bFrom: '', bTo: '',
    group: 'day', loading: false, results: false,

    async runCompare() {
      if (!this.aFrom||!this.aTo||!this.bFrom||!this.bTo) {
        window._app?.toast('Vyplňte všechna data','err'); return;
      }
      this.loading = true;
      const url = `/api.php?action=compare&a_from=${this.aFrom}&a_to=${this.aTo}&b_from=${this.bFrom}&b_to=${this.bTo}&group=${this.group}`;
      const data = await fetch(url).then(r=>r.json());
      this.loading = false;
      if (data.error) { window._app?.toast('Chyba: '+data.error,'err'); return; }
      this.results = true;
      Charts.renderCompare(data.A, data.B, this);
      this.renderFinTable(data.A.finance, data.B.finance, data.A.label, data.B.label);
      this.renderMetrics(data.A, data.B);
    },

    renderMetrics(A, B) {
      const diff = (a,b,unit) => {
        const d = b-a, pct = a ? ((d/a)*100).toFixed(0) : '–';
        const cls = d > 0 ? 'red' : 'green';
        return `<span class="${cls}">${d>0?'▲':'▼'} ${Math.abs(d).toFixed(1)} ${unit} (${Math.abs(pct)}%)</span>`;
      };
      document.getElementById('cmpMetrics').innerHTML = `
        <div class="cmp-met-row">
          <div class="cmp-met blue"><div class="m-label">A – Odběr</div><div class="m-value">${(A.totals.spotreba||0).toFixed(1)} kWh</div><div class="m-sub">${A.label}</div></div>
          <div class="cmp-met purple"><div class="m-label">B – Odběr</div><div class="m-value">${(B.totals.spotreba||0).toFixed(1)} kWh</div><div class="m-sub">${B.label}</div><div>${diff(A.totals.spotreba||0,B.totals.spotreba||0,'kWh')}</div></div>
          <div class="cmp-met green"><div class="m-label">A – FVE dodávka</div><div class="m-value">${(A.totals.dodavka||0).toFixed(1)} kWh</div></div>
          <div class="cmp-met amber"><div class="m-label">B – FVE dodávka</div><div class="m-value">${(B.totals.dodavka||0).toFixed(1)} kWh</div><div>${diff(A.totals.dodavka||0,B.totals.dodavka||0,'kWh')}</div></div>
        </div>`;
    },

    renderFinTable(fA, fB, lA, lB) {
      const rows = [
        ['Náklady za odběr', fA.naklady_czk, fB.naklady_czk, 'Kč', true],
        ['Příjem za export',  fA.prijem_czk,  fB.prijem_czk,  'Kč', false],
        ['Úspora vlastní',    fA.uspora_czk,  fB.uspora_czk,  'Kč', false],
        ['Bilance celkem',    fA.bilance_czk, fB.bilance_czk, 'Kč', false],
      ];
      const fmtDiff = (a,b,inv) => {
        if (a==null||b==null) return '–';
        const d = b-a; const better = inv ? d<0 : d>0;
        return `<span class="${better?'green':'red'}">${d>0?'+':''}${d.toFixed(1)} Kč</span>`;
      };
      document.getElementById('cmpFinTable').innerHTML =
        `<table class="fin-table">
          <thead><tr><th>Ukazatel</th><th>${lA}</th><th>${lB}</th><th>Rozdíl</th></tr></thead>
          <tbody>${rows.map(([n,a,b,u,inv])=>`
            <tr><td>${n}</td>
                <td>${a!=null?a.toFixed(1):'–'} ${u}</td>
                <td>${b!=null?b.toFixed(1):'–'} ${u}</td>
                <td>${fmtDiff(a,b,inv)}</td></tr>`).join('')}
          </tbody></table>`;
    },
  };
}
</script>
