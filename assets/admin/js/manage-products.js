(function(){
  const rows = Array.from(document.querySelectorAll('#productRows tr'));
  const buttons = Array.from(document.querySelectorAll('.mp-stat'));
  const search = document.getElementById('productSearch');
  const empty = document.getElementById('emptyProducts');
  const count = document.getElementById('visibleCount');
  let filter = 'all';
  function apply(){
    const q = (search && search.value ? search.value : '').toLowerCase().trim();
    let shown = 0;
    rows.forEach(row => {
      const statusOk = filter === 'all' || (filter === 'hidden' ? row.dataset.hidden === '1' : row.dataset.status === filter);
      const searchOk = !q || row.dataset.search.includes(q);
      const ok = statusOk && searchOk;
      row.style.display = ok ? '' : 'none';
      if (ok) shown++;
    });
    if (empty) empty.style.display = shown ? 'none' : 'block';
    if (count) count.textContent = shown + ' showing';
  }
  buttons.forEach(btn => btn.addEventListener('click', () => {
    filter = btn.dataset.filter || 'all';
    buttons.forEach(b => b.classList.toggle('is-active', b === btn));
    apply();
  }));
  if (search) search.addEventListener('input', apply);
  apply();
})();
