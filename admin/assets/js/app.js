// assets/js/app.js

document.addEventListener('DOMContentLoaded', () => {

  const toggle  = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('sidebar');

  // ── Create overlay element dynamically if not in HTML ──
  let overlay = document.querySelector('.sidebar-overlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);
  }

  if (!toggle || !sidebar) return;

  // ── Open / Close helpers ──
  function openSidebar() {
    sidebar.classList.add('open');
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden'; // prevent body scroll on mobile
  }

  function closeSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('active');
    document.body.style.overflow = '';
  }

  function isMobile() {
    return window.innerWidth <= 768;
  }

  // ── Toggle button ──
  toggle.addEventListener('click', () => {
    if (sidebar.classList.contains('open')) {
      closeSidebar();
    } else {
      openSidebar();
    }
  });

  // ── Close on overlay click ──
  overlay.addEventListener('click', closeSidebar);

  // ── Close on Escape key ──
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && sidebar.classList.contains('open')) {
      closeSidebar();
    }
  });

  // ── Close on outside click (belt-and-braces) ──
  document.addEventListener('click', (e) => {
    if (
      isMobile() &&
      sidebar.classList.contains('open') &&
      !sidebar.contains(e.target) &&
      !toggle.contains(e.target)
    ) {
      closeSidebar();
    }
  });

  // ── On resize: restore desktop state ──
  window.addEventListener('resize', () => {
    if (!isMobile()) {
      // Desktop — always visible, clean up mobile state
      closeSidebar();
      document.body.style.overflow = '';
    }
  });

});