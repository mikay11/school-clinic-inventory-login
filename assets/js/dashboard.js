(function () {
  const toggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  if (!toggle || !sidebar || !overlay) return;

  function setOpen(open) {
    sidebar.classList.toggle('open', open);
    overlay.classList.toggle('open', open);
    document.body.classList.toggle('nav-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
  }

  toggle.addEventListener('click', function () {
    setOpen(!sidebar.classList.contains('open'));
  });

  overlay.addEventListener('click', function () {
    setOpen(false);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') setOpen(false);
  });

  sidebar.querySelectorAll('a').forEach(function (link) {
    link.addEventListener('click', function () {
      setOpen(false);
    });
  });

  window.addEventListener('resize', function () {
    if (window.innerWidth > 900) setOpen(false);
  });
})();

(function () {
  const toolbar = document.querySelector('.inventory-toolbar');
  if (!toolbar) return;

  toolbar.querySelectorAll('select').forEach(function (select) {
    select.addEventListener('change', function () {
      if (typeof toolbar.requestSubmit === 'function') {
        toolbar.requestSubmit();
      } else {
        const button = toolbar.querySelector('button[type="submit"]');
        if (button) button.click();
      }
    });
  });
})();
