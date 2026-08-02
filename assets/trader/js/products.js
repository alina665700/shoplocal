function byId(id){ return document.getElementById(id); }
function updateCount(n){ const el = byId('productCount'); if(el) el.textContent = n + ' product' + (n === 1 ? '' : 's') + ' found'; }
function filterProducts(){ const q=(byId('prodSearch')?.value||'').toLowerCase().trim(); let shown=0; document.querySelectorAll('#prodTableBody tr').forEach(row=>{ const ok=!q || row.dataset.search.includes(q); row.style.display=ok?'':'none'; if(ok) shown++; }); updateCount(shown); const empty=byId('emptyState'); if(empty) empty.style.display=shown?'none':'block'; }
document.addEventListener('DOMContentLoaded',()=>{
  byId('prodSearch')?.addEventListener('input', filterProducts);
  byId('globalSearch')?.addEventListener('input', function(){ const p=byId('prodSearch'); if(p){ p.value=this.value; filterProducts(); } });
});
