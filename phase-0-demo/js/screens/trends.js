/* Screen 2 — Trend Radar: velocity-coded trend cards, niche filter, live ticks. */
(function () {
  'use strict';
  var PL = window.Kuyash, ui = PL.ui, store = PL.store, t = PL.t;

  PL.screens.trends = {
    id: 'trends', icon: 'radar',

    render: function (el) {
      var ws = store.workspace();
      var selected = PL.state.trendsNiche[ws.id] || '';
      var all = store.byWorkspace('trends');
      var trends = selected ? all.filter(function (tr) { return tr.niche === selected; }) : all;

      var stateHtml = ui.resolveUiState('trends',
        { icon: 'radar', title: t('trends.empty_t'), body: t('trends.empty_b'),
          cta: { label: t('trends.emptyCta'), nav: 'settings', icon: 'gear' } },
        { title: t('trends.error_t'), body: t('trends.error_b') });

      el.innerHTML = `
        <header class="screen-head">
          <div><h1 class="vt-page-title">${ui.esc(t('nav.trends'))}</h1>
          <p class="screen-sub">${ui.esc(t('trends.sub'))}</p></div>
          ${ui.uiStateBar('trends')}
        </header>

        <div class="chip-row" role="group" aria-label="${ui.esc(t('trends.allNiches'))}">
          <button class="fchip ${selected === '' ? 'is-active' : ''}" data-niche="">${ui.esc(t('trends.allNiches'))}</button>
          ${ws.niches.map(function (n) {
            return `<button class="fchip ${selected === n ? 'is-active' : ''}" data-niche="${ui.esc(n)}">${ui.esc(n)}</button>`;
          }).join('')}
        </div>

        <div class="trend-grid stagger">
        ${stateHtml ? stateHtml :
          (trends.length === 0
            ? ui.emptyState({ icon: 'radar', title: t('trends.emptyNiche_t', { niche: selected }), body: t('trends.emptyNiche_b'),
                cta: { label: t('nav.workflow'), nav: 'workflow', icon: 'workflow' } })
            : trends.map(function (tr) {
              var hasIdea = PL.state.data.ideas.some(function (i) { return i.trend_id === tr.id && i.workspace_id === tr.workspace_id; });
              return `<article class="trend-card lift" data-open-trend="${tr.id}" tabindex="0" role="button">
                <div class="trend-card__top">
                  ${ui.sourceBadge(tr.source)}
                  <span class="trend-card__fresh mono">${ui.icon('clock')}${ui.esc(PL.fmt.ago(tr.freshness))}</span>
                </div>
                <h3>${ui.esc(tr.title)}</h3>
                <div class="trend-card__meta">
                  ${ui.velocityBadge(tr)}
                  ${ui.sparkbars(tr.spark)}
                  ${ui.faceBadge(tr.recommended_format)}
                </div>
                <p class="trend-card__angle">${ui.esc(tr.angle)}</p>
                <div class="trend-card__foot">
                  <span class="trend-card__niche mono">${ui.esc(tr.niche)}</span>
                  ${hasIdea
                    ? `<button class="btn btn--ghost btn--sm" data-create-from="${tr.id}">${ui.icon('check')}${ui.esc(t('trends.ideaExists'))}</button>`
                    : `<button class="btn btn--primary btn--sm" data-create-from="${tr.id}">${ui.icon('plus')}${ui.esc(t('trends.create'))}</button>`}
                </div>
              </article>`;
            }).join(''))}
        </div>
        ${ui.note(t('trends.note'))}`;

      var grid = el.querySelector('.trend-grid');
      if (grid) PL.motion.stagger(grid);

      el.querySelectorAll('[data-niche]').forEach(function (b) {
        b.addEventListener('click', function () {
          PL.state.trendsNiche[ws.id] = b.getAttribute('data-niche');
          PL.App.rerender();
        });
      });

      /* create button delegates to the shared action (anti-slop dedupe lives there);
         clicking the card itself opens the trend drawer */
      el.querySelectorAll('[data-create-from]').forEach(function (b) {
        b.addEventListener('click', function (e) {
          e.stopPropagation();
          PL.actions.createFromTrend(b.getAttribute('data-create-from'));
        });
      });
      el.querySelectorAll('[data-open-trend]').forEach(function (card) {
        card.addEventListener('click', function (e) {
          if (e.target.closest('button')) return;
          PL.drawers.openTrend(card.getAttribute('data-open-trend'));
        });
        card.addEventListener('keydown', function (e) {
          if ((e.key === 'Enter' || e.key === ' ') && !e.target.closest('button')) {
            e.preventDefault();
            PL.drawers.openTrend(card.getAttribute('data-open-trend'));
          }
        });
      });

      /* live: velocity scores tick in place */
      PL.App.onLive('trend_tick', function (evt) {
        var tr = evt.payload.trend;
        var chip = el.querySelector('[data-live="trend:' + tr.id + '"]');
        if (chip) {
          var arrow = tr.velocity === 'cooling' ? '▼' : tr.velocity === 'steady' ? '–' : '▲';
          chip.innerHTML = arrow + ' <span class="num">' + tr.velocity_score + '</span>&nbsp;' + ui.esc(t('velocity.' + tr.velocity));
        }
      });
    }
  };
})();
