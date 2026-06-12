/* Screen 9 — Live Logs / Jobs: streaming terminal pane (pause/resume, FLIP),
   filters, job drawer with compliance audit. */
(function () {
  'use strict';
  var PL = window.Kuyash, ui = PL.ui, store = PL.store, t = PL.t;

  var FILTERS = [['all', 'logs.fAll'], ['info', 'logs.fInfo'], ['warn', 'logs.fWarn'], ['error', 'logs.fError'], ['compliance', 'logs.fCompliance']];

  function matches(log, filter) {
    if (filter === 'all') return true;
    if (filter === 'compliance') return log.kind === 'compliance' || log.kind === 'guardrail';
    return log.level === filter;
  }

  /* job details now open the global Detail Drawer (drawers.js) — the old
     ad-hoc kv drawer is gone; retry lives in PL.actions. */

  PL.screens.logs = {
    id: 'logs', icon: 'list',

    render: function (el) {
      var filter = PL.state.logsFilter;
      var logs = store.byWorkspace('logs').filter(function (l) { return matches(l, filter); });
      var paused = PL.live.isPaused();

      var stateHtml = ui.resolveUiState('logs',
        { icon: 'list', title: t('logs.empty_t'), body: t('logs.empty_b'),
          cta: { label: t('dash.openQueue'), nav: 'queue', icon: 'queue' } },
        { title: t('logs.error_t'), body: t('logs.error_b') });

      el.innerHTML = `
        <header class="screen-head">
          <div><h1 class="vt-page-title">${ui.esc(t('nav.logs'))}</h1>
          <p class="screen-sub">${ui.esc(t('logs.sub'))}</p></div>
          ${ui.uiStateBar('logs')}
        </header>

        <div class="chip-row">
          ${FILTERS.map(function (f) {
            return `<button class="fchip ${filter === f[0] ? 'is-active' : ''}" data-logfilter="${f[0]}">${ui.esc(t(f[1]))}</button>`;
          }).join('')}
        </div>

        ${stateHtml ? stateHtml :
          ui.term({
            title: t('logs.term'), live: !paused, height: 'calc(100vh - 320px)', cls: 'term--page',
            controls: `<button class="btn btn--ghost btn--xs" data-stream-toggle>${paused ? ui.icon('play') : ui.icon('pauseIcon')}${ui.esc(t(paused ? 'common.resume' : 'common.pause'))}</button>`,
            /* caret sits ABOVE the feed (new lines prepend) and outside the
               FLIP container, so the row cap can never delete it */
            lines: '<div class="term__line">' + (paused ? '' : '<span class="term__caret"></span>') + '</div>' +
              '<div data-live="feed">' +
              (logs.length
                ? logs.map(function (l) { return ui.termLine(l); }).join('')
                : '<div class="term__line"><span class="term__msg">' + ui.esc(t('logs.noEntries')) + '</span></div>') +
              '</div>'
          })}
        ${ui.note(t('logs.note'))}`;

      el.querySelectorAll('[data-logfilter]').forEach(function (b) {
        b.addEventListener('click', function () {
          PL.state.logsFilter = b.getAttribute('data-logfilter'); PL.App.rerender();
        });
      });

      var toggleBtn = el.querySelector('[data-stream-toggle]');
      if (toggleBtn) toggleBtn.addEventListener('click', function () {
        if (PL.live.isPaused()) PL.live.resume(); else PL.live.pause();
        PL.App.rerender();
      });

      /* job drawer via delegation on terminal lines */
      var feed = el.querySelector('[data-live="feed"]');
      if (feed) {
        feed.addEventListener('click', function (e) {
          var row = e.target.closest('[data-job]');
          if (row) PL.drawers.openJob(row.getAttribute('data-job'));
        });
        feed.addEventListener('keydown', function (e) {
          var row = e.target.closest('[data-job]');
          if (row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); PL.drawers.openJob(row.getAttribute('data-job')); }
        });
      }

      /* live: FLIP-prepend new lines that match the active filter */
      PL.App.onLive('*', function (evt) {
        if (!feed || PL.live.isPaused()) return;
        if (evt.type !== 'job_done' && evt.type !== 'job_failed' && evt.type !== 'job_progress') return;
        if (evt.type === 'job_progress') return; /* progress doesn't log a line */
        var latest = store.byWorkspace('logs')[0];
        if (!latest || !matches(latest, PL.state.logsFilter)) return;
        var tmp = document.createElement('div');
        tmp.innerHTML = ui.termLine(latest);
        PL.motion.flipPrepend(feed, tmp.firstChild, 60);
      });
    }
  };
})();
