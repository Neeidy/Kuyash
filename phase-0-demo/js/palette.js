/* Kuyash Phase 0 — ⌘K / Ctrl+K command palette (iteration 2 §E1).
   Keyboard-first: fuzzy subsequence filter, ↑↓ + Enter, Esc closes.
   Actions are rebuilt on every open from live state (awaiting renders,
   workspaces, language, density) — never stale. */
(function () {
  'use strict';
  var PL = window.Kuyash;

  function buildActions() {
    var ui = PL.ui, t = PL.t, store = PL.store;
    var acts = [];

    acts.push({
      sec: 'actions', icon: 'plus', label: t('cta.create'),
      run: function () { location.hash = '#/create'; }
    });

    store.byWorkspace('renders').filter(function (r) { return r.status === 'awaiting_approval'; })
      .forEach(function (r) {
        acts.push({
          sec: 'actions', icon: 'check', label: t('pal.approve', { title: r.title }),
          run: function () {
            var row = document.querySelector('[data-render-row="' + r.id + '"]');
            PL.actions.approveRender(r.id, row);
          }
        });
      });

    PL.state.data.workspaces.forEach(function (w) {
      if (w.id === PL.state.workspaceId) return;
      acts.push({
        sec: 'actions', icon: w.icon, label: t('pal.switchWs', { ws: w.name }),
        run: function () { PL.App.switchWorkspace(w.id); }
      });
    });

    acts.push({
      sec: 'actions', icon: 'gear',
      label: t(PL.i18n.lang() === 'en' ? 'pal.langTr' : 'pal.langEn'),
      run: function () { PL.App.setLang(PL.i18n.lang() === 'en' ? 'tr' : 'en'); }
    });

    if (PL.App.toggleDensity) {
      acts.push({
        sec: 'actions', icon: 'density', label: t('pal.density'),
        run: function () { PL.App.toggleDensity(); }
      });
    }

    PL.App.ORDER.forEach(function (key) {
      var s = PL.screens[key];
      if (!s) return;
      acts.push({
        sec: 'nav', icon: s.icon, label: t('pal.goTo', { screen: t('nav.' + key) }),
        run: function () { location.hash = '#/' + key; }
      });
    });
    acts.push({
      sec: 'nav', icon: 'plus', label: t('pal.goTo', { screen: t('create.title') }),
      run: function () { location.hash = '#/create'; }
    });

    return acts;
  }

  /* fuzzy match; lower score = better. A contiguous substring beats any
     spread subsequence ("trend" → "Trend Radar", not "RendeR queue"). */
  function fuzzy(query, label) {
    var q = query.toLowerCase(), l = label.toLowerCase();
    if (!q) return 0;
    var sub = l.indexOf(q);
    if (sub !== -1) return sub * 0.1;
    var li = 0, first = -1, last = -1;
    for (var qi = 0; qi < q.length; qi++) {
      li = l.indexOf(q[qi], li);
      if (li === -1) return -1;
      if (first === -1) first = li;
      last = li; li++;
    }
    return 10 + first + (last - first - q.length + 1) * 2;
  }

  var openState = null; /* { acts, filtered, active } */

  function renderList(listEl, query) {
    var ui = PL.ui, t = PL.t;
    var scored = openState.acts.map(function (a) {
      return { a: a, s: fuzzy(query, a.label) };
    }).filter(function (x) { return x.s !== -1; });
    scored.sort(function (x, y) { return x.s - y.s; });
    openState.filtered = scored.map(function (x) { return x.a; });
    openState.active = 0;

    if (!openState.filtered.length) {
      listEl.innerHTML = '<li class="palette__empty">' + ui.esc(t('pal.empty')) + '</li>';
      return;
    }
    var html = '', lastSec = null;
    openState.filtered.forEach(function (a, i) {
      if (a.sec !== lastSec) {
        lastSec = a.sec;
        html += '<li class="palette__sec" aria-hidden="true">' + ui.esc(t(a.sec === 'nav' ? 'pal.secNav' : 'pal.secActions')) + '</li>';
      }
      html += '<li class="palette__item' + (i === openState.active ? ' is-active' : '') + '" role="option" ' +
        'aria-selected="' + (i === openState.active) + '" data-pal-i="' + i + '">' +
        ui.icon(a.icon) + '<span>' + ui.esc(a.label) + '</span></li>';
    });
    listEl.innerHTML = html;
  }

  function setActive(listEl, idx) {
    var items = listEl.querySelectorAll('.palette__item');
    if (!items.length) return;
    openState.active = (idx + items.length) % items.length;
    items.forEach(function (it, i) {
      it.classList.toggle('is-active', i === openState.active);
      it.setAttribute('aria-selected', String(i === openState.active));
    });
    items[openState.active].scrollIntoView({ block: 'nearest' });
  }

  PL.palette = {
    open: function () {
      var ui = PL.ui, t = PL.t;
      ui.closeModal(); /* palette replaces any open drawer/modal */
      ui.captureFocus(); /* Esc returns focus to wherever ⌘K was pressed */
      openState = { acts: buildActions(), filtered: [], active: 0 };

      var rootEl = document.getElementById('modal-root');
      rootEl.innerHTML =
        '<div class="modal-overlay modal-overlay--palette" data-action="modal-overlay">' +
        '  <div class="palette" role="dialog" aria-modal="true" aria-label="' + ui.esc(t('pal.aria')) + '">' +
        '    <div class="palette__inputrow">' + ui.icon('search') +
        '      <input type="text" class="palette__input" placeholder="' + ui.esc(t('pal.placeholder')) + '" ' +
        '        aria-label="' + ui.esc(t('pal.aria')) + '" data-pal-input>' +
        '      <kbd class="palette__esc mono">esc</kbd></div>' +
        '    <ul class="palette__list" role="listbox" data-pal-list></ul>' +
        '  </div></div>';

      var overlay = rootEl.querySelector('.modal-overlay');
      /* @starting-style fallback parity with modals */
      if (!(window.CSS && CSS.supports && CSS.supports('transition-behavior', 'allow-discrete'))) {
        overlay.classList.add('enter-init');
        requestAnimationFrame(function () { requestAnimationFrame(function () { overlay.classList.remove('enter-init'); }); });
      }
      overlay.addEventListener('click', function (e) { if (e.target === e.currentTarget) ui.closeModal(); });

      var input = rootEl.querySelector('[data-pal-input]');
      var list = rootEl.querySelector('[data-pal-list]');
      renderList(list, '');
      input.focus();

      input.addEventListener('input', function () { renderList(list, input.value); });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(list, openState.active + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(list, openState.active - 1); }
        else if (e.key === 'Enter') {
          e.preventDefault();
          var a = openState.filtered[openState.active];
          if (a) { ui.closeModal(); a.run(); }
        }
      });
      list.addEventListener('mousemove', function (e) {
        var it = e.target.closest('[data-pal-i]');
        if (it) setActive(list, parseInt(it.getAttribute('data-pal-i'), 10));
      });
      list.addEventListener('click', function (e) {
        var it = e.target.closest('[data-pal-i]');
        if (!it) return;
        var a = openState.filtered[parseInt(it.getAttribute('data-pal-i'), 10)];
        if (a) { ui.closeModal(); a.run(); }
      });
    },

    isOpen: function () {
      return !!document.querySelector('.palette');
    }
  };

  /* global shortcut — ⌘K (mac) / Ctrl+K */
  document.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      if (PL.palette.isOpen()) PL.ui.closeModal(); else PL.palette.open();
    }
  });
})();
