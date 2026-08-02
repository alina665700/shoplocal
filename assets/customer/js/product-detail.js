/* Qty */
  let qty = 1;
  function changeQty(d) {
    qty = Math.max(1, qty + d);
    document.getElementById('qtyVal').textContent = qty;
    syncQtyInputs();
  }

  function syncQtyInputs() {
    document.querySelectorAll('.qtyInput').forEach(input => {
      input.value = qty;
    });
  }

  /* Thumbnail highlight (swap src if you have real variant images) */
  function selectThumb(el) {
    document.querySelectorAll('.thumb').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
  }

  /* Wishlist */
  let wished = false;
  function toggleWish() {
    wished = !wished;
    document.getElementById('wishBtn').classList.toggle('active', wished);
    showToast(wished ? 'Added to wishlist!' : 'Removed from wishlist');
  }

  /* Toast */
  function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2200);
  }

  /* Star picker */
  let pickedStar = 0;
  function pickStar(val) {
    pickedStar = val;
    const ratingInput = document.getElementById('reviewRating');
    if (ratingInput) {
      ratingInput.value = val > 0 ? String(val) : '';
    }

    document.querySelectorAll('#starPicker svg').forEach((s, i) => {
      const on = i < val;
      s.classList.toggle('lit', on);
      s.style.fill   = on ? '#f5a623' : '#ddd';
      s.style.stroke = on ? '#f5a623' : '#ccc';
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    const oldRating = parseInt(document.getElementById('reviewRating')?.value || '0', 10);
    if (oldRating > 0) {
      pickStar(oldRating);
    }
  });
