/* Screen 11 — Usage / Credits & Costs */
(function () {
  'use strict';
  var PL = window.Kuyash, ui = PL.ui, store = PL.store, t = PL.t;

  var CATS = [['ai_text', 'usage.catText'], ['tts', 'usage.catTts'], ['ai_video', 'usage.catVideo'], ['publishing', 'usage.catPub']];
  var lastUsed = {}; /* per-workspace previous spend → count-up on change */

  PL.screens.usage = {
    id: 'usage', icon: 'credits',

    render: function (el) {
      var ws = store.workspace();
      var cr = store.credits();
      var capPct = cr.used_this_month / cr.budget_cap;
      var maxCat = Math.max.apply(null, CATS.map(function (c) { return cr.breakdown[c[0]]; }));
      var plan = PL.state.data.plan;
      var top = CATS.reduce(function (a, b) { return cr.breakdown[a[0]] >= cr.breakdown[b[0]] ? a : b; });

      var stateHtml = ui.resolveUiState('usage',
        { icon: 'credits', title: t('usage.empty_t'), body: t('usage.empty_b'),
          cta: { label: t('cta.create'), nav: 'create', icon: 'plus' } },
        { title: t('usage.error_t'), body: t('usage.error_b') });

      el.innerHTML = `
        <header class="screen-head">
          <div><h1 class="vt-page-title">${ui.esc(t('nav.usage'))}</h1>
          <p class="screen-sub">${ui.esc(t('usage.sub', { ws: ws.name }))}</p></div>
          ${ui.uiStateBar('usage')}
        </header>

        ${stateHtml ? stateHtml : `
        ${capPct >= 0.75 ? `<div class="callout callout--${capPct >= 0.9 ? 'err' : 'warn'} callout--banner">${ui.icon('warning')}
          <div><strong>${ui.esc(t('usage.warn', { used: cr.used_this_month, cap: cr.budget_cap, pct: Math.round(capPct * 100) }))}</strong></div>
          <button class="btn btn--ghost btn--sm" data-nav="settings">${ui.esc(t('usage.adjustCap'))}</button></div>` : ''}

        <div class="panel stat-row">
          ${ui.stat('usage.balance', String(cr.balance), ui.esc(t('usage.ofMonthly', { n: cr.monthly_allowance })))}
          ${ui.stat('usage.usedMonth', '<span data-used-num>' + cr.used_this_month + '</span>', ui.meter(cr.used_this_month, cr.budget_cap))}
          ${ui.stat('usage.cap', String(cr.budget_cap), ui.esc(t('usage.hardStop')))}
          ${ui.stat('usage.biggest', ui.esc(t(top[1])), ui.esc(t('usage.seeBreakdown')))}
        </div>

        <div class="bento bento--usage">
          ${ui.card({
            title: t('usage.breakdown'),
            body: `<ul class="break-list">
              ${CATS.map(function (c) {
                var v = cr.breakdown[c[0]];
                return `<li><span class="break-list__label">${ui.esc(t(c[1]))}</span>
                  <div class="break-list__bar"><div style="width:${Math.round(v / maxCat * 100)}%"></div></div>
                  <b class="mono num">${v}</b></li>`;
              }).join('')}
            </ul>`
          })}

          ${ui.card({
            title: t('usage.perWs'),
            body: `<div class="table-wrap"><table class="table">
              <thead><tr><th>${ui.esc(t('usage.thWs'))}</th><th>${ui.esc(t('usage.thUsed'))}</th><th>${ui.esc(t('usage.thCap'))}</th><th></th></tr></thead>
              <tbody>
                ${PL.state.data.workspaces.map(function (w) {
                  var c = PL.state.data.credits[w.id];
                  return `<tr class="${w.id === ws.id ? 'is-active' : ''}"><td>${ui.esc(w.name)}</td>
                    <td class="mono">${c.used_this_month}</td><td class="mono">${c.budget_cap}</td>
                    <td style="min-width:90px">${ui.meter(c.used_this_month, c.budget_cap)}</td></tr>`;
                }).join('')}
              </tbody>
            </table></div>`
          })}

          ${ui.card({
            title: t('usage.charges'),
            body: `<ul class="history-list">
              ${cr.history.map(function (h) {
                return `<li><span class="history-list__time mono">${ui.esc(PL.fmt.when(h.at))}</span>
                  <span class="history-list__label">${ui.esc(t(h.label_key, h.label_params || {}))}</span>
                  <b class="mono num ${h.amount < 0 ? '' : 'text-ok'}">${h.amount > 0 ? '+' : ''}${h.amount}</b></li>`;
              }).join('')}
            </ul>`
          })}

          ${ui.card({
            title: t('usage.plan'), cls: 'plan-card',
            chip: '<span class="chip chip--neutral mono">' + ui.esc(plan.badge) + '</span>',
            body: `<h3 class="plan-card__name">${ui.esc(t(plan.name_key))}</h3>
              <ul class="stat-list">${plan.includes.map(function (k) { return '<li>' + ui.icon('check') + ui.esc(t(k)) + '</li>'; }).join('')}</ul>
              ${ui.note(t(plan.note_key))}
              <button class="btn btn--ghost" data-upgrade>${ui.esc(t('usage.upgrade'))}</button>`
          })}
        </div>`}`;

      /* count-up the spend when it changed since the last visit (e.g. Quick Create) */
      var usedEl = el.querySelector('[data-used-num]');
      if (usedEl && lastUsed[ws.id] != null && lastUsed[ws.id] !== cr.used_this_month) {
        usedEl.textContent = String(lastUsed[ws.id]);
        usedEl.dataset.countVal = String(lastUsed[ws.id]);
        PL.motion.countUp(usedEl, cr.used_this_month);
      }
      lastUsed[ws.id] = cr.used_this_month;

      var up = el.querySelector('[data-upgrade]');
      if (up) up.addEventListener('click', function () {
        ui.modal({
          title: t('dlg.plans_t'),
          body: '<p class="modal__text">' + ui.esc(t('plan.dlg_b')) + '</p>',
          footer: '<button class="btn btn--primary" data-action="modal-close">' + ui.esc(t('common.gotIt')) + '</button>'
        });
      });
    }
  };
})();
