(function(){
  const ROWS_PER_PAGE = 5; let currentPage = 1; let filteredRows = [];
  const tbody = document.getElementById('custTableBody');
  const rows = () => Array.from(tbody.querySelectorAll('tr[data-search]'));
  const empty = document.getElementById('custEmpty');
  const pagInfo = document.getElementById('pagInfo');
  const pagBtns = document.getElementById('pagBtns');
  const input = document.getElementById('custSearch');
  const global = document.getElementById('globalSearch');
  function makeBtn(label, disabled){ const b=document.createElement('button'); b.className='pag-btn'; b.textContent=label; b.disabled=disabled; return b; }
  function renderPage(page){
    rows().forEach(r => r.style.display = 'none');
    const start = (page - 1) * ROWS_PER_PAGE; const end = start + ROWS_PER_PAGE;
    filteredRows.slice(start, end).forEach(r => r.style.display = '');
    if (empty) empty.style.display = filteredRows.length === 0 && rows().length > 0 ? '' : 'none';
    if (pagInfo) { const total=filteredRows.length; const s=total===0?0:start+1; const e=Math.min(end,total); pagInfo.textContent=`Showing ${s}–${e} of ${total} customers`; }
  }
  function updatePagination(){
    if (!pagBtns) return; pagBtns.innerHTML=''; const totalPages = Math.ceil(filteredRows.length / ROWS_PER_PAGE) || 1;
    const prev = makeBtn('← Prev', currentPage === 1); prev.onclick = () => { if(currentPage>1){ currentPage--; renderPage(currentPage); updatePagination(); } }; pagBtns.appendChild(prev);
    for(let i=1;i<=totalPages;i++){ const b=makeBtn(String(i), false); if(i===currentPage)b.classList.add('active'); b.onclick=()=>{currentPage=i;renderPage(currentPage);updatePagination();}; pagBtns.appendChild(b); }
    const next = makeBtn('Next →', currentPage === totalPages); next.onclick = () => { if(currentPage<totalPages){ currentPage++; renderPage(currentPage); updatePagination(); } }; pagBtns.appendChild(next);
  }
  function applySearch(q){ q=(q||'').toLowerCase().trim(); filteredRows = rows().filter(r => !q || r.dataset.search.includes(q)); currentPage = 1; renderPage(currentPage); updatePagination(); }
  input?.addEventListener('input', function(){ applySearch(this.value); });
  global?.addEventListener('input', function(){ if(input) input.value=this.value; applySearch(this.value); });
  filteredRows = rows(); renderPage(1); updatePagination();
})();
