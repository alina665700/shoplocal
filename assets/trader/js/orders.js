(function(){
  const input = document.getElementById('orderSearch');
  const global = document.getElementById('globalSearch');
  const cards = () => document.querySelectorAll('.order-group');
  let activeFilter = 'all';
  function filterCards(){
    const q = (input?.value || global?.value || '').toLowerCase().trim();
    cards().forEach(card => {
      const byText = !q || card.dataset.search.includes(q);
      const statuses = (card.dataset.status || '').split(/\s+/);
      const byStatus = activeFilter === 'all' || statuses.includes(activeFilter);
      card.style.display = byText && byStatus ? '' : 'none';
    });
  }
  input?.addEventListener('input', filterCards);
  global?.addEventListener('input', function(){ if(input) input.value = this.value; filterCards(); });
  document.querySelectorAll('.ftab').forEach(tab => tab.addEventListener('click', function(){
    document.querySelectorAll('.ftab').forEach(t => t.classList.remove('active'));
    this.classList.add('active'); activeFilter = this.dataset.filter; filterCards();
  }));
})();
