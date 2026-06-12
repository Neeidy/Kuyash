/* Kuyash Phase 0 — app shell: View-Transition router, sidebar, topbar
   (workspace switcher, LIVE indicator, EN|TR toggle), live-subscription
   lifecycle. Loaded last; classic scripts only (file:// safe). */
(function () {
  'use strict';
  var PL = window.Kuyash, ui = PL.ui, store = PL.store, t = PL.t;

  var ORDER = ['dashboard', 'trends', 'studio', 'library', 'workflow', 'queue',
    'accounts', 'preview', 'logs', 'analytics', 'usage', 'settings', 'onboarding'];
  /* the composer is routable but lives outside the 13-screen nav list */
  var ROUTES = ORDER.concat(['create']);

  var screenUnsubs = []; /* per-screen live subscriptions, torn down on navigation */

  var LS_DENSITY = 'kuyash.density';

  var App = PL.App = {

    ORDER: ORDER,

    /* screens call this inside render(); router clears on every (re)render */
    onLive: function (type, fn) { screenUnsubs.push(PL.live.subscribe(type, fn)); },

    clearLive: function () {
      screenUnsubs.forEach(function (u) { u(); });
      screenUnsubs.length = 0;
    },

    rerender: function (keepSearchFocus) {
      App.clearLive();
      /* keyboard selection dies with the DOM it pointed at — A/R must never
         act on an invisible selection */
      PL.state.kbdIndex = -1;
      var key = PL.state.route;
      var screen = PL.screens[key] || PL.screens.dashboard;
      var rootEl = document.getElementById('screen-root');
      screen.render(rootEl);
      App.renderSidebarActive();
      App.renderTopbar();
      if (keepSearchFocus) {
        var s = rootEl.querySelector('[data-search]');
        if (s) { s.focus(); s.setSelectionRange(s.value.length, s.value.length); }
      }
    },

    route: function () {
      var hash = (location.hash || '').replace(/^#\/?/, '');
      var key = ROUTES.indexOf(hash) !== -1 ? hash : 'dashboard';
      PL.state.route = key;
      PL.state.sidebarOpen = false;
      document.body.classList.remove('sidebar-open');
      ui.closeModal();
      PL.motion.vt(function () {
        App.rerender();
        window.scrollTo(0, 0);
      }, document.getElementById('screen-root'));
    },

    switchWorkspace: function (wsId) {
      if (wsId === PL.state.workspaceId) return;
      PL.state.workspaceId = wsId;
      /* tenant isolation: a half-built run never carries another workspace's
         media or spends the new workspace's credits */
      PL.state.composer = null;
      ui.toast(t('toast.switched', { ws: store.workspace().name }), 'info');
      App.rerender();
    },

    setLang: function (lang) {
      if (lang === PL.i18n.lang()) return;
      PL.i18n.set(lang);
      App.renderSidebar();
      App.rerender();
    },

    /* density: comfortable | compact — token override only, persisted like language */
    toggleDensity: function () {
      var next = document.documentElement.getAttribute('data-density') === 'compact' ? 'comfortable' : 'compact';
      App.setDensity(next);
      ui.toast(t('toast.density', { mode: t('density.' + next) }), 'info');
      App.renderTopbar();
    },
    setDensity: function (d) {
      if (d === 'compact') document.documentElement.setAttribute('data-density', 'compact');
      else document.documentElement.removeAttribute('data-density');
      try { window.localStorage.setItem(LS_DENSITY, d); } catch (e) { /* file:// may block */ }
    },

    renderSidebar: function () {
      var el = document.getElementById('sidebar');
      el.innerHTML =
        '<div class="sidebar__logo"><span class="logo-mark mono">P_</span>' +
        '<span class="logo-word">Kuyash</span></div>' +
        '<button class="btn btn--primary sidebar__create" data-nav="create">' +
          ui.icon('plus') + '<span>' + ui.esc(t('create.btn')) + '</span></button>' +
        '<nav class="sidebar__nav" aria-label="' + ui.esc(t('sidebar.navAria')) + '">' +
        ORDER.map(function (key) {
          var s = PL.screens[key];
          return '<a class="nav-item" href="#/' + key + '" data-navitem="' + key + '">' +
            '<span class="nav-item__marker"></span>' + ui.icon(s.icon) +
            '<span>' + ui.esc(t('nav.' + key)) + '</span></a>';
        }).join('') +
        '</nav>' +
        '<div class="sidebar__foot">' +
        '<span class="chip chip--faint mono">' + ui.esc(t('sidebar.badge')) + '</span>' +
        '<p>' + ui.esc(t('sidebar.note')) + '</p></div>';
      App.renderSidebarActive();
    },

    renderSidebarActive: function () {
      document.querySelectorAll('[data-navitem]').forEach(function (a) {
        var active = a.getAttribute('data-navitem') === PL.state.route;
        a.classList.toggle('is-active', active);
        var marker = a.querySelector('.nav-item__marker');
        if (marker) marker.classList.toggle('vt-nav-active', active);
      });
    },

    renderTopbar: function () {
      var el = document.getElementById('topbar');
      var ws = store.workspace();
      var mode = store.approvalMode();
      var user = PL.state.data.meta.demo_user;
      var killed = PL.state.killSwitch[ws.id];
      var lang = PL.i18n.lang();

      el.innerHTML =
        '<button class="iconbtn topbar__menu" data-menu-btn aria-label="' + ui.esc(t('topbar.menu')) + '">' + ui.icon('menu') + '</button>' +

        '<div class="ws-switcher" data-ws-switcher>' +
        '  <button class="ws-switcher__btn" data-ws-toggle aria-haspopup="listbox" aria-expanded="false" aria-label="' + ui.esc(t('topbar.wsSwitch')) + '">' +
             ui.icon(ws.icon) + '<span>' + ui.esc(ws.name) + '</span>' + ui.icon('chevR', 'ws-switcher__chev') +
        '  </button>' +
        '  <div class="ws-switcher__menu" data-ws-menu hidden>' +
             PL.state.data.workspaces.map(function (w) {
               return '<button class="ws-switcher__item ' + (w.id === ws.id ? 'is-active' : '') + '" data-ws-pick="' + w.id + '">' +
                 ui.icon(w.icon) + '<span><strong>' + ui.esc(w.name) + '</strong><small>' + ui.esc(w.niche) + '</small></span>' +
                 (w.id === ws.id ? ui.icon('check') : '') + '</button>';
             }).join('') +
        '  </div></div>' +

        '<div class="topbar__right">' +
        (killed ? '<span class="chip chip--err">' + ui.icon('warning') + ui.esc(t('topbar.paused')) + '</span>' : '') +
        '  <button class="mode-chip" data-nav="settings" title="' + ui.esc(t('queue.changeSettings')) + '">' +
             ui.dot(mode === 'manual' ? 'ok' : 'info') + '<span>' + ui.esc(t(mode === 'manual' ? 'topbar.manual' : 'topbar.auto')) + '</span>' +
        '  </button>' +
        /* the LIVE pill never lies: paused stream = static neutral dot + "Paused" */
        (PL.live.isPaused()
          ? '  <span class="live-pill" title="' + ui.esc(t('topbar.paused')) + '">' +
              '<span class="dot dot--neutral"></span><span class="live-pill__word live-pill__word--off">' + ui.esc(t('topbar.paused')) + '</span>' +
              '<span class="mono num" data-live-clock>' + ui.esc(PL.live.lastUpdate() || PL.fmt.clock()) + '</span></span>'
          : '  <span class="live-pill" title="' + ui.esc(t('topbar.live')) + '">' +
              '<span class="dot dot--ok pulse"></span><span class="live-pill__word">' + ui.esc(t('topbar.live')) + '</span>' +
              '<span class="mono num" data-live-clock>' + ui.esc(PL.live.lastUpdate() || PL.fmt.clock()) + '</span></span>') +
        /* visible ⌘K anchor — also the only palette path on touch */
        '  <button class="pal-chip" data-open-palette aria-label="' + ui.esc(t('pal.aria')) + '" title="' + ui.esc(t('pal.aria')) + '">' +
             ui.icon('search') + '<kbd class="mono">⌘K</kbd></button>' +
        '  <button class="iconbtn" data-density-toggle aria-label="' + ui.esc(t('topbar.density')) + '" ' +
             'aria-pressed="' + (document.documentElement.getAttribute('data-density') === 'compact') + '" ' +
             'title="' + ui.esc(t('topbar.density')) + '">' + ui.icon('density') + '</button>' +
        '  <div class="seg seg--sm" role="group" aria-label="' + ui.esc(t('topbar.lang')) + '">' +
             '<button class="seg__btn ' + (lang === 'en' ? 'is-active' : '') + '" data-lang="en">EN</button>' +
             '<button class="seg__btn ' + (lang === 'tr' ? 'is-active' : '') + '" data-lang="tr">TR</button>' +
        '  </div>' +
        '  <span class="avatar" title="' + ui.esc(user.name + ' · ' + user.email) + '">' + ui.esc(user.initials) + '</span>' +
        '</div>';

      el.querySelector('[data-menu-btn]').addEventListener('click', function () {
        PL.state.sidebarOpen = !PL.state.sidebarOpen;
        document.body.classList.toggle('sidebar-open', PL.state.sidebarOpen);
      });
      var toggle = el.querySelector('[data-ws-toggle]');
      var menu = el.querySelector('[data-ws-menu]');
      toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        menu.hidden = !menu.hidden;
        toggle.setAttribute('aria-expanded', String(!menu.hidden));
      });
      el.querySelectorAll('[data-ws-pick]').forEach(function (b) {
        b.addEventListener('click', function () {
          menu.hidden = true;
          App.switchWorkspace(b.getAttribute('data-ws-pick'));
        });
      });
      el.querySelectorAll('[data-lang]').forEach(function (b) {
        b.addEventListener('click', function () { App.setLang(b.getAttribute('data-lang')); });
      });
      el.querySelector('[data-density-toggle]').addEventListener('click', App.toggleDensity);
      el.querySelector('[data-open-palette]').addEventListener('click', function () { PL.palette.open(); });
    },

    boot: function () {
      document.documentElement.lang = PL.i18n.lang();
      /* restore density preference (silent in-memory fallback under file://) */
      var storedDensity = null;
      try { storedDensity = window.localStorage.getItem(LS_DENSITY); } catch (e) { /* blocked */ }
      if (storedDensity === 'compact') document.documentElement.setAttribute('data-density', 'compact');
      App.renderSidebar();
      window.addEventListener('hashchange', App.route);

      /* global delegation: nav, UI-state demo, retry-ui, ws-menu close */
      document.addEventListener('click', function (e) {
        var nav = e.target.closest('[data-nav]');
        if (nav) {
          var key = nav.getAttribute('data-nav');
          if ('#/' + key === location.hash) App.route(); else location.hash = '#/' + key;
          return;
        }
        var st = e.target.closest('[data-uistate]');
        if (st) {
          var parts = st.getAttribute('data-uistate').split(':');
          PL.state.uiStates[parts[0]] = parts[1];
          App.rerender();
          return;
        }
        var retry = e.target.closest('[data-action="retry-ui"]');
        if (retry) {
          if (PL.state.uiStates[PL.state.route] !== undefined) {
            PL.state.uiStates[PL.state.route] = 'loading';
            App.rerender();
            setTimeout(function () {
              if (PL.state.uiStates[PL.state.route] === 'loading') {
                PL.state.uiStates[PL.state.route] = 'data';
                App.rerender();
                /* skeleton → content morph: recovered content dissolves in */
                var rootEl = document.getElementById('screen-root');
                rootEl.classList.remove('skel-resolve');
                void rootEl.offsetWidth;
                rootEl.classList.add('skel-resolve');
                ui.toast(t('toast.recovered'), 'success');
              }
            }, 900);
          }
          return;
        }
        var menu = document.querySelector('[data-ws-menu]');
        if (menu && !menu.hidden && !e.target.closest('[data-ws-switcher]')) menu.hidden = true;
      });

      /* keyboard layer: Esc closes palette/drawer/modal (single root = natural
         priority); J/K navigate + A approve + R reject + Enter opens the
         drawer inside any [data-kbd-zone] (Queue, dashboard approval strip).
         Shortcuts stay inert while typing or while an overlay is open. */
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { ui.closeModal(); return; }
        var tag = (e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
        if (e.metaKey || e.ctrlKey || e.altKey) return;
        if (document.querySelector('#modal-root .modal-overlay')) return;
        /* a focused card keeps Enter/Space (its own handler opens the drawer);
           J/K/A/R fall through so the hints stay true even while tabbing */
        if (e.target.closest && e.target.closest('[data-kbd-zone]') &&
            (e.key === 'Enter' || e.key === ' ')) return;

        var zone = document.querySelector('[data-kbd-zone]');
        if (!zone) return;
        var rows = Array.prototype.slice.call(zone.querySelectorAll('[data-render-row]'));
        if (!rows.length) return;

        var key = e.key.toLowerCase();
        var idx = PL.state.kbdIndex != null ? PL.state.kbdIndex : -1;
        if (idx >= rows.length) idx = rows.length - 1;

        if (key === 'j' || key === 'k') {
          idx = key === 'j' ? Math.min(rows.length - 1, idx + 1) : Math.max(0, idx - 1);
          rows.forEach(function (r) { r.classList.remove('is-kbd'); });
          rows[idx].classList.add('is-kbd');
          /* real DOM focus keeps the Enter→drawer→Esc focus contract intact */
          if (rows[idx].focus) rows[idx].focus({ preventScroll: true });
          rows[idx].scrollIntoView({ block: 'nearest', inline: 'nearest' });
          PL.state.kbdIndex = idx;
          e.preventDefault();
          return;
        }
        /* A/R require a VISIBLE selection — never act on a stale index */
        if (idx < 0 || !rows[idx] || !rows[idx].classList.contains('is-kbd')) return;
        if (key === 'a') {
          e.preventDefault();
          PL.actions.approveRender(rows[idx].getAttribute('data-render-row'), rows[idx]);
        } else if (key === 'r') {
          e.preventDefault();
          PL.actions.rejectRender(rows[idx].getAttribute('data-render-row'));
        } else if (e.key === 'Enter') {
          e.preventDefault();
          PL.drawers.openRender(rows[idx].getAttribute('data-render-row'));
        }
      });
      document.querySelector('.scrim').addEventListener('click', function () {
        PL.state.sidebarOpen = false;
        document.body.classList.remove('sidebar-open');
      });

      /* persistent LIVE indicator updates (survives navigation) */
      PL.live.subscribe('*', function () {
        var clock = document.querySelector('[data-live-clock]');
        if (clock) clock.textContent = PL.live.lastUpdate();
      });
      PL.live.start(PL.MockTicker);

      if (!location.hash) location.hash = '#/dashboard';
      App.route();
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', App.boot);
  } else {
    App.boot();
  }
})();
