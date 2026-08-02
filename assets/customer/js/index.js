(function () {
    const slides    = document.getElementById('slides');
    const dots      = document.querySelectorAll('.slider-dot');
    const prevBtn   = document.getElementById('sliderPrev');
    const nextBtn   = document.getElementById('sliderNext');

    if (!slides) return;

    const total  = dots.length || 0;
    let current  = 0;
    let timer;

    if (total <= 1) return;

    function goTo(index) {
      current = (index + total) % total;
      slides.style.transform = `translateX(-${current * 100}%)`;
      dots.forEach((d, i) => d.classList.toggle('active', i === current));
    }

    function startAuto() {
      timer = setInterval(() => goTo(current + 1), 4500);
    }

    function stopAuto() {
      clearInterval(timer);
    }

    if (prevBtn) {
      prevBtn.addEventListener('click', () => { stopAuto(); goTo(current - 1); startAuto(); });
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', () => { stopAuto(); goTo(current + 1); startAuto(); });
    }

    dots.forEach(dot => {
      dot.addEventListener('click', () => {
        stopAuto();
        goTo(parseInt(dot.dataset.index, 10));
        startAuto();
      });
    });

    let touchStartX = 0;

    slides.addEventListener('touchstart', e => {
      touchStartX = e.touches[0].clientX;
    }, { passive: true });

    slides.addEventListener('touchend', e => {
      const diff = touchStartX - e.changedTouches[0].clientX;
      if (Math.abs(diff) > 50) {
        stopAuto();
        goTo(diff > 0 ? current + 1 : current - 1);
        startAuto();
      }
    }, { passive: true });

    startAuto();
  })();

  (function () {
    const categoryBar = document.getElementById('categoryBar');
    const cards = document.querySelectorAll('.product-card');

    if (!categoryBar || cards.length === 0) return;

    categoryBar.addEventListener('click', function (event) {
      const button = event.target.closest('button[data-category]');
      if (!button) return;

      const selected = button.dataset.category;

      categoryBar.querySelectorAll('button').forEach(btn => {
        btn.classList.toggle('active', btn === button);
      });

      cards.forEach(card => {
        const cardCategory = card.dataset.category;
        card.style.display = selected === 'all' || cardCategory === selected ? '' : 'none';
      });
    });
  })();
