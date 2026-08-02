(function () {
  function initCustomerNavbar() {
    const navbar = document.getElementById('customerNavbar');
    const toggle = document.getElementById('customerNavToggle');
    const panel = document.getElementById('customerNavPanel');
    const forms = document.querySelectorAll('.site-search-form');

    forms.forEach(form => {
      if (form.dataset.searchReady === '1') return;
      form.dataset.searchReady = '1';

      form.addEventListener('submit', function (event) {
        const input = form.querySelector('input[name="q"]');

        if (!input || input.value.trim() === '') {
          event.preventDefault();
          if (input) {
            input.focus();
          }
        }
      });
    });

    if (!navbar || !toggle || !panel || toggle.dataset.ready === '1') {
      return;
    }

    toggle.dataset.ready = '1';

    function closeMenu() {
      navbar.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
    }

    function toggleMenu(event) {
      event.preventDefault();
      event.stopPropagation();

      const willOpen = !navbar.classList.contains('is-open');
      navbar.classList.toggle('is-open', willOpen);
      toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    }

    toggle.addEventListener('click', toggleMenu);

    panel.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', closeMenu);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeMenu();
      }
    });

    document.addEventListener('click', function (event) {
      if (!navbar.contains(event.target)) {
        closeMenu();
      }
    });

    window.addEventListener('resize', function () {
      if (window.innerWidth > 1180) {
        closeMenu();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCustomerNavbar);
  } else {
    initCustomerNavbar();
  }
})();
