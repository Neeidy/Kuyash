/* Screen 1 — Mission-Control Dashboard (iteration 2 §A).
   Answers "what is my system doing right now" without a click:
   live KPI strip → active runs with per-stage progress → approval strip
   (9:16 minis) → account health → cost meter → live feed. Deliberately
   DENSE — the one screen allowed to break the whitespace-first rule. */
(function () {
  'use strict';
  var PL = window.Kuyash, ui = PL.ui, store = PL.store, t = PL.t;

  /* canonical pipeline stages ← job types */
  var STAGES = [
    ['TREND', ['trend_fetch']], ['IDEA', ['idea_generation']], ['SCRIPT', ['script_draft']],
    ['VOICE', ['tts']], ['VISUALS', ['asset_fetch']], ['ASSEMBLE', ['assembly', 'ai_video_generation']],
    ['COMPLIANCE', ['compliance_check']], ['REVIEW', ['render_review']], ['PUBLISH', ['publish']]
  ];
  var ACTIVE = ['queued', 'processing', 'awaiting_approval', 'awaiting_recording'];

  function stageIdx(type) {
    for (var i = 0; i < STAGES.length; i++) {
      if (STAGES[i][1].indexOf(type) !== -1) return i;
    }
    return 0;
  }

  function marquee(trends) {
    function chips(dup) {
      return trends.slice().sort(function (a, b) { return b.velocity_score - a.velocity_score; })
        .map(function (tr) {
          return '<button class="mq-chip" data-nav="trends"' + (dup ? ' tabindex="-1"' : '') + '>' +
            '<span class="mono num ' + (tr.velocity === 'cooling' ? 'faint' : 'text-ok') + '" data-live="mq:' + tr.id + '">' +
            (tr.velocity === 'cooling' ? '▼' : '▲') + tr.velocity_score + '</span>' +
            '<span class="mq-chip__title">' + ui.esc(tr.title) + '</span>' +
            '<span class="mq-chip__niche">' + ui.esc(tr.niche) + '</span></button>';
        }).join('');
    }
    /* the second half only exists for the seamless loop — hide it from a11y/tab order */
    return '<div class="marquee dash-marquee" aria-label="' + ui.esc(t('dash.marqueeAria')) + '">' +
      '<div class="marquee__track">' +
      '<div class="marquee__half">' + chips(false) + '</div>' +
      '<div class="marquee__half" aria-hidden="true">' + chips(true) + '</div>' +
      '</div></div>';
  }

  /* one source for the run-row markup so live patches rebuild it in place */
  function runRowInner(j) {
    var idx = stageIdx(j.type);
    var segs = STAGES.map(function (s, i) {
      var cls = i < idx ? 'is-done' : i === idx ? 'is-current' : '';
      return '<i class="' + cls + '" title="' + s[0] + '"></i>';
    }).join('');
    return '<span class="run-row__icon">' + ui.icon(j.status === 'failed' ? 'warning' : 'workflow') + '</span>' +
      '<div class="run-row__main">' +
      '  <strong>' + ui.esc(j.entity || j.type) + '</strong>' +
      '  <span class="run-row__stage mono">' + STAGES[idx][0] +
      (j.status === 'processing' && j.progress != null ? ' · <span class="num" data-run-pct>' + Math.round(j.progress * 100) + '%</span>' : '') +
      '  </span></div>' +
      '<div class="run-stages" aria-hidden="true">' + segs + '</div>' +
      '<span data-live="jobstatus:' + j.id + '">' + ui.statusBadge(j.status) + '</span>';
  }

  function kpiValues() {
    var jobs = store.byWorkspace('jobs');
    var renders = store.byWorkspace('renders');
    var accounts = store.byWorkspace('accounts');
    return {
      pub: accounts.reduce(function (s, a) { return s + a.posts_today; }, 0),
      pipe: jobs.filter(function (j) { return ACTIVE.indexOf(j.status) !== -1; }).length,
      wait: renders.filter(function (r) { return r.status === 'awaiting_approval'; }).length,
      fail: jobs.filter(function (j) { return j.status === 'failed'; }).length,
      credits: store.credits().balance
    };
  }

  PL.screens = PL.screens || {};

  PL.screens.dashboard = {
    id: 'dashboard', icon: 'home',

    render: function (el) {
      var ws = store.workspace();
      var mode = store.approvalMode();
      var renders = store.byWorkspace('renders');
      var jobs = store.byWorkspace('jobs');
      var logs = store.byWorkspace('logs').slice(0, 14);
      var cr = store.credits();
      var accounts = store.byWorkspace('accounts');
      var trends = store.byWorkspace('trends');

      var awaiting = renders.filter(function (r) { return r.status === 'awaiting_approval'; });
      var runs = jobs.filter(function (j) { return ACTIVE.indexOf(j.status) !== -1 || j.status === 'failed'; });
      var killed = PL.state.killSwitch[ws.id];
      var k = kpiValues();

      /* everything lives inside .dash-root so the delegated listeners below
         are discarded with the screen — never left behind on #screen-root */
      el.innerHTML = `<div class="dash-root">
        ${marquee(trends)}

        ${killed ? `<div class="callout callout--err callout--banner">${ui.icon('warning')}
          <div><strong>${ui.esc(t('guard.killOn'))}</strong></div>
          <button class="btn btn--ghost btn--sm" data-nav="settings">${ui.esc(t('guard.killCta'))}</button></div>` : ''}

        <div class="panel kpi-strip vt-page-title">
          ${ui.stat('dash.kpiPubToday', '<span data-kpi="pub">0</span>')}
          ${ui.stat('dash.kpiPipeline', '<span data-kpi="pipe">0</span>')}
          ${ui.stat('dash.kpiAwaiting', '<span data-kpi="wait">0</span>',
            null, { cls: k.wait ? 'stat--hot' : '' })}
          ${ui.stat('dash.kpiFailed', '<span data-kpi="fail" data-live="failedcount">0</span>',
            null, { cls: k.fail ? 'stat--err' : '' })}
          ${ui.stat('dash.kpiCredits', '<span data-kpi="credits">0</span><small> ' + ui.esc(t('usage.ofMonthly', { n: cr.monthly_allowance })) + '</small>')}
          <button class="btn btn--primary kpi-strip__cta" data-nav="queue">${ui.esc(t('dash.review'))}${ui.icon('chevR')}</button>
        </div>

        <div class="bento">
          ${ui.card({
            title: t('dash.activeRuns'), cls: 'bento__runs',
            chip: `<span class="chip chip--neutral mono" data-live="runcount">${runs.length}</span>`,
            action: `<button class="btn btn--ghost btn--xs" data-nav="queue">${ui.esc(t('dash.openQueue'))}</button>`,
            body: runs.length === 0
              ? `<div class="dash-empty"><p class="muted">${ui.esc(t('dash.noRuns'))}</p>
                 <button class="btn btn--primary btn--sm" data-nav="create">${ui.icon('plus')}${ui.esc(t('cta.create'))}</button></div>`
              : `<ul class="run-list stagger">${runs.map(function (j) {
                  return `<li class="run-row" data-live="run:${j.id}" data-open-job="${j.id}" tabindex="0" role="button">${runRowInner(j)}</li>`;
                }).join('')}</ul>`
          })}

          ${ui.card({
            title: t('dash.card_live'), cls: 'bento__live',
            chip: `<span class="chip chip--faint mono" data-live="feedstate">${ui.esc(t(PL.live.isPaused() ? 'dash.streamPaused' : 'dash.streaming'))}</span>`,
            action: `<button class="btn btn--ghost btn--xs" data-nav="logs">${ui.esc(t('dash.openLogs'))}</button>`,
            body: ui.term({
              title: t('logs.term'), live: !PL.live.isPaused(), height: '228px',
              bodyAttrs: 'data-live="feed"',
              lines: logs.map(ui.termLine).join('')
            })
          })}

          ${ui.card({
            title: t('dash.card_approvals'), cls: 'bento__approvals',
            chip: awaiting.length ? `<span class="chip chip--warn mono">${awaiting.length}</span>` : '',
            action: ui.kbdHint(),
            body: awaiting.length === 0
              ? `<p class="muted">${ui.esc(t('dash.approvalsEmpty'))}</p>`
              : `<div class="ap-strip stagger" data-kbd-zone>${awaiting.map(function (r) {
                  var autoEligible = mode === 'auto' && r.risk === 'low' && !killed;
                  return `<article class="ap-card lift" data-render-row="${r.id}" data-open-render="${r.id}" tabindex="0" role="button">
                    ${ui.thumb(r.thumb, r.duration_s + 's')}
                    <strong class="ap-card__title">${ui.esc(r.title)}</strong>
                    <span class="ap-card__meta">${ui.complianceBadge(r.compliance)}${r.ai_label ? ui.aiLabelTag() : ''}</span>
                    ${autoEligible
                      ? `<span class="muted ap-card__auto">${ui.esc(t('queue.willAuto'))}</span>`
                      : `<span class="ap-card__actions">
                          <button class="btn btn--primary btn--xs" data-approve="${r.id}">${ui.esc(t('common.approve'))}</button>
                          <button class="btn btn--danger-ghost btn--xs" data-reject="${r.id}">${ui.esc(t('common.reject'))}</button>
                        </span>`}
                  </article>`;
                }).join('')}</div>`
          })}

          ${ui.card({
            title: t('dash.accountsCard'), cls: 'bento__accounts',
            body: `<div class="acc-strip">${accounts.map(function (a) {
              var tone = a.health === 'healthy' ? 'ok' : a.health === 'token_expiring' ? 'warn' : 'err';
              return `<button class="acc-row" data-open-account="${a.id}">
                ${ui.platformIcon(a.platform)}
                <span class="acc-row__handle">${ui.esc(a.handle)}</span>
                ${ui.dot(tone)}
                <span class="mono num ${a.posts_today >= a.daily_cap ? 'text-err' : a.posts_today / a.daily_cap >= 0.75 ? 'text-warn' : 'faint'}">${a.posts_today}/${a.daily_cap}</span>
              </button>`;
            }).join('')}</div>`,
            footer: ui.icon('shield') + ui.esc(t(mode === 'manual' ? 'topbar.manual' : 'topbar.auto'))
          })}

          ${ui.card({
            title: t('dash.costCard'), cls: 'bento__cost',
            body: `
              <div class="guard-row"><span class="guard-row__label">${ui.esc(t('dash.costToday'))}</span>
                <span class="mono num" data-kpi="usedToday">0</span></div>
              <div class="guard-row"><span class="guard-row__label">${ui.esc(t('dash.costMonth'))}</span>
                <span class="mono num">${cr.used_this_month}/${cr.budget_cap}</span></div>
              ${ui.meter(cr.used_this_month, cr.budget_cap)}`,
            footer: ui.icon('credits') + ui.esc(t('dash.guardBudget'))
          })}
        </div>
      </div>`;

      var dashRoot = el.querySelector('.dash-root');
      el.querySelectorAll('.stagger').forEach(PL.motion.stagger);

      /* KPI count-ups: animate from 0 on load AND on every tick (iteration-2 §B) */
      var KPI_TARGETS = { pub: k.pub, pipe: k.pipe, wait: k.wait, fail: k.fail, credits: k.credits, usedToday: cr.used_today || 0 };
      Object.keys(KPI_TARGETS).forEach(function (key) {
        var n = el.querySelector('[data-kpi="' + key + '"]');
        if (n) PL.motion.countUp(n, KPI_TARGETS[key]);
      });

      /* row & card interactions → global Detail Drawer (bound to .dash-root,
         which dies with the screen) */
      dashRoot.addEventListener('click', function (e) {
        if (e.target.closest('button[data-approve],button[data-reject],[data-nav]')) return;
        var run = e.target.closest('[data-open-job]');
        if (run) { PL.drawers.openJob(run.getAttribute('data-open-job')); return; }
        var ap = e.target.closest('[data-open-render]');
        if (ap) { PL.drawers.openRender(ap.getAttribute('data-open-render')); return; }
        var ac = e.target.closest('[data-open-account]');
        if (ac) PL.drawers.openAccount(ac.getAttribute('data-open-account'));
      });
      dashRoot.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var row = e.target.closest('[data-open-job],[data-open-render]');
        if (!row || e.target.closest('button')) return;
        e.preventDefault();
        if (row.hasAttribute('data-open-job')) PL.drawers.openJob(row.getAttribute('data-open-job'));
        else PL.drawers.openRender(row.getAttribute('data-open-render'));
      });

      el.querySelectorAll('[data-approve]').forEach(function (b) {
        b.addEventListener('click', function () {
          var card = b.closest('[data-render-row]');
          PL.actions.approveRender(b.getAttribute('data-approve'), card);
        });
      });
      el.querySelectorAll('[data-reject]').forEach(function (b) {
        b.addEventListener('click', function () {
          PL.actions.rejectRender(b.getAttribute('data-reject'));
        });
      });

      /* ---- live bindings (targeted DOM patches; never a full re-render) ---- */
      function patchKpis() {
        var now = kpiValues();
        ['pub', 'pipe', 'wait', 'fail', 'credits'].forEach(function (key) {
          var n = el.querySelector('[data-kpi="' + key + '"]');
          if (n && parseFloat(n.dataset.countVal || '-1') !== now[key]) PL.motion.countUp(n, now[key]);
        });
      }

      PL.App.onLive('*', function (evt) {
        if (evt.type === 'job_progress') {
          var j = evt.payload.job;
          var row = el.querySelector('[data-live="run:' + j.id + '"]');
          if (row) row.innerHTML = runRowInner(j);
          patchKpis();
          return;
        }
        if (evt.type === 'job_done' || evt.type === 'job_failed') {
          var job = evt.payload.job;
          var rrow = el.querySelector('[data-live="run:' + job.id + '"]');
          var rcChip = el.querySelector('[data-live="runcount"]');
          if (rrow) {
            if (evt.type === 'job_done') {
              /* finished runs leave the monitor — FLIP the list closed */
              PL.motion.flipRemove(rrow, function () {
                if (rcChip) rcChip.textContent = String(el.querySelectorAll('.run-row').length);
              });
            } else {
              rrow.innerHTML = runRowInner(job);
            }
          }
          var feed = el.querySelector('[data-live="feed"]');
          var latest = store.byWorkspace('logs')[0];
          if (feed && latest) {
            var line = document.createElement('div');
            line.innerHTML = ui.termLine(latest);
            PL.motion.flipPrepend(feed, line.firstChild, 30);
          }
          patchKpis();
          return;
        }
        if (evt.type === 'trend_tick') {
          var trd = evt.payload.trend;
          el.querySelectorAll('[data-live="mq:' + trd.id + '"]').forEach(function (c) {
            c.textContent = (trd.velocity === 'cooling' ? '▼' : '▲') + trd.velocity_score;
          });
        }
      });
    }
  };
})();
