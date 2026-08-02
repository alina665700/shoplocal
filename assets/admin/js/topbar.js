(function () {
    if (window.__shoplocalfyAdminTopbarSidebarBridgeLoaded) {
      return;
    }
    window.__shoplocalfyAdminTopbarSidebarBridgeLoaded = true;

    const MOBILE_BREAKPOINT = 1100;

    function getSidebar() {
      return document.getElementById('sidebar') ||
        document.getElementById('adminSidebar') ||
        document.querySelector('.admin-sidebar.sidebar') ||
        document.querySelector('.admin-sidebar') ||
        document.querySelector('aside.sidebar');
    }

    function getToggle() {
      return document.getElementById('sidebarToggle') ||
        document.querySelector('.admin-sidebar-toggle') ||
        document.querySelector('.sidebar-toggle');
    }

    function getOverlay() {
      let overlay = document.getElementById('adminSidebarOverlay') ||
        document.querySelector('.admin-sidebar-overlay') ||
        document.querySelector('.sidebar-overlay');

      if (!overlay && document.body) {
        overlay = document.createElement('div');
        overlay.id = 'adminSidebarOverlay';
        overlay.className = 'admin-sidebar-overlay sidebar-overlay';
        document.body.appendChild(overlay);
      }

      return overlay;
    }

    function setSidebarOpen(open) {
      const sidebar = getSidebar();
      const toggle = getToggle();
      const overlay = getOverlay();

      if (!sidebar) {
        console.warn('Admin sidebar was not found. Make sure admin/sidebar.php is included on this page.');
        return;
      }

      sidebar.classList.toggle('open', open);
      document.body.classList.toggle('admin-sidebar-open', open);

      if (overlay) {
        overlay.classList.toggle('active', open);
      }

      if (toggle) {
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      }

      if (window.innerWidth <= MOBILE_BREAKPOINT) {
        document.body.style.overflow = open ? 'hidden' : '';
      } else {
        document.body.style.overflow = '';
      }
    }

    function sidebarIsOpen() {
      const sidebar = getSidebar();
      return !!sidebar && (sidebar.classList.contains('open') || document.body.classList.contains('admin-sidebar-open'));
    }

    document.addEventListener('click', function (event) {
      const toggle = event.target.closest ? event.target.closest('#sidebarToggle, .admin-sidebar-toggle, .sidebar-toggle') : null;
      if (!toggle) {
        return;
      }

      event.preventDefault();
      event.stopImmediatePropagation();
      setSidebarOpen(!sidebarIsOpen());
    }, true);

    document.addEventListener('click', function (event) {
      const clickedOverlay = event.target.closest ? event.target.closest('.sidebar-overlay, .admin-sidebar-overlay') : null;
      if (clickedOverlay) {
        event.preventDefault();
        setSidebarOpen(false);
      }
    }, true);

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        setSidebarOpen(false);
      }
    });

    window.addEventListener('resize', function () {
      if (window.innerWidth > MOBILE_BREAKPOINT) {
        setSidebarOpen(false);
      }
    });
  })();
