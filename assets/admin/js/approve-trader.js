(function () {
  const searchInput = document.getElementById('traderSearch');
  const statusFilter = document.getElementById('statusFilter');
  const rows = Array.from(document.querySelectorAll('#traderTable tbody tr:not(.empty-row)'));
  const cards = Array.from(document.querySelectorAll('[data-status-card]'));

  function applyFilters() {
    const q = (searchInput?.value || '').trim().toLowerCase();
    const status = statusFilter?.value || 'ALL';

    rows.forEach(row => {
      const textMatch = row.innerText.toLowerCase().includes(q);
      const statusMatch = status === 'ALL' || row.dataset.status === status;
      row.style.display = textMatch && statusMatch ? '' : 'none';
    });
  }

  searchInput?.addEventListener('input', applyFilters);
  statusFilter?.addEventListener('change', function () {
    cards.forEach(card => card.classList.toggle('is-active', card.dataset.statusCard === statusFilter.value));
    applyFilters();
  });

  cards.forEach(card => {
    card.addEventListener('click', function () {
      const status = card.dataset.statusCard || 'ALL';
      statusFilter.value = status;
      cards.forEach(item => item.classList.toggle('is-active', item === card));
      applyFilters();
    });
  });
})();
