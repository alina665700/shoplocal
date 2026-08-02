// Inlined from assets/js/app.js
// assets/js/app.js

document.addEventListener('DOMContentLoaded', () => {
  const toggle  = document.getElementById('sidebarToggle');
  const sidebar = document.getElementById('sidebar');
  let overlay = document.querySelector('.sidebar-overlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);
  }
  if (!toggle || !sidebar) return;
  function openSidebar(){ sidebar.classList.add('open'); overlay.classList.add('active'); document.body.style.overflow = 'hidden'; }
  function closeSidebar(){ sidebar.classList.remove('open'); overlay.classList.remove('active'); document.body.style.overflow = ''; }
  function isMobile(){ return window.innerWidth <= 768; }
  toggle.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
  overlay.addEventListener('click', closeSidebar);
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && sidebar.classList.contains('open')) closeSidebar(); });
  document.addEventListener('click', e => { if (isMobile() && sidebar.classList.contains('open') && !sidebar.contains(e.target) && !toggle.contains(e.target)) closeSidebar(); });
  window.addEventListener('resize', () => { if (!isMobile()) closeSidebar(); });
});
