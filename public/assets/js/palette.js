/* Kuyash ⌘K command palette (Phase 16). Keyboard-first, type-to-filter,
   focus-trapped, restores focus on close. Items are pure navigation (static
   server-rendered hrefs) plus one action that opens the shortcuts drawer —
   no state change, no form post, no CSRF surface, no user-input reflection. */
(function () {
  'use strict';
  var palette = document.getElementById('cmdk');
  if (!palette) return;
  var input = palette.querySelector('.cmdk__input');
  var list = palette.querySelector('.cmdk__list');
  var empty = palette.querySelector('.cmdk__empty');
  var items = Array.prototype.slice.call(list.querySelectorAll('.cmdk__item'));
  var lastFocus = null;
  var sel = 0;

  function visible() { return items.filter(function (i) { return !i.hidden; }); }

  function setActive(i) {
    var vis = visible();
    if (!vis.length) { sel = 0; return; }
    sel = (i + vis.length) % vis.length;
    items.forEach(function (it) { it.classList.remove('is-active'); it.setAttribute('aria-selected', 'false'); });
    var el = vis[sel];
    el.classList.add('is-active');
    el.setAttribute('aria-selected', 'true');
    el.scrollIntoView({ block: 'nearest' });
  }

  function filter() {
    var q = input.value.trim().toLowerCase();
    items.forEach(function (it) {
      var label = (it.getAttribute('data-label') || it.textContent || '').toLowerCase();
      it.hidden = q !== '' && label.indexOf(q) === -1;
    });
    empty.hidden = visible().length > 0;
    setActive(0);
  }

  function activate(el) {
    if (!el) return;
    var action = el.getAttribute('data-action');
    var href = el.getAttribute('data-href');
    close();
    if (action === 'shortcuts' && window.PL && PL.drawer) { PL.drawer.openTemplate('tpl-shortcuts'); return; }
    if (href) window.location.href = href;
  }

  function isOpen() { return palette.classList.contains('is-open'); }

  function open() {
    lastFocus = document.activeElement;
    palette.classList.add('is-open');
    input.value = '';
    filter();
    document.addEventListener('keydown', onKey, true);
    setTimeout(function () { input.focus(); }, 0);
  }

  function close() {
    palette.classList.remove('is-open');
    document.removeEventListener('keydown', onKey, true);
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  function onKey(e) {
    if (!isOpen()) return;
    if (e.key === 'Escape') { e.preventDefault(); close(); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); setActive(sel + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(sel - 1); }
    else if (e.key === 'Enter') { e.preventDefault(); activate(visible()[sel]); }
    else if (e.key === 'Tab') { e.preventDefault(); } /* trap focus in the search box */
  }

  /* global open shortcut + topbar trigger(s) */
  document.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      isOpen() ? close() : open();
    }
  });
  Array.prototype.slice.call(document.querySelectorAll('[data-cmdk-open]')).forEach(function (b) {
    b.addEventListener('click', function () { open(); });
  });

  input.addEventListener('input', filter);
  palette.addEventListener('click', function (e) { if (e.target === palette) close(); });
  items.forEach(function (it) { it.addEventListener('click', function () { activate(it); }); });

  (window.PL = window.PL || {}).palette = { open: open, close: close };
})();
