<div x-data="filesPage()" x-init="load()">

<!-- Upload zona -->
<div class="card">
  <div class="card-head"><span class="card-title">📂 Nahrát report od distributora</span></div>
  <div class="drop-zone"
       :class="{drag: dragging}"
       @click="$refs.fi.click()"
       @dragover.prevent="dragging=true"
       @dragleave="dragging=false"
       @drop.prevent="onDrop($event)">
    <input type="file" x-ref="fi" accept=".xlsx" multiple style="display:none" @change="upload($event.target.files)">
    <div class="dz-icon">📁</div>
    <div class="dz-text"><strong>REP_DATA_*.xlsx</strong></div>
    <div class="dz-sub">Přetáhněte nebo klikněte · více souborů najednou</div>
    <div class="dz-hint">Portál distributora: ČEZ / EG.D / PRE → Zákaznická zóna → Měření</div>
  </div>

  <!-- Progress -->
  <div class="upload-list" x-show="queue.length>0">
    <template x-for="item in queue" :key="item.name">
      <div class="upload-item" :class="item.status">
        <span x-text="item.name"></span>
        <span class="upst" x-text="item.msg"></span>
      </div>
    </template>
  </div>
</div>

<!-- Seznam souborů -->
<div class="card">
  <div class="card-head">
    <span class="card-title">Importované soubory</span>
    <span class="badge" x-text="files.length + ' souborů'"></span>
    <button class="btn sm" @click="load()">↻ Obnovit</button>
  </div>

  <div class="empty-state" x-show="files.length===0">
    Žádné soubory. Nahrajte první <code>REP_DATA_*.xlsx</code> výše.
  </div>

  <div class="file-list">
    <template x-for="f in files" :key="f.filename">
      <div class="file-row">
        <span class="file-ico">📄</span>
        <div class="file-info">
          <div class="file-name" x-text="f.filename"></div>
          <div class="file-meta">
            <span x-text="(f.period_from||'').slice(0,10)"></span> –
            <span x-text="(f.period_to||'').slice(0,10)"></span>
            &nbsp;·&nbsp; <span x-text="f.records"></span> záznamů
            &nbsp;·&nbsp; importováno <span x-text="(f.imported_at||'').slice(0,16).replace('T',' ')"></span>
          </div>
        </div>
        <button class="btn danger sm" @click="del(f.filename)">✕</button>
      </div>
    </template>
  </div>
</div>

</div>

<script>
function filesPage() {
  return {
    files: [], queue: [], dragging: false,

    async load() {
      this.files = await fetch('/api.php?action=files').then(r=>r.json());
    },

    onDrop(e) {
      this.dragging = false;
      this.upload(e.dataTransfer.files);
    },

    async upload(fileList) {
      for (const file of fileList) {
        const item = { name: file.name, status: 'uploading', msg: 'Nahrávám...' };
        this.queue.push(item);
        try {
          const fd = new FormData();
          fd.append('file', file);
          const r = await fetch('/api.php?action=upload', {method:'POST',body:fd}).then(x=>x.json());
          if (r.ok) {
            item.status = 'done';
            item.msg    = `✓ ${r.records} záznamů · ${r.from?.slice(0,10)} – ${r.to?.slice(0,10)}`;
          } else {
            item.status = 'error'; item.msg = '✗ ' + (r.error||'Chyba');
          }
        } catch(e) {
          item.status = 'error'; item.msg = '✗ ' + e.message;
        }
      }
      await this.load();
      setTimeout(() => this.queue = [], 4000);
    },

    async del(fn) {
      if (!confirm(`Smazat data ze souboru:\n${fn}?`)) return;
      await fetch(`/api.php?action=delete_file&filename=${encodeURIComponent(fn)}`);
      window._app?.toast('Smazáno: ' + fn, 'ok');
      await this.load();
    },
  };
}
</script>
