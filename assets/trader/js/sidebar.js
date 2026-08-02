(function () {
  if (window.__shoplocalfyTraderSidebarToggleLoaded) {
    return;
  }
  window.__shoplocalfyTraderSidebarToggleLoaded = true;

  document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebarToggle');
    const BREAKPOINT = 1100;

    let overlay = document.querySelector('.sidebar-overlay');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.className = 'sidebar-overlay';
      document.body.appendChild(overlay);
    }

    if (!toggle || !sidebar) return;

    function isCompactLayout() {
      return window.innerWidth <= BREAKPOINT;
    }

    function openSidebar() {
      sidebar.classList.add('open');
      overlay.classList.add('active');
      document.body.style.overflow = 'hidden';
      toggle.setAttribute('aria-expanded', 'true');
    }

    function closeSidebar() {
      sidebar.classList.remove('open');
      overlay.classList.remove('active');
      document.body.style.overflow = '';
      toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.setAttribute('aria-expanded', sidebar.classList.contains('open') ? 'true' : 'false');

    toggle.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();

      if (sidebar.classList.contains('open')) {
        closeSidebar();
      } else {
        openSidebar();
      }
    });

    overlay.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeSidebar();
      }
    });

    document.addEventListener('click', function (event) {
      if (
        isCompactLayout() &&
        sidebar.classList.contains('open') &&
        !sidebar.contains(event.target) &&
        !toggle.contains(event.target)
      ) {
        closeSidebar();
      }
    });

    window.addEventListener('resize', function () {
      if (!isCompactLayout()) {
        closeSidebar();
      }
    });
  });
})();
