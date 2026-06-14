/* Kuyash global drawer (Phase 16) — one reusable right-side panel that later
   phases (17 approval detail, 18 pipeline node detail) fill with their own
   content. Exposes PL.drawer.open({title, html}) / openTemplate(id) / close().

   SECURITY: body HTML only ever comes from a server-rendered, escaped <template>
   in the page (openTemplate) or from first-party callers — never from user input
   or a network response. There is no user-controlled sink here. */
(function () {
  'use strict';
  var drawer = document.getElementById('drawer');
  if (!drawer) return;
  var titleEl = drawer.querySelector('.drawer__head h3');
  var bodyEl = drawer.querySelector('.drawer__body');
  var closeBtn = drawer.querySelector('.drawer__close');
  var scrim = drawer.querySelector('.drawer__scrim');
  var lastFocus = null;

  function open(opts) {
    opts = opts || {};
    lastFocus = document.activeElement;
    if (opts.title != null) titleEl.textContent = opts.title;
    if (opts.html != null) bodyEl.innerHTML = opts.html;
    drawer.classList.add('is-open');
    document.addEventListener('keydown', onKey, true);
    setTimeout(function () { if (closeBtn) closeBtn.focus(); }, 0);
  }
  function openTemplate(id) {
    var tpl = document.getElementById(id);
    if (!tpl) return;
    open({ title: tpl.getAttribute('data-title') || '', html: tpl.innerHTML });
  }
  function close() {
    drawer.classList.remove('is-open');
    document.removeEventListener('keydown', onKey, true);
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); close(); } }

  if (closeBtn) closeBtn.addEventListener('click', close);
  if (scrim) scrim.addEventListener('click', close);

  /* declarative triggers: <button data-drawer-open="tpl-id"> */
  Array.prototype.slice.call(document.querySelectorAll('[data-drawer-open]')).forEach(function (b) {
    b.addEventListener('click', function () { openTemplate(b.getAttribute('data-drawer-open')); });
  });

  (window.PL = window.PL || {}).drawer = { open: open, openTemplate: openTemplate, close: close };
})();
