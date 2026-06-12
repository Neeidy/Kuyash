/* Kuyash app shell — mobile sidebar toggle + destructive-action confirm.
   Vanilla JS, no frameworks. */
(function () {
  'use strict';

  /* forms marked data-confirm ask before submitting (e.g. reject = cancel
     the whole run); without JS the form still posts — CSRF + server guards hold */
  document.addEventListener('submit', function (e) {
    var form = e.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.getAttribute('data-confirm'))) {
      e.preventDefault();
    }
  });

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
