(function(){
  const filter = document.getElementById('statusFilter');
  const global = document.getElementById('globalSearch');
  function apply(){
    const f = filter?.value || 'all';
    const q = (global?.value || '').toLowerCase().trim();
    document.querySelectorAll('#stockTableBody tr[data-status]').forEach(row => {
      const showStatus = f === 'all' || row.dataset.status === f;
      const showText = !q || row.dataset.search.includes(q);
      row.style.display = showStatus && showText ? '' : 'none';
    });
  }
  filter?.addEventListener('change', apply);
  global?.addEventListener('input', apply);
})();
