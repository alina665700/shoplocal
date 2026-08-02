(function() {
    if (window.__shoplocalfyAdminSidebarToggleLoaded) {
      return;
    }
    window.__shoplocalfyAdminSidebarToggleLoaded = true;

    function initAdminSidebarToggle() {
      const sidebar = document.getElementById('sidebar');
      const toggle =
        document.getElementById('sidebarToggle') ||
        document.querySelector('.admin-sidebar-toggle') ||
        document.querySelector('.sidebar-toggle');

      let overlay = document.querySelector('.sidebar-overlay');

      if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);
      }

      if (!sidebar || !toggle) {
        console.warn('Admin sidebar toggle could not start: missing #sidebar or #sidebarToggle.');
        return;
      }

      const mobileBreakpoint = 1100;

      function isMobileLayout() {
        return window.innerWidth <= mobileBreakpoint;
      }

      function openSidebar() {
        sidebar.classList.add('open');
        overlay.classList.add('active');
        document.body.classList.add('admin-sidebar-open');
        document.body.style.overflow = 'hidden';
        toggle.setAttribute('aria-expanded', 'true');
      }

      function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        document.body.classList.remove('admin-sidebar-open');
        document.body.style.overflow = '';
        toggle.setAttribute('aria-expanded', 'false');
      }

      function toggleSidebar(event) {
        event.preventDefault();
        event.stopPropagation();

        if (sidebar.classList.contains('open')) {
          closeSidebar();
        } else {
          openSidebar();
        }
      }

      toggle.setAttribute('aria-expanded', sidebar.classList.contains('open') ? 'true' : 'false');
      toggle.addEventListener('click', toggleSidebar);

      overlay.addEventListener('click', closeSidebar);

      sidebar.querySelectorAll('a').forEach(function(link) {
        link.addEventListener('click', function() {
          if (isMobileLayout()) {
            closeSidebar();
          }
        });
      });

      document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
          closeSidebar();
        }
      });

      document.addEventListener('click', function(event) {
        if (
          isMobileLayout() &&
          sidebar.classList.contains('open') &&
          !sidebar.contains(event.target) &&
          !toggle.contains(event.target)
        ) {
          closeSidebar();
        }
      });

      window.addEventListener('resize', function() {
        if (!isMobileLayout()) {
          closeSidebar();
        }
      });
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initAdminSidebarToggle);
    } else {
      initAdminSidebarToggle();
    }
  })();
