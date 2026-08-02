document.querySelector('#globalSearch')?.addEventListener('input', function(){
  const q = this.value.toLowerCase();
  document.querySelectorAll('.otable tbody tr, .prod-item').forEach(el => {
    el.style.display = el.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
});
