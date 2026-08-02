(function () {
  const input = document.getElementById('transactionSearch');
  const select = document.getElementById('transactionStatus');
  const cards = Array.from(document.querySelectorAll('.txn-card'));
  const statButtons = Array.from(document.querySelectorAll('[data-status-filter]'));

  function setActiveFilter(value) {
    statButtons.forEach(button => {
      button.classList.toggle('is-active', button.dataset.statusFilter === value);
    });
  }

  function applyFilters() {
    const query = (input?.value || '').trim().toLowerCase();
    const status = select?.value || 'ALL';

    cards.forEach(card => {
      const textMatch = card.innerText.toLowerCase().includes(query);
      const statusMatch = status === 'ALL' || card.dataset.status === status;
      card.style.display = textMatch && statusMatch ? '' : 'none';
    });
  }

  input?.addEventListener('input', applyFilters);
  select?.addEventListener('change', function () {
    setActiveFilter(select.value || 'ALL');
    applyFilters();
  });

  statButtons.forEach(button => {
    button.addEventListener('click', function () {
      const value = button.dataset.statusFilter || 'ALL';
      if (select) select.value = value;
      setActiveFilter(value);
      applyFilters();
    });
  });
})();
