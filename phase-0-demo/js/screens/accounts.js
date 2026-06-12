/* Screen 7 — Creators / Accounts: workspaces, accounts, OAuth mock, caps */
(function () {
  'use strict';
  var PL = window.Kuyash, ui = PL.ui, store = PL.store, t = PL.t;

  PL.screens.accounts = {
    id: 'accounts', icon: 'users',

    render: function (el) {
      var ws = store.workspace();
      var accounts = store.byWorkspace('accounts');

      el.innerHTML = `
        <header class="screen-head">
          <div><h1 class="vt-page-title">${ui.esc(t('nav.accounts'))}</h1>
          <p class="screen-sub">${ui.esc(t('accounts.sub'))}</p></div>
          <button class="btn btn--primary" data-connect>${ui.icon('plus')}${ui.esc(t('accounts.connect'))}</button>
        </header>

        <div class="ws-strip">
          ${PL.state.data.workspaces.map(function (w) {
            var count = PL.state.data.accounts.filter(function (a) { return a.workspace_id === w.id; }).length;
            var active = w.id === ws.id;
            return `<button class="ws-card ${active ? 'is-active' : ''}" data-switch-ws="${w.id}">
              ${ui.icon(w.icon)}<div><strong>${ui.esc(w.name)}</strong><span>${ui.esc(w.niche)} · ${ui.esc(t('ws.accounts', { n: count }))}</span></div>
              ${active ? '<span class="chip chip--ok">' + ui.esc(t('ws.active')) + '</span>' : '<span class="chip chip--neutral">' + ui.esc(t('ws.switch')) + '</span>'}
            </button>`;
          }).join('')}
        </div>

        <div class="account-grid stagger">
          ${accounts.map(function (a) {
            var capPct = a.posts_today / a.daily_cap;
            return `<article class="panel account-card lift" data-open-account="${a.id}" tabindex="0" role="button">
              <div class="account-card__head">
                ${ui.platformIcon(a.platform)}
                <div><h3>${ui.esc(a.handle)}</h3><span class="faint">${ui.esc(ui.platformName(a.platform))} · ${ui.esc(t('accounts.connectedAt', { date: PL.fmt.when(a.connected_at) }))}</span></div>
                ${ui.statusBadge(a.status)}
              </div>
              ${a.warn_key ? `<div class="callout callout--${a.status === 'error' ? 'err' : 'warn'}">${ui.icon('warning')}<div>${ui.esc(t(a.warn_key))}</div></div>` : ''}
              <div class="account-card__cap">
                <span class="faint">${ui.esc(t('accounts.dailyCap'))}</span>
                <span class="mono num ${capPct >= 1 ? 'text-err' : capPct >= 0.75 ? 'text-warn' : ''}">${ui.esc(t('accounts.postsToday', { used: a.posts_today, cap: a.daily_cap }))}</span>
                ${ui.meter(a.posts_today, a.daily_cap)}
              </div>
              <div class="account-card__foot">
                ${a.status !== 'connected'
                  ? `<button class="btn btn--primary btn--sm" data-reconnect="${a.id}">${ui.icon('refresh')}${ui.esc(t('accounts.reconnect'))}</button>`
                  : `<span class="health">${ui.dot('ok')}${ui.esc(t('accounts.healthy'))}</span>`}
              </div>
            </article>`;
          }).join('')}
        </div>
        ${ui.note(t('accounts.note'))}`;

      var grid = el.querySelector('.account-grid');
      if (grid) PL.motion.stagger(grid);

      el.querySelectorAll('[data-switch-ws]').forEach(function (b) {
        b.addEventListener('click', function () {
          PL.App.switchWorkspace(b.getAttribute('data-switch-ws'));
        });
      });

      /* cards open the account drawer (buttons excluded) */
      el.querySelectorAll('[data-open-account]').forEach(function (card) {
        card.addEventListener('click', function (e) {
          if (e.target.closest('button')) return;
          PL.drawers.openAccount(card.getAttribute('data-open-account'));
        });
        card.addEventListener('keydown', function (e) {
          if ((e.key === 'Enter' || e.key === ' ') && !e.target.closest('button')) {
            e.preventDefault();
            PL.drawers.openAccount(card.getAttribute('data-open-account'));
          }
        });
      });

      el.querySelectorAll('[data-reconnect]').forEach(function (b) {
        b.addEventListener('click', function (e) {
          e.stopPropagation();
          var a = store.find('accounts', b.getAttribute('data-reconnect'));
          openOauthFlow(a.platform, function () {
            a.status = 'connected'; a.health = 'healthy'; a.warn_key = null;
            store.logKeyed('log.account_reconnected', { handle: a.handle });
            ui.toast(t('toast.reconnected', { handle: a.handle }), 'success');
            PL.App.rerender();
          });
        });
      });

      el.querySelector('[data-connect]').addEventListener('click', function () {
        var step1 = ui.modal({
          title: t('dlg.connect_t'),
          body: `<p class="modal__text">${ui.esc(t('dlg.connect_b'))}</p>
            <div class="platform-pick">
              ${['instagram', 'tiktok', 'youtube'].map(function (p) {
                return `<button class="platform-pick__btn" data-pick="${p}">${ui.platformIcon(p)}<span>${ui.platformName(p)}</span></button>`;
              }).join('')}
            </div>`
        });
        step1.querySelectorAll('[data-pick]').forEach(function (pb) {
          pb.addEventListener('click', function () {
            var platform = pb.getAttribute('data-pick');
            openOauthFlow(platform, function () {
              PL.state.data.accounts.push({
                id: 'ac_x' + Math.random().toString(36).slice(2, 6), workspace_id: ws.id,
                platform: platform, handle: '@new.' + platform + '.account',
                status: 'connected', health: 'healthy', daily_cap: 2, posts_today: 0, connected_at: { day: 'now' }
              });
              store.logKeyed('log.account_connected', { handle: '@new.' + platform + '.account' });
              ui.toast(t('toast.connected'), 'success');
              PL.App.rerender();
            });
          });
        });
      });

      function openOauthFlow(platform, done) {
        var m = ui.modal({
          title: t('dlg.oauth_t'),
          body: `<div class="oauth-mock">
              <div class="oauth-mock__head">${ui.platformIcon(platform)}<strong>${ui.esc(ui.platformName(platform))}</strong> · ${ui.esc(t('dlg.oauth_consent'))}</div>
              <p>${ui.esc(t('dlg.oauth_asking'))}</p>
              <ul class="stat-list">
                <li>${ui.icon('check')}${ui.esc(t('dlg.oauth_s1'))}</li>
                <li>${ui.icon('check')}${ui.esc(t('dlg.oauth_s2'))}</li>
              </ul>
              ${ui.note(t('dlg.oauth_note'))}
            </div>`,
          footer: `<button class="btn btn--ghost" data-action="modal-close">${ui.esc(t('common.deny'))}</button>
                   <button class="btn btn--primary" data-oauth-ok>${ui.esc(t('dlg.oauth_ok'))}</button>`
        });
        m.querySelector('[data-oauth-ok]').addEventListener('click', function () {
          ui.closeModal(); done();
        });
      }
    }
  };
})();
