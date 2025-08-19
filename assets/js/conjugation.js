// content.js
(function () {
  const modalId = 'cj-modal';
  const STORAGE_NS = 'cj-';
  const API_JSON = '/api/conjugation.php';
  const API_PROXY = '/api/proxy.php';

  // ===== Modal Bootstrap =====
  const modalHTML = `
  <div class="modal fade" id="${modalId}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-md-down modal-xl">
      <div class="modal-content shadow-lg border-0">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title d-flex align-items-center">
            <i class="bi bi-translate me-2"></i>
            <span id="cj-title">Từ vựng</span>
          </h5>
          <div class="d-flex gap-2 me-2">
            <button class="btn btn-sm btn-light" id="cj-copy-csv" title="Sao chép CSV 6 ngôi">
              <i class="bi bi-clipboard-check"></i>
            </button>
            <button class="btn btn-sm btn-light" id="cj-open-source" title="Mở trang gốc">
              <i class="bi bi-box-arrow-up-right"></i>
            </button>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Đóng"></button>
        </div>
        <div class="modal-body p-0">
          <div class="px-3 pt-3">
            <ul class="nav nav-tabs" id="cj-tabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-verb" data-bs-toggle="tab" data-bs-target="#pane-verb" type="button" role="tab">
                  Động từ
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-overview" data-bs-toggle="tab" data-bs-target="#pane-overview" type="button" role="tab">
                  Tổng quan
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-noun" data-bs-toggle="tab" data-bs-target="#pane-noun" type="button" role="tab">
                  Danh/Tính từ
                </button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-tools" data-bs-toggle="tab" data-bs-target="#pane-tools" type="button" role="tab">
                  Công cụ
                </button>
              </li>
            </ul>
          </div>

          <div class="tab-content p-3">
            <div class="tab-pane fade show active" id="pane-overview" role="tabpanel">
              <div id="cj-overview"></div>
            </div>
            <div class="tab-pane fade" id="pane-verb" role="tabpanel">
              <div id="cj-verb"></div>
            </div>
            <div class="tab-pane fade" id="pane-noun" role="tabpanel">
              <div id="cj-noun"></div>
            </div>
            <div class="tab-pane fade" id="pane-tools" role="tabpanel">
              <div id="cj-tools"></div>
            </div>
          </div>

          <div id="cj-loading" class="text-center py-4">
            <div class="spinner-border text-primary" role="status"></div>
            <div class="mt-2">Đang tải...</div>
          </div>
          <div id="cj-result"></div>
        </div>
      </div>
    </div>
  </div>`;

  function ensureModal() {
    if (!document.getElementById(modalId)) {
      document.body.insertAdjacentHTML('beforeend', modalHTML);
      addCustomStyles();
    }
  }

  // ===== Styles =====
  function addCustomStyles() {
    if (document.getElementById('cj-custom-styles')) return;

    const style = document.createElement('style');
    style.id = 'cj-custom-styles';
    style.textContent = `
      .cj-table-wrapper {
        background: var(--bs-body-bg,#fff);
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0,0,0,.08);
        overflow: hidden;
        margin-bottom: 1rem;
      }
      .cj-table { width:100%; border-collapse: collapse; font-size:14px; }
      .cj-table th, .cj-table td { border: 1px solid #dee2e6; padding: 10px 8px; text-align:center; vertical-align: middle; }
      .cj-table th { background: linear-gradient(135deg, #f8f9fa, #e9ecef); font-weight:600; font-size:13px; color:#495057; }
      .cj-table tr:nth-child(even) td { background:#f8f9fa; }
      .cj-table tr:hover td { background:#e7f1ff; }
      .cj-badge { display:inline-block; padding:.25rem .5rem; border-radius:.5rem; background:#f1f3f5; color:#495057; margin-right:.5rem; }
      .cj-toolbar { display:flex; gap:.5rem; flex-wrap:wrap; }
      .cj-section-title { font-weight:600; margin-bottom:.5rem; padding:.5rem .75rem; border-left:4px solid #1976d2; background:#f6f8fa; border-radius:4px; }
      .cj-grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(300px,1fr)); gap:1rem; }
      @media (max-width: 576px) {
        .cj-table { font-size:16px }
        .cj-table th { font-size:15px }
        .modal-dialog { margin:0; height:100vh; }
      }
      /* Dark mode friendly */
      @media (prefers-color-scheme: dark) {
        .cj-table th { background: #2b2f36; color:#dfe4ea; border-color:#444 }
        .cj-table td { background:#1f2328; color:#e6edf3; border-color:#333 }
        .cj-table tr:nth-child(even) td { background:#20252b; }
        .cj-table tr:hover td { background:#2a3440; }
        .cj-section-title { background:#222730; color:#dfe4ea; border-left-color:#4ea1ff; }
        .cj-table-wrapper { background:#0d1117; box-shadow: 0 2px 10px rgba(0,0,0,.4); }
      }
    `;
    document.head.appendChild(style);
  }

  // ===== Utilities =====
  function htmlToEl(html) {
    const t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstElementChild;
  }
  function copyToClipboard(text) {
    return navigator.clipboard?.writeText(text);
  }
  function toCSV(rows) {
    return rows.map(r => r.map(v => `"${(v??'').toString().replace(/"/g,'""')}"`).join(',')).join('\n');
  }
  function byId(id){ return document.getElementById(id); }

  // ===== Fetch JSON API =====
  async function fetchJSON(word) {
    const url = `${API_JSON}?word=${encodeURIComponent(word)}`;
    const res = await fetch(url, { cache: 'no-store' });
    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(`API JSON error`);
    return data;
  }
  // Optional: fetch full HTML proxy
  async function fetchProxy(word) {
    const url = `${API_PROXY}?word=${encodeURIComponent(word)}`;
    const res = await fetch(url, { cache: 'no-store' });
    if (!res.ok) throw new Error(`Proxy HTTP ${res.status}`);
    const text = await res.text();
    const parser = new DOMParser();
    return { raw:text, doc: parser.parseFromString(text,'text/html') };
  }

  // ===== Build “6 ngôi” (from JSON) =====
  function buildSixPersonsTable(simple, title) {
    const order = ['ich','du','er','wir','ihr','sie'];
    const orderLabel = {ich:'ich',du:'du',er:'er/sie/es',wir:'wir',ihr:'ihr',sie:'sie/Sie'};
    const groups = [
      ['present','Präsens (hiện tại)'],
      ['imperfect','Präteritum (quá khứ đơn)'],
      ['subjunctive_present','Konjunktiv I (giả định hiện tại)'],
      ['subjunctive_imperfect','Konjunktiv II (giả định quá khứ)'],
      ['imperative','Imperativ (mệnh lệnh)'],
    ].filter(([k]) => simple && simple[k]);

    const grid = document.createElement('div');
    grid.className = 'cj-grid';

    for (const [key, caption] of groups) {
      const t = document.createElement('table');
      t.className = 'cj-table';
      t.innerHTML = `
        <thead><tr><th colspan="2">${caption}</th></tr></thead>
        <tbody></tbody>`;
      const tb = t.querySelector('tbody');
      order.forEach(p => {
        const form = simple[key][p] ?? '';
        const tr = document.createElement('tr');
        tr.innerHTML = `<td style="white-space:nowrap;font-weight:600">${orderLabel[p]}</td><td>${form||'<span class="text-muted">—</span>'}</td>`;
        tb.appendChild(tr);
      });

      const wrap = document.createElement('div');
      wrap.className = 'cj-table-wrapper';
      wrap.appendChild(t);
      grid.appendChild(wrap);
    }

    const card = htmlToEl(`
      <div class="card mb-4 border-0 shadow">
        <div class="card-header bg-gradient bg-success text-white d-flex justify-content-between align-items-center">
          <span class="fw-semibold"><i class="bi bi-lightning-charge me-2"></i>${title||'Chia động từ (6 ngôi)'} </span>
          <div class="cj-toolbar">
            <button class="btn btn-sm btn-light" data-action="copy-forms"><i class="bi bi-clipboard"></i> Copy</button>
            <button class="btn btn-sm btn-light" data-action="download-csv"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</button>
          </div>
        </div>
        <div class="card-body p-3"></div>
      </div>`);
    card.querySelector('.card-body').appendChild(grid);

    // Actions
    card.querySelector('[data-action="copy-forms"]').addEventListener('click', () => {
      const rows = [['Tense','Pronoun','Form']];
      for (const [key] of groups) {
        order.forEach(p => rows.push([key, p, simple[key][p] ?? '']));
      }
      copyToClipboard(toCSV(rows));
    });
    card.querySelector('[data-action="download-csv"]').addEventListener('click', () => {
      const rows = [['Tense','Pronoun','Form']];
      for (const [key] of groups) {
        order.forEach(p => rows.push([key, p, simple[key][p] ?? '']));
      }
      const blob = new Blob([toCSV(rows)], {type:'text/csv;charset=utf-8;'});
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'conjugation.csv';
      a.click();
      URL.revokeObjectURL(a.href);
    });

    return card;
  }

  // ===== Render raw “simple section” HTML (đã beautify) =====
  function beautifyRawSection(rawHTML) {
    if (!rawHTML) return null;
    const container = document.createElement('div');
    container.innerHTML = rawHTML;

    // Xử lý bảng cho đẹp hơn
    container.querySelectorAll('table').forEach(table => {
      table.className = 'cj-table';
      table.querySelectorAll('img').forEach(img => {
        if (/(s\.svg|audio|flag)/i.test(img.src||'')) img.remove();
      });
      table.querySelectorAll('td,th').forEach(cell => {
        cell.innerHTML = cell.innerHTML.replace(/\s+/g,' ').trim();
      });
    });

    // Bọc grid
    const tables = Array.from(container.querySelectorAll('table'));
    if (tables.length===0) return null;

    const grid = document.createElement('div');
    grid.className='cj-grid';
    tables.forEach(t=>{
      const w = document.createElement('div');
      w.className='cj-table-wrapper';
      w.appendChild(t);
      grid.appendChild(w);
    });

    const card = htmlToEl(`
      <div class="card mb-4 border-0 shadow">
        <div class="card-header bg-gradient bg-primary text-white d-flex justify-content-between align-items-center">
          <span class="fw-semibold"><i class="bi bi-table me-2"></i>Simple conjugated verbs (đầy đủ bảng)</span>
          <button class="btn btn-sm btn-light" data-action="toggle"><i class="bi bi-eye-slash"></i> Ẩn/Hiện</button>
        </div>
        <div class="card-body p-3"></div>
      </div>`);
    const body = card.querySelector('.card-body');
    body.appendChild(grid);

    card.querySelector('[data-action="toggle"]').addEventListener('click', ()=>{
      body.style.display = (body.style.display==='none' ? 'block' : 'none');
    });

    return card;
  }

  // ===== Render declension/others from full page (nếu cần) =====
  function buildFromFullDoc(doc, word) {
    const container = document.createElement('div');

    // Declension section
    const sections = doc.querySelectorAll('section.rBox.rBoxWht');
    let declSec = null;
    for (const sec of sections) {
      if (sec.querySelector('.vDkl')) { declSec = sec; break; }
    }
    if (declSec) {
      const tables = declSec.querySelectorAll('.vDkl');
      if (tables.length>0) {
        const grid = document.createElement('div');
        grid.className='cj-grid';
        Array.from(tables).forEach(t=>{
          const tbl=t.cloneNode(true);
          tbl.className='cj-table';
          tbl.querySelectorAll('img').forEach(img => { if (/(s\.svg|audio|flag)/i.test(img.src||'')) img.remove(); });
          tbl.querySelectorAll('td,th').forEach(cell => cell.innerHTML = cell.innerHTML.replace(/\s+/g,' ').trim());
          const w=document.createElement('div'); w.className='cj-table-wrapper'; w.appendChild(tbl);
          grid.appendChild(w);
        });

        const card = htmlToEl(`
          <div class="card mb-4 border-0 shadow">
            <div class="card-header bg-gradient bg-info text-white d-flex justify-content-between align-items-center">
              <span class="fw-semibold"><i class="bi bi-table me-2"></i>Biến cách (Danh/Tính từ)</span>
              <button class="btn btn-sm btn-light" data-action="toggle"><i class="bi bi-eye-slash"></i> Ẩn/Hiện</button>
            </div>
            <div class="card-body p-3"></div>
          </div>`);
        card.querySelector('.card-body').appendChild(grid);
        card.querySelector('[data-action="toggle"]').addEventListener('click', ()=>{
          const b = card.querySelector('.card-body');
          b.style.display = (b.style.display==='none' ? 'block' : 'none');
        });

        container.appendChild(card);
      }
    } else {
      const warn = htmlToEl(`<div class="alert alert-warning border-0 shadow-sm">
        <i class="bi bi-exclamation-triangle me-2"></i>Không tìm thấy bảng biến cách trong trang gốc.
      </div>`);
      container.appendChild(warn);
    }
    return container;
  }

  // ===== Main render =====
  async function renderWord(word) {
    const loading = byId('cj-loading');
    const paneOverview = byId('cj-overview');
    const paneVerb = byId('cj-verb');
    const paneNoun = byId('cj-noun');
    const btnSource = byId('cj-open-source');
    const btnCopyCsv = byId('cj-copy-csv');

    // reset
    paneOverview.innerHTML = '';
    paneVerb.innerHTML = '';
    paneNoun.innerHTML = '';
    loading.style.display = 'block';

    try {
      const data = await fetchJSON(word);
      loading.style.display = 'none';

      // Header buttons
      btnSource.onclick = () => window.open(data.meta?.source || `https://www.verbformen.com/?w=${encodeURIComponent(word)}`, '_blank');
      btnCopyCsv.onclick = () => {
        const s = data.simple || {};
        const order = ['ich','du','er','wir','ihr','sie'];
        const rows = [['Tense','Pronoun','Form']];
        Object.keys(s).forEach(k => order.forEach(p => rows.push([k,p,s[k]?.[p] ?? ''])));
        copyToClipboard(toCSV(rows));
      };

      // Overview: badges
      const ov = htmlToEl(`
        <div class="cj-section-title"><i class="bi bi-info-circle me-2"></i>Thông tin</div>
      `);
      const badges = htmlToEl(`<div class="mb-3"></div>`);
      badges.appendChild(htmlToEl(`<span class="cj-badge">Từ: <strong>${word}</strong></span>`));
      if (data.meta?.source) badges.appendChild(htmlToEl(`<span class="cj-badge">Nguồn: Verbformen</span>`));
      if (data.meta?.fetched_at) badges.appendChild(htmlToEl(`<span class="cj-badge">Cập nhật: ${new Date(data.meta.fetched_at).toLocaleString()}</span>`));
      paneOverview.appendChild(ov);
      paneOverview.appendChild(badges);

      // Verb – Six-person tables
      if (data.simple) {
        paneVerb.appendChild(buildSixPersonsTable(data.simple, 'Chia động từ (6 ngôi)'));
      }
      // Verb – Raw section (đầy đủ bảng simple)
      const cardRaw = beautifyRawSection(data.raw_section_html);
      if (cardRaw) paneVerb.appendChild(cardRaw);

      // Noun/Adj – Tuỳ chọn: tải toàn trang để lấy bảng biến cách
      const nounTools = htmlToEl(`
        <div class="d-flex align-items-center gap-2 mb-3">
          <button class="btn btn-outline-primary btn-sm" id="cj-load-full">
            <i class="bi bi-cloud-download"></i> Tải toàn trang để hiển thị thêm bảng biến cách
          </button>
          <div class="text-muted small">Sử dụng khi cần hiển thị nhiều bảng khác từ trang gốc</div>
        </div>`);
      paneNoun.appendChild(nounTools);
      nounTools.querySelector('#cj-load-full').addEventListener('click', async () => {
        nounTools.querySelector('#cj-load-full').disabled = true;
        nounTools.querySelector('#cj-load-full').innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Đang tải...';
        try {
          const { doc } = await fetchProxy(word);
          const node = buildFromFullDoc(doc, word);
          paneNoun.appendChild(node);
        } catch (e) {
          paneNoun.appendChild(htmlToEl(`<div class="alert alert-danger mt-2">Không thể tải trang gốc.</div>`));
        } finally {
          nounTools.querySelector('#cj-load-full').disabled = false;
          nounTools.querySelector('#cj-load-full').innerHTML = '<i class="bi bi-cloud-download"></i> Tải toàn trang để hiển thị thêm bảng biến cách';
        }
      });

      // Tools – quick copy
      const tools = htmlToEl(`
        <div class="cj-section-title"><i class="bi bi-tools me-2"></i>Công cụ</div>
      `);
      const actions = htmlToEl(`
        <div class="cj-toolbar">
          <button class="btn btn-sm btn-outline-secondary" id="cj-copy-present"><i class="bi bi-clipboard"></i> Copy Präsens</button>
          <button class="btn btn-sm btn-outline-secondary" id="cj-copy-imperfect"><i class="bi bi-clipboard"></i> Copy Präteritum</button>
        </div>`);
      tools.appendChild(actions);
      byId('cj-tools').appendChild(tools);

      function copyTense(tense) {
        const s = data.simple?.[tense];
        if (!s) return;
        const order = ['ich','du','er','wir','ihr','sie'];
        const lines = order.map(p => `${p}\t${s[p] ?? ''}`);
        copyToClipboard(lines.join('\n'));
      }
      byId('cj-copy-present').onclick = () => copyTense('present');
      byId('cj-copy-imperfect').onclick = () => copyTense('imperfect');

    } catch (e) {
      console.error('Conjugation UI error:', e);
      loading.style.display = 'none';
      byId('cj-overview').innerHTML = `
        <div class="alert alert-danger border-0 shadow-sm">
          <i class="bi bi-exclamation-circle me-2"></i>
          <strong>Không thể tải dữ liệu.</strong><br>
          <small class="text-muted">${(e && e.message) ? e.message : String(e)}</small>
        </div>`;
    }
  }

  // ===== Event binding: open modal =====
  document.addEventListener('click', async (ev) => {
    const t = ev.target.closest('[data-conjugation-word]');
    if (!t) return;
    ev.preventDefault();
    const word = t.getAttribute('data-conjugation-word');
    ensureModal();
    const modalEl = document.getElementById(modalId);
    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);

    byId('cj-title').textContent = `Từ vựng: ${word}`;
    byId('cj-loading').style.display = 'block';
    byId('cj-overview').innerHTML = '';
    byId('cj-verb').innerHTML = '';
    byId('cj-noun').innerHTML = '';
    byId('cj-tools').innerHTML = '';
    bsModal.show();

    renderWord(word);
  });
})();
