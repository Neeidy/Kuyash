/* Screen 6 — Render Queue: live pipeline jobs (progress advances via ticker),
   approval queue with truthful records, renders with compliance results. */
(function () {
  'use strict';
  var PL = window.Kuyash, ui = PL.ui, store = PL.store, t = PL.t;

  var JOB_ICONS = {
    trend_fetch: 'radar', idea_generation: 'sparkle', script_draft: 'studio', tts: 'play',
    asset_fetch: 'library', assembly: 'workflow', compliance_check: 'shield',
    render_review: 'eye', publish: 'rocket', ai_video_generation: 'sparkle'
  };

  /* one source for the row markup so live patches rebuild it completely
     (status chip, error line and Retry appear in place — no re-navigation) */
  function jobRowInner(j) {
    return `<span class="job-row__icon">${ui.icon(JOB_ICONS[j.type] || 'list')}</span>
      <div class="job-row__main">
        <span class="job-row__type mono">${ui.esc(j.type)} <span class="faint">${ui.esc(j.id)}</span></span>
        <span class="job-row__entity">${ui.esc(j.entity || '')}</span>
        ${j.error_key ? `<span class="job-row__error">${ui.icon('warning')}${ui.esc(t(j.error_key, j.error_params || {}))} (${ui.esc(t('queue.retryCount', { r: j.retry_count, max: j.max_retries }))})</span>` : ''}
        ${j.note_key ? `<span class="job-row__note">${ui.esc(t(j.note_key, j.note_params || {}))}</span>` : ''}
      </div>
      ${j.status === 'processing' && j.progress != null ? ui.progressBar(j.progress, j.id) : ''}
      <span data-live="jobstatus:${j.id}">${ui.statusBadge(j.status)}</span>
      ${j.status === 'failed' ? `<button class="btn btn--ghost btn--sm" data-retry-job="${j.id}">${ui.icon('refresh')}${ui.esc(t('common.retry'))}</button>` : ''}`;
  }

  PL.screens.queue = {
    id: 'queue', icon: 'queue',

    render: function (el) {
      var ws = store.workspace();
      var mode = store.approvalMode();
      var jobs = store.byWorkspace('jobs');
      var renders = store.byWorkspace('renders');
      var awaiting = renders.filter(function (r) { return r.status === 'awaiting_approval'; });
      var killed = PL.state.killSwitch[ws.id];

      var stateHtml = ui.resolveUiState('queue',
        { icon: 'queue', title: t('queue.empty_t'), body: t('queue.empty_b'),
          cta: { label: t('cta.create'), nav: 'create', icon: 'plus' } },
        { title: t('queue.error_t'), body: t('queue.error_b') });

      el.innerHTML = `
        <header class="screen-head">
          <div><h1 class="vt-page-title">${ui.esc(t('nav.queue'))}</h1>
          <p class="screen-sub">${ui.esc(t('queue.sub', { ws: ws.name }))}</p></div>
          ${ui.uiStateBar('queue')}
        </header>

        ${killed ? `<div class="callout callout--err callout--banner">${ui.icon('warning')}
          <div><strong>${ui.esc(t('queue.killBanner'))}</strong></div></div>` : ''}

        <div class="callout callout--info callout--banner">
          ${ui.icon(mode === 'manual' ? 'users' : 'shield')}
          <div><strong>${ui.esc(t(mode === 'manual' ? 'queue.modeManual_t' : 'queue.modeAuto_t'))}</strong><br>
          ${ui.esc(t(mode === 'manual' ? 'queue.modeManual_b' : 'queue.modeAuto_b'))}</div>
          <button class="btn btn--ghost btn--sm" data-nav="settings">${ui.esc(t('queue.changeSettings'))}</button>
        </div>

        ${stateHtml ? stateHtml : `
        ${ui.card({
          title: t('queue.secJobs'),
          body: `<ul class="job-list stagger">
            ${jobs.map(function (j) {
              return `<li class="job-row" data-live="jobrow:${j.id}" data-open-job="${j.id}" tabindex="0" role="button">${jobRowInner(j)}</li>`;
            }).join('') || '<li class="muted">' + ui.esc(t('queue.noJobs')) + '</li>'}
          </ul>`
        })}

        ${ui.card({
          title: t('queue.secApprovals'),
          chip: `<span class="chip chip--${awaiting.length ? 'warn' : 'ok'} mono">${ui.esc(t('queue.waiting', { n: awaiting.length }))}</span>`,
          action: ui.kbdHint(),
          body: awaiting.length === 0
            ? `<p class="muted">${ui.esc(t('queue.noneWaiting'))} ${mode === 'auto' && !killed ? ui.esc(t('queue.autoApproving')) : ''}</p>`
            : `<div class="approve-list stagger" data-kbd-zone>${awaiting.map(function (r) {
                /* kill switch gates ALL automation — nothing auto-approves while paused */
                var autoEligible = mode === 'auto' && r.risk === 'low' && !killed;
                return `<article class="approve-card" data-render-row="${r.id}" data-open-render="${r.id}" tabindex="0" role="button">
                  ${ui.thumb(r.thumb)}
                  <div class="approve-card__main">
                    <h3>${ui.esc(r.title)}</h3>
                    <div class="approve-card__meta">
                      <span class="chip chip--neutral mono">${r.duration_s}s</span>
                      ${r.ai_label ? ui.aiLabelTag() : ''}
                      ${ui.complianceBadge(r.compliance)}
                    </div>
                    ${r.compliance && r.compliance.note_key ? `<p class="approve-card__note">${ui.complianceNote(r.compliance)}</p>` : ''}
                    ${r.compliance ? ui.whyCompliance(r.compliance) : ''}
                    ${mode === 'auto' && r.risk !== 'low' ? `<p class="approve-card__note text-warn">${ui.esc(t('queue.flagged'))}</p>` : ''}
                  </div>
                  <div class="approve-card__actions">
                    ${autoEligible
                      ? `<span class="muted">${ui.esc(t('queue.willAuto'))}</span>`
                      : `<button class="btn btn--primary btn--sm" data-approve="${r.id}">${ui.icon('check')}${ui.esc(t('common.approve'))}</button>
                         <button class="btn btn--danger-ghost btn--sm" data-reject="${r.id}">${ui.icon('x')}${ui.esc(t('common.reject'))}</button>`}
                  </div>
                </article>`;
              }).join('')}</div>`
        })}

        ${ui.card({
          title: t('queue.secRenders'),
          body: `<div class="render-grid stagger">
            ${renders.map(function (r) {
              return `<article class="render-card ${r.status === 'blocked' ? 'render-card--blocked' : ''} lift" data-open-render="${r.id}" tabindex="0" role="button">
                ${ui.thumb(r.thumb, r.duration_s + 's', true)}
                <div class="render-card__body">
                  <h3>${ui.esc(r.title)}</h3>
                  <div class="render-card__meta">
                    ${ui.statusBadge(r.status)}
                    ${r.ai_label ? ui.aiLabelTag() : ''}
                  </div>
                  ${r.status === 'processing' && r.progress != null ? ui.progressBar(r.progress, 'rn-' + r.id) : ''}
                  ${ui.complianceBadge(r.compliance)}
                  ${r.approval ? '<div class="render-card__approval">' + ui.approvalBadge(r.approval) + '</div>' : ''}
                  ${r.status === 'blocked'
                    ? `<p class="approve-card__note text-err">${ui.complianceNote(r.compliance)}</p>
                       <button class="btn btn--ghost btn--sm" data-to-studio="${r.id}">${ui.icon('studio')}${ui.esc(t('queue.backToStudio'))}</button>`
                    : `<button class="btn btn--ghost btn--sm" data-preview="${r.id}">${ui.icon('phone')}${ui.esc(t('queue.openPreview'))}</button>`}
                </div>
              </article>`;
            }).join('') || '<p class="muted">' + ui.esc(t('queue.noRenders')) + '</p>'}
          </div>`
        })}`}`;

      el.querySelectorAll('.stagger').forEach(PL.motion.stagger);

      /* delegation so retry buttons injected by live patches stay wired;
         clicking the row itself opens the job drawer */
      var jobList = el.querySelector('.job-list');
      if (jobList) {
        jobList.addEventListener('click', function (e) {
          var b = e.target.closest('[data-retry-job]');
          if (b) { PL.actions.retryJob(b.getAttribute('data-retry-job')); return; }
          var row = e.target.closest('[data-open-job]');
          if (row) PL.drawers.openJob(row.getAttribute('data-open-job'));
        });
        jobList.addEventListener('keydown', function (e) {
          if (e.key !== 'Enter' && e.key !== ' ') return;
          var row = e.target.closest('[data-open-job]');
          if (row && !e.target.closest('button')) { e.preventDefault(); PL.drawers.openJob(row.getAttribute('data-open-job')); }
        });
      }

      /* approve choreography: truthful record → check-flash → FLIP-exit → rerender */
      el.querySelectorAll('[data-approve]').forEach(function (b) {
        b.addEventListener('click', function (e) {
          e.stopPropagation();
          PL.actions.approveRender(b.getAttribute('data-approve'), b.closest('[data-render-row]'));
        });
      });

      el.querySelectorAll('[data-reject]').forEach(function (b) {
        b.addEventListener('click', function (e) {
          e.stopPropagation();
          PL.actions.rejectRender(b.getAttribute('data-reject'));
        });
      });

      /* cards open the Detail Drawer (buttons and the "Why?" disclosure excluded —
         expanding the disclosure must not bury it under the drawer) */
      el.querySelectorAll('[data-open-render]').forEach(function (card) {
        card.addEventListener('click', function (e) {
          if (e.target.closest('button') || e.target.closest('details')) return;
          PL.drawers.openRender(card.getAttribute('data-open-render'));
        });
        card.addEventListener('keydown', function (e) {
          if ((e.key === 'Enter' || e.key === ' ') && !e.target.closest('button') && !e.target.closest('details')) {
            e.preventDefault();
            PL.drawers.openRender(card.getAttribute('data-open-render'));
          }
        });
      });

      el.querySelectorAll('[data-preview]').forEach(function (b) {
        b.addEventListener('click', function (e) {
          PL.state.previewRenderId = b.getAttribute('data-preview');
          /* shared-element morph: tag the source thumb before navigating */
          var card = e.currentTarget.closest('.render-card');
          var th = card && card.querySelector('.thumb');
          if (th) th.classList.add('vt-media-hero');
          location.hash = '#/preview';
        });
      });
      el.querySelectorAll('[data-to-studio]').forEach(function (b) {
        b.addEventListener('click', function () {
          PL.state.studioTab = 'script';
          ui.toast(t('toast.blockedRouted'), 'info');
          location.hash = '#/studio';
        });
      });

      /* live: progress bars advance, statuses flip in place */
      PL.App.onLive('*', function (evt) {
        if (evt.type === 'job_progress') {
          var p = el.querySelector('[data-live="progress:' + evt.payload.job.id + '"]');
          if (p) {
            p.querySelector('.progress__fill').style.width = evt.payload.pct + '%';
            p.querySelector('.progress__num').textContent = evt.payload.pct + '%';
          }
          return;
        }
        if (evt.type === 'job_done' || evt.type === 'job_failed') {
          /* rebuild the whole row in place: chip flips, error line + Retry appear */
          var j = evt.payload.job;
          var row = el.querySelector('[data-live="jobrow:' + j.id + '"]');
          if (row) row.innerHTML = jobRowInner(j);
        }
      });
    }
  };
})();
