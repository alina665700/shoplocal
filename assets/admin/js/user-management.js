(function () {
  const searchInput = document.getElementById('userSearch');
  const roleFilter = document.getElementById('roleFilter');
  const statusFilter = document.getElementById('statusFilter');
  const rows = Array.from(document.querySelectorAll('#userTable tbody tr:not(.empty-row)'));
  const cards = Array.from(document.querySelectorAll('[data-role-card]'));

  function applyFilters() {
    const q = (searchInput?.value || '').trim().toLowerCase();
    const role = roleFilter?.value || 'ALL';
    const status = statusFilter?.value || 'ALL';

    rows.forEach(row => {
      const textMatch = row.innerText.toLowerCase().includes(q);
      const roleMatch = role === 'ALL' || row.dataset.role === role;
      const statusMatch = status === 'ALL' || row.dataset.status === status;
      row.style.display = textMatch && roleMatch && statusMatch ? '' : 'none';
    });
  }

  searchInput?.addEventListener('input', applyFilters);
  roleFilter?.addEventListener('change', function () {
    cards.forEach(card => card.classList.toggle('is-active', card.dataset.roleCard === roleFilter.value));
    applyFilters();
  });
  statusFilter?.addEventListener('change', applyFilters);

  cards.forEach(card => {
    card.addEventListener('click', function () {
      const role = card.dataset.roleCard || 'ALL';
      roleFilter.value = role;
      cards.forEach(item => item.classList.toggle('is-active', item === card));
      applyFilters();
    });
  });
})();
