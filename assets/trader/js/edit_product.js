document.addEventListener('DOMContentLoaded', function(){
  const input = document.getElementById('productImage');
  const preview = document.getElementById('imagePreview');
  if (!input || !preview) return;
  input.addEventListener('change', function(){
    const file = this.files && this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(e){ preview.innerHTML = '<img src="' + String(e.target.result).replace(/"/g, '&quot;') + '" alt="Preview">'; };
    reader.readAsDataURL(file);
  });
});
