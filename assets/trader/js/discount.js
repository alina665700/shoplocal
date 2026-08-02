(function(){
  const form = document.getElementById('discountForm');
  const btnSave = document.getElementById('btnSave');
  const reset = document.getElementById('btnReset');
  const id = document.getElementById('discountId');
  const name = document.getElementById('discountName');
  const type = document.getElementById('discountType');
  const value = document.getElementById('discountValue');
  const product = document.getElementById('productId');
  const start = document.getElementById('startDate');
  const end = document.getElementById('endDate');
  reset?.addEventListener('click', function(){ form?.reset(); if(id) id.value=''; if(btnSave) btnSave.textContent='Save discount'; });
  document.querySelectorAll('.dc-act.edit').forEach(btn => btn.addEventListener('click', function(){
    if(id) id.value = this.dataset.id || '';
    if(name) name.value = this.dataset.name || '';
    if(type) type.value = (this.dataset.type || '%').toUpperCase().includes('FLAT') ? 'FLAT' : '%';
    if(value) value.value = this.dataset.value || '';
    if(product) product.value = this.dataset.product || '';
    if(start) start.value = this.dataset.start || '';
    if(end) end.value = this.dataset.end || '';
    if(btnSave) btnSave.textContent = 'Update discount';
    form?.scrollIntoView({behavior:'smooth', block:'start'});
  }));
})();
