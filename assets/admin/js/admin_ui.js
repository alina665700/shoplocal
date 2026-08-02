(function() {
  if (window.__shoplocalfyAdminUiLoaded) return;
  window.__shoplocalfyAdminUiLoaded = true;

  document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebarToggle');
    let overlay = document.querySelector('.sidebar-overlay');

    if (!overlay) {
      overlay = document.createElement('div');
      overlay.className = 'sidebar-overlay';
      document.body.appendChild(overlay);
    }

    if (!sidebar) return;

    function isMobile() {
      return window.innerWidth <= 1100;
    }

    function closeSidebar() {
      sidebar.classList.remove('open');
      overlay.classList.remove('active');
      document.body.style.overflow = '';
      if (toggle) toggle.setAttribute('aria-expanded', 'false');
    }

    function openSidebar() {
      sidebar.classList.add('open');
      overlay.classList.add('active');
      document.body.style.overflow = 'hidden';
      if (toggle) toggle.setAttribute('aria-expanded', 'true');
    }

    if (toggle) {
      toggle.setAttribute('aria-expanded', sidebar.classList.contains('open') ? 'true' : 'false');
      toggle.addEventListener('click', function() {
        sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
      });
    }

    overlay.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape') closeSidebar();
    });
    window.addEventListener('resize', function() {
      if (!isMobile()) closeSidebar();
    });
  });
})();
