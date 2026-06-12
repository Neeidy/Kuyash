/* Kuyash app shell — mobile sidebar toggle only. Vanilla JS, no frameworks. */
(function () {
  'use strict';

  var toggle = document.querySelector('[data-sidebar-toggle]');
  var scrim = document.querySelector('[data-sidebar-scrim]');
  if (!toggle) return;

  function setOpen(open) {
    document.body.classList.toggle('sidebar-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  toggle.addEventListener('click', function () {
    setOpen(!document.body.classList.contains('sidebar-open'));
  });
  if (scrim) {
    scrim.addEventListener('click', function () { setOpen(false); });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') setOpen(false);
  });
})();
