// content.js (hoặc script gắn trong popup.html)
(function () {
    const modalId = 'cj-modal';
  
    // ==== Modal Bootstrap ====
    const modalHTML = `
    <div class="modal fade" id="${modalId}" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-md-down modal-xl">
        <div class="modal-content shadow-lg border-0">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title">
              <i class="bi bi-book me-2"></i><span id="cj-title">Từ vựng</span>
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Đóng"></button>
          </div>
          <div class="modal-body p-2 p-md-3">
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
      }
    }
  
    // ==== Custom CSS cho bảng đẹp ====
    function addCustomStyles() {
      if (document.getElementById('cj-custom-styles')) return;
      
      const style = document.createElement('style');
      style.id = 'cj-custom-styles';
      style.textContent = `
        .cj-table-wrapper {
          background: #fff;
          border-radius: 8px;
          box-shadow: 0 2px 8px rgba(0,0,0,0.1);
          overflow: hidden;
          margin-bottom: 1rem;
        }
        
        .cj-table {
          width: 100%;
          margin: 0;
          border-collapse: collapse;
          font-size: 14px;
        }
        
        .cj-mobile-table {
          font-size: 16px !important;
        }
        
        .cj-table th {
          background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
          font-weight: 600;
          padding: 12px 8px;
          text-align: center;
          border: 1px solid #dee2e6;
          color: #495057;
          font-size: 13px;
        }
        
        .cj-mobile-table th {
          font-size: 15px !important;
          padding: 14px 10px !important;
        }
        
        .cj-table td {
          padding: 10px 8px;
          text-align: center;
          border: 1px solid #dee2e6;
          vertical-align: middle;
          background: #fff;
          transition: background-color 0.2s;
        }
        
        .cj-mobile-table td {
          padding: 14px 10px !important;
          font-size: 16px !important;
        }
        
        .cj-table tr:nth-child(even) td {
          background: #f8f9fa;
        }
        
        .cj-table tr:hover td {
          background: #e3f2fd !important;
        }
        
        .cj-table .vStm {
          font-weight: 600;
          color: #1976d2;
        }
        
        .cj-responsive-grid {
          display: grid;
          grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
          gap: 1rem;
          width: 100%;
        }
        
        @media (max-width: 768px) {
          .modal-xl {
            margin: 0.5rem;
          }
          
          .cj-responsive-grid {
            grid-template-columns: 1fr;
          }
          
          .card-header {
            padding: 1rem !important;
          }
          
          .card-header .fw-semibold {
            font-size: 16px;
          }
          
          .btn-sm {
            padding: 0.5rem 1rem !important;
            font-size: 14px !important;
          }
        }
        
        @media (max-width: 576px) {
          .modal-dialog {
            margin: 0;
            height: 100vh;
          }
          
          .cj-section-title {
            font-size: 16px !important;
            padding: 12px 15px !important;
          }
          
          .card-header .fw-semibold {
            font-size: 15px !important;
          }
          
          .nav-tabs .nav-link {
            font-size: 14px !important;
            padding: 12px 16px !important;
          }
          
          .tab-content h6 {
            font-size: 16px !important;
          }
        }
        
        .cj-section-title {
          font-size: 14px;
          font-weight: 600;
          color: #495057;
          margin-bottom: 8px;
          padding: 8px 12px;
          background: #f1f3f4;
          border-radius: 4px;
          border-left: 4px solid #1976d2;
        }
      `;
      document.head.appendChild(style);
    }
  
    // ==== Fetch HTML từ proxy ====
    async function fetchVerbformen(word) {
      const url = `/api/proxy.php?word=${encodeURIComponent(word)}`;
      const res = await fetch(url, { cache: 'no-store' });
      const text = await res.text();
      if (!res.ok) {
        throw new Error(`Proxy HTTP ${res.status}: ${text.slice(0, 200)}`);
      }
      const parser = new DOMParser();
      const doc = parser.parseFromString(text, "text/html");
      return { doc, raw: text };
    }
  
    // ==== Cải thiện bảng ====
    function improveTable(table) {
      // Thêm class CSS
      table.className = 'cj-table';
      
      // Xóa images không cần thiết
      table.querySelectorAll('img').forEach(img => {
        if (img.src.includes('s.svg')) img.remove();
      });
      
      // Cải thiện header
      table.querySelectorAll('th').forEach(th => {
        th.style.whiteSpace = 'nowrap';
      });
      
      // Làm sạch nội dung
      table.querySelectorAll('td, th').forEach(cell => {
        cell.innerHTML = cell.innerHTML.replace(/\s+/g, ' ').trim();
      });
      
      return table;
    }
  
    // ==== Tạo layout responsive cho nhiều bảng ====
    function createResponsiveLayout(tables, title) {
      const wrapper = document.createElement('div');
      wrapper.className = 'mb-4';
      
      if (title) {
        const titleEl = document.createElement('div');
        titleEl.className = 'cj-section-title';
        titleEl.textContent = title;
        wrapper.appendChild(titleEl);
      }
      
      if (tables.length === 1) {
        // Một bảng - hiển thị full width
        const tableWrapper = document.createElement('div');
        tableWrapper.className = 'cj-table-wrapper';
        tableWrapper.appendChild(tables[0]);
        wrapper.appendChild(tableWrapper);
      } else if (tables.length <= 4) {
        // 2-4 bảng - dùng grid responsive
        const grid = document.createElement('div');
        grid.className = 'cj-responsive-grid';
        
        tables.forEach(table => {
          const tableWrapper = document.createElement('div');
          tableWrapper.className = 'cj-table-wrapper';
          tableWrapper.appendChild(table);
          grid.appendChild(tableWrapper);
        });
        
        wrapper.appendChild(grid);
      } else {
        // Nhiều bảng - nhóm thành từng cặp
        for (let i = 0; i < tables.length; i += 2) {
          const grid = document.createElement('div');
          grid.className = 'cj-responsive-grid';
          grid.style.marginBottom = '1rem';
          
          const table1 = tables[i];
          const wrapper1 = document.createElement('div');
          wrapper1.className = 'cj-table-wrapper';
          wrapper1.appendChild(table1);
          grid.appendChild(wrapper1);
          
          if (tables[i + 1]) {
            const table2 = tables[i + 1];
            const wrapper2 = document.createElement('div');
            wrapper2.className = 'cj-table-wrapper';
            wrapper2.appendChild(table2);
            grid.appendChild(wrapper2);
          }
          
          wrapper.appendChild(grid);
        }
      }
      
      return wrapper;
    }
  
    // ==== Render UI từ HTML lấy được ====
    function buildHtmlFromDoc(doc, word) {
      addCustomStyles(); // Thêm CSS
      
      const container = document.createElement("div");
      const uniqueId = Math.random().toString(36).substring(2, 8);
  
      // Header
      const header = document.createElement("div");
      header.className = "d-flex align-items-center mb-4 p-3 bg-light rounded";
      header.innerHTML = `
        <h4 class="mb-0 fw-bold flex-grow-1 text-primary">
          <i class="bi bi-translate me-2"></i>${word}
        </h4>
        <a href="https://www.verbformen.com/?w=${encodeURIComponent(word)}" 
           target="_blank" class="btn btn-sm btn-outline-primary">
           <i class="bi bi-box-arrow-up-right me-1"></i>Trang gốc
        </a>
      `;
      container.appendChild(header);
  
      const sections = doc.querySelectorAll('section.rBox.rBoxWht');
  
      // ==== Declension (Danh từ) ====
      let declSec = null;
      for (const sec of sections) {
        if (sec.querySelector('.vDkl')) { declSec = sec; break; }
      }
      if (declSec) {
        const tables = declSec.querySelectorAll('.vDkl');
        if (tables.length > 0) {
          const improvedTables = Array.from(tables).map(t => improveTable(t.cloneNode(true)));
          const layout = createResponsiveLayout(improvedTables, '📘 Biến cách (Danh từ)');
          
          const declId = `decl-${uniqueId}`;
          const card = document.createElement("div");
          card.className = "card mb-4 border-0 shadow";
          card.innerHTML = `
            <div class="card-header bg-gradient bg-primary text-white d-flex justify-content-between align-items-center">
              <span class="fw-semibold"><i class="bi bi-table me-2"></i>Biến cách (Danh từ)</span>
              <button class="btn btn-sm btn-light toggle-btn" data-target="#${declId}">
                <i class="bi bi-eye me-1"></i>Hiện
              </button>
            </div>
            <div id="${declId}" class="collapse">
              <div class="card-body p-3"></div>
            </div>
          `;
          
          card.querySelector(`#${declId} .card-body`).appendChild(layout);
          container.appendChild(card);
        }
      }
  
      // ==== Conjugation (Động từ) ====
      let verbSec = null, maxTbl = 0;
      for (const sec of sections) {
        const t = sec.querySelectorAll('.vTbl');
        if (t.length > maxTbl) { maxTbl = t.length; verbSec = sec; }
      }
      if (verbSec && maxTbl >= 2) {
        const tables = verbSec.querySelectorAll('.vTbl');
        const improvedTables = Array.from(tables).map(t => improveTable(t.cloneNode(true)));
        const layout = createResponsiveLayout(improvedTables, '⚡ Chia động từ');
        
        const verbId = `verb-${uniqueId}`;
        const card = document.createElement("div");
        card.className = "card mb-4 border-0 shadow";
        card.innerHTML = `
          <div class="card-header bg-gradient bg-success text-white d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-lightning-charge me-2"></i>Chia động từ</span>
            <button class="btn btn-sm btn-light toggle-btn" data-target="#${verbId}">
              <i class="bi bi-eye me-1"></i>Hiện
            </button>
          </div>
          <div id="${verbId}" class="collapse">
            <div class="card-body p-3"></div>
          </div>
        `;
        
        card.querySelector(`#${verbId} .card-body`).appendChild(layout);
        container.appendChild(card);
      }
  
      // ==== Fallback khi không có bảng ====
      if (!declSec && !verbSec) {
        const warn = document.createElement("div");
        warn.className = "alert alert-warning border-0 shadow-sm";
        warn.innerHTML = `
          <i class="bi bi-exclamation-triangle me-2"></i>
          Không tìm thấy bảng biến cách/chia động từ trong trang nguồn.
        `;
        container.appendChild(warn);
      }
  
      // ==== Gắn toggle với Bootstrap Collapse ====
      container.querySelectorAll(".toggle-btn").forEach(btn => {
        const targetId = btn.getAttribute("data-target");
        const target = container.querySelector(targetId);
        
        btn.addEventListener("click", (e) => {
          e.preventDefault();
          const isCollapsed = target.classList.contains('show');
          
          if (isCollapsed) {
            target.classList.remove('show');
            target.style.display = 'none';
            btn.innerHTML = '<i class="bi bi-eye me-1"></i>Hiện';
          } else {
            target.style.display = 'block';
            target.classList.add('show');
            btn.innerHTML = '<i class="bi bi-eye-slash me-1"></i>Ẩn';
          }
        });
      });
  
      return container;
    }
  
    // ==== Lắng nghe click ====
    document.addEventListener('click', async (ev) => {
      const t = ev.target.closest('[data-conjugation-word]');
      if (!t) return;
      ev.preventDefault();
      const word = t.getAttribute('data-conjugation-word');
      ensureModal();
      const modalEl = document.getElementById(modalId);
      const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
  
      document.getElementById('cj-title').textContent = `Từ vựng: ${word}`;
      document.getElementById('cj-loading').style.display = 'block';
      document.getElementById('cj-result').innerHTML = '';
      bsModal.show();
  
      try {
        const { doc } = await fetchVerbformen(word);
        const node = buildHtmlFromDoc(doc, word);
        document.getElementById('cj-loading').style.display = 'none';
        const resultEl = document.getElementById('cj-result');
        resultEl.innerHTML = '';
        resultEl.appendChild(node);
      } catch (e) {
        console.error('Verbformen error:', e);
        document.getElementById('cj-loading').style.display = 'none';
        document.getElementById('cj-result').innerHTML = `
          <div class="alert alert-danger border-0 shadow-sm">
            <i class="bi bi-exclamation-circle me-2"></i>
            <strong>Không thể tải dữ liệu.</strong><br>
            <small class="text-muted">${(e && e.message) ? e.message : String(e)}</small>
          </div>`;
      }
    });
})();