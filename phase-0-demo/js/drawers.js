/* Kuyash Phase 0 — global Detail Drawer content builders (iteration 2 §C)
   + the shared row actions they trigger. One slide-over pattern for
   renders/jobs, trends, accounts and assets — replaces ad-hoc detail panels.
   All records stay truthful: approvals write label_key, never re-derived. */
(function () {
  'use strict';
  var PL = window.Kuyash, ui = PL.ui, store = PL.store, t = PL.t;

  var HEALTH_TONE = { healthy: 'ok', token_expiring: 'warn', reconnect_required: 'err' };

  /* ---------- shared actions (used by rows, keyboard flow and drawers) ---------- */
  PL.actions = {

    /* Approve with choreography: check-flash on the row → FLIP-exit → rerender.
       The truthful record is written FIRST — animation is presentation only. */
    approveRender: function (id, row) {
      var r = store.find('renders', id);
      if (!r || r.status !== 'awaiting_approval') return;
      r.status = 'ready';
      r.approval = {
        mode: 'manual', label_key: 'badge.approvedByYou',
        by: PL.state.data.meta.demo_user.email, at: { day: 'now' }
      };
      store.audit('log.render_approved', { job: r.id, render: r.id, by: r.approval.by });
      ui.toast(t('toast.approved'), 'success');
      if (row && row.isConnected) {
        PL.motion.flash(row, function () {
          PL.motion.flipRemove(row, function () { PL.App.rerender(); });
        });
      } else {
        PL.App.rerender();
      }
    },

    rejectRender: function (id) {
      var r = store.find('renders', id);
      if (!r || r.status !== 'awaiting_approval') return;
      ui.confirm({
        title: t('dlg.reject_t'),
        body: ui.esc(t('dlg.reject_b', { title: r.title })),
        confirmLabel: t('dlg.reject_ok'), danger: true,
        onConfirm: function () {
          r.status = 'cancelled';
          store.audit('log.render_rejected', { render: r.id, by: PL.state.data.meta.demo_user.email });
          ui.toast(t('toast.rejected'), 'info');
          PL.App.rerender();
        }
      });
    },

    retryJob: function (id) {
      var j = store.find('jobs', id);
      if (!j) return;
      j.status = 'queued'; j.retry_count += 1; j.error_key = null; j.error_params = null;
      j.note_key = 'job.note_retry_idem';
      store.logKeyed('log.job_retried', { type: j.type, job: j.id, r: j.retry_count, max: j.max_retries }, 'info', 'transition', j.id);
      ui.toast(t('toast.retried'), 'success');
      PL.App.rerender();
    },

    /* anti-slop: one trend, one idea — no template-identical duplicates */
    createFromTrend: function (id) {
      var tr = store.find('trends', id);
      if (!tr) return;
      var existing = PL.state.data.ideas.find(function (i) {
        return i.trend_id === tr.id && i.workspace_id === tr.workspace_id;
      });
      if (existing) {
        ui.toast(t('toast.ideaExists'), 'info');
      } else {
        PL.state.data.ideas.unshift({
          id: 'id_x' + Math.random().toString(36).slice(2, 6),
          workspace_id: tr.workspace_id, trend_id: tr.id, status: 'draft',
          title: tr.title, hook: '—',
          score: { total: tr.velocity_score, velocity: tr.velocity_score, fit: 80, novelty: 75 }
        });
        store.logKeyed('log.idea_from_trend', { title: tr.title });
        ui.toast(t('toast.ideaCreated'), 'success');
      }
      PL.state.studioTab = 'ideas';
      location.hash = '#/studio';
    },

    reconnectAccount: function (id) {
      var a = store.find('accounts', id);
      if (!a) return;
      ui.confirm({
        title: t('dlg.oauth_t'),
        body: ui.esc(t('dlg.oauth_asking')) + '<ul class="stat-list" style="margin-top:8px">' +
          '<li>' + ui.icon('check') + ui.esc(t('dlg.oauth_s1')) + '</li>' +
          '<li>' + ui.icon('check') + ui.esc(t('dlg.oauth_s2')) + '</li></ul>',
        detail: ui.esc(t('dlg.oauth_note')),
        confirmLabel: t('dlg.oauth_ok'),
        onConfirm: function () {
          a.status = 'connected'; a.health = 'healthy'; a.warn_key = null;
          if (a.health_history && a.health_history.length) {
            a.health_history[a.health_history.length - 1].status = 'healthy';
          }
          store.logKeyed('log.account_reconnected', { handle: a.handle });
          ui.toast(t('toast.reconnected', { handle: a.handle }), 'success');
          PL.App.rerender();
        }
      });
    }
  };

  /* ---------- small shared fragments ---------- */
  function subhead(icon, key) {
    return '<h4 class="drawer__subhead">' + ui.icon(icon) + ui.esc(t(key)) + '</h4>';
  }

  function auditBlock(renderId) {
    var decision = PL.state.data.compliance_decisions.find(function (d) { return d.render_id === renderId; });
    var logs = store.byWorkspace('logs').filter(function (l) {
      return l.params && l.params.render === renderId;
    }).slice(0, 5);
    if (!decision && !logs.length) {
      return subhead('shield', 'drawer.audit') + '<p class="faint" style="font-size:12px">' + ui.esc(t('drawer.noAudit')) + '</p>';
    }
    return subhead('shield', 'drawer.audit') +
      (decision
        ? '<dl class="kv-list">' +
          ui.kv(t('logs.dResult'), ui.esc(decision.result)) +
          ui.kv(t('logs.dSlop'), ui.esc(PL.fmt.pct(decision.slop_score))) +
          ui.kv(t('logs.dPolicy'), ui.esc(decision.policy_version)) +
          ui.kv(t('logs.dAt'), ui.esc(PL.fmt.when(decision.at))) + '</dl>'
        : '') +
      (logs.length
        ? '<div class="term term--flat"><div class="term__body">' +
          logs.map(function (l) { return ui.termLine(l); }).join('') + '</div></div>'
        : '');
  }

  /* compliance "Why?" comes from the shared component (ui.whyCompliance) */

  /* ---------- drawer builders ---------- */
  PL.drawers = {

    openRender: function (id) {
      var r = store.find('renders', id);
      if (!r) return;
      var script = r.script_id ? store.find('scripts', r.script_id) : null;
      var awaiting = r.status === 'awaiting_approval';

      var body =
        subhead('clock', 'drawer.timeline') + ui.timeline(r.timeline) +
        (r.compliance
          ? subhead('shield', 'drawer.checks') + ui.checkBars(r.compliance.checks_run) +
            (r.compliance.note_key ? '<p class="drawer__note">' + ui.complianceNote(r.compliance) + '</p>' : '') +
            ui.whyCompliance(r.compliance)
          : '') +
        (script
          ? subhead('studio', 'drawer.linked') +
            '<p class="drawer__quote">“' + ui.esc(script.hook) + '”</p>' +
            '<dl class="kv-list">' +
            ui.kv(t('studio.script'), ui.esc(script.id)) +
            ui.kv(t('studio.duration'), r.duration_s + 's') + '</dl>'
          : '') +
        auditBlock(r.id);

      var actions =
        '<button class="btn btn--ghost btn--sm" data-dw-preview="' + ui.esc(r.id) + '">' + ui.icon('phone') + ui.esc(t('queue.openPreview')) + '</button>' +
        (awaiting
          ? '<button class="btn btn--danger-ghost btn--sm" data-dw-reject="' + ui.esc(r.id) + '">' + ui.icon('x') + ui.esc(t('common.reject')) + '</button>' +
            '<button class="btn btn--primary btn--sm" data-dw-approve="' + ui.esc(r.id) + '">' + ui.icon('check') + ui.esc(t('common.approve')) + '</button>'
          : '') +
        (r.status === 'blocked'
          ? '<button class="btn btn--ghost btn--sm" data-dw-studio>' + ui.icon('studio') + ui.esc(t('queue.backToStudio')) + '</button>'
          : '');

      var d = ui.detailDrawer({
        title: r.title,
        subtitle: r.id + ' · ' + r.duration_s + 's · 9:16',
        chips: ui.statusBadge(r.status) + (r.ai_label ? ui.aiLabelTag() : '') +
          ui.complianceBadge(r.compliance) + (r.approval ? ui.approvalBadge(r.approval) : ''),
        body: body,
        actions: actions
      });

      var ap = d.querySelector('[data-dw-approve]');
      if (ap) ap.addEventListener('click', function () {
        ui.closeModal();
        /* choreography plays on the row behind the closing drawer, if visible */
        var row = document.querySelector('[data-render-row="' + r.id + '"]');
        PL.actions.approveRender(r.id, row);
      });
      var rj = d.querySelector('[data-dw-reject]');
      if (rj) rj.addEventListener('click', function () {
        ui.closeModal();
        PL.actions.rejectRender(r.id);
      });
      var pv = d.querySelector('[data-dw-preview]');
      if (pv) pv.addEventListener('click', function () {
        ui.closeModal();
        PL.state.previewRenderId = r.id;
        location.hash = '#/preview';
      });
      var st = d.querySelector('[data-dw-studio]');
      if (st) st.addEventListener('click', function () {
        ui.closeModal();
        PL.state.studioTab = 'script';
        ui.toast(t('toast.blockedRouted'), 'info');
        location.hash = '#/studio';
      });
    },

    openJob: function (id) {
      var j = store.find('jobs', id);
      if (!j) return;
      var renderId = (String(j.entity || '').match(/rn_\d+/) || [null])[0];
      var render = renderId ? store.find('renders', renderId) : null;

      var body =
        '<dl class="kv-list">' +
        ui.kv(t('logs.dEntity'), ui.esc(j.entity || '—')) +
        ui.kv(t('logs.dRetries'), j.retry_count + ' / ' + j.max_retries) +
        ui.kv(t('logs.dStarted'), ui.esc(j.started ? PL.fmt.when(j.started) : '—')) +
        ui.kv(t('logs.dFinished'), ui.esc(j.finished ? PL.fmt.when(j.finished) : '—')) +
        ui.kv(t('logs.dCost'), j.cost_cents != null ? '$' + (j.cost_cents / 100).toFixed(2) : '—') +
        ui.kv(t('logs.dIdem'), ui.esc(j.idempotency_key || '—')) +
        '</dl>' +
        (j.error_key ? '<div class="callout callout--err">' + ui.icon('warning') + '<div>' + ui.esc(t(j.error_key, j.error_params || {})) + '</div></div>' : '') +
        (j.note_key ? ui.note(t(j.note_key, j.note_params || {})) : '') +
        (render && render.timeline ? subhead('clock', 'drawer.timeline') + ui.timeline(render.timeline) : '') +
        (render ? auditBlock(render.id) : '');

      var d = ui.detailDrawer({
        title: t('logs.job', { id: j.id }),
        subtitle: j.type + (renderId ? ' · ' + renderId : ''),
        chips: ui.statusBadge(j.status),
        body: body,
        actions: j.status === 'failed'
          ? '<button class="btn btn--ghost btn--sm" data-dw-retry="' + ui.esc(j.id) + '">' + ui.icon('refresh') + ui.esc(t('common.retry')) + '</button>'
          : ''
      });

      var rb = d.querySelector('[data-dw-retry]');
      if (rb) rb.addEventListener('click', function () {
        ui.closeModal();
        PL.actions.retryJob(j.id);
      });
    },

    openTrend: function (id) {
      var tr = store.find('trends', id);
      if (!tr) return;
      var hasIdea = PL.state.data.ideas.some(function (i) {
        return i.trend_id === tr.id && i.workspace_id === tr.workspace_id;
      });

      var body =
        subhead('chart', 'drawer.velocityHist') +
        '<div class="drawer__spark">' + ui.sparkbars(tr.spark, 'spark--lg') +
        '<b class="mono num">' + tr.velocity_score + '</b></div>' +
        subhead('eye', 'drawer.recFormat') +
        '<p class="drawer__note">' + ui.esc(t('why.recFormat', { format: t(tr.recommended_format === 'face' ? 'badge.face' : 'badge.faceless') })) + '</p>' +
        '<p class="drawer__quote">' + ui.esc(tr.angle) + '</p>';

      var d = ui.detailDrawer({
        title: tr.title,
        subtitle: tr.id + ' · ' + tr.niche + ' · ' + PL.fmt.ago(tr.freshness),
        chips: ui.sourceBadge(tr.source) + ui.velocityBadge(tr) + ui.faceBadge(tr.recommended_format),
        body: body,
        actions: '<button class="btn btn--primary btn--sm" data-dw-create="' + ui.esc(tr.id) + '">' +
          ui.icon(hasIdea ? 'check' : 'plus') + ui.esc(t(hasIdea ? 'trends.ideaExists' : 'trends.create')) + '</button>'
      });

      d.querySelector('[data-dw-create]').addEventListener('click', function () {
        ui.closeModal();
        PL.actions.createFromTrend(tr.id);
      });
    },

    openAccount: function (id) {
      var a = store.find('accounts', id);
      if (!a) return;

      var body =
        (a.warn_key ? '<div class="callout callout--' + (a.status === 'error' ? 'err' : 'warn') + '">' +
          ui.icon('warning') + '<div>' + ui.esc(t(a.warn_key)) + '</div></div>' : '') +
        subhead('queue', 'drawer.todayCap') +
        '<p class="mono num drawer__note">' + ui.esc(t('accounts.postsToday', { used: a.posts_today, cap: a.daily_cap })) + '</p>' +
        ui.meter(a.posts_today, a.daily_cap) +
        subhead('chart', 'drawer.healthHist') +
        '<div class="hh-row">' + (a.health_history || []).map(function (h) {
          return '<span class="hh-day" title="' + ui.esc(PL.fmt.when({ day: h.day })) + '">' +
            ui.dot(HEALTH_TONE[h.status] || 'neutral') +
            '<small>' + ui.esc(PL.fmt.when({ day: h.day })) + '</small></span>';
        }).join('') + '</div>' +
        subhead('rocket', 'drawer.recentPosts') +
        '<ul class="post-list">' + (a.recent_posts || []).map(function (p) {
          return '<li><span class="post-list__title">' + ui.esc(p.title) + '</span>' +
            '<span class="post-list__when mono">' + ui.esc(PL.fmt.when(p.at)) + '</span>' +
            ui.statusBadge(p.status) + '</li>';
        }).join('') + '</ul>';

      var d = ui.detailDrawer({
        title: a.handle,
        subtitle: a.platform + ' · ' + t('accounts.connectedAt', { date: PL.fmt.when(a.connected_at) }),
        chips: ui.statusBadge(a.status),
        body: body,
        actions: a.status !== 'connected'
          ? '<button class="btn btn--primary btn--sm" data-dw-reconnect="' + ui.esc(a.id) + '">' + ui.icon('refresh') + ui.esc(t('accounts.reconnect')) + '</button>'
          : ''
      });

      var rc = d.querySelector('[data-dw-reconnect]');
      if (rc) rc.addEventListener('click', function () {
        ui.closeModal();
        PL.actions.reconnectAccount(a.id);
      });
    },

    openAsset: function (id) {
      var a = store.find('assets', id);
      if (!a) return;
      var used = (a.used_in || []).map(function (rid) { return store.find('renders', rid); }).filter(Boolean);

      var body =
        '<dl class="kv-list">' +
        ui.kv(t('studio.duration'), a.duration_s + 's') +
        ui.kv(t('drawer.aspect'), ui.esc(a.aspect)) +
        ui.kv('#', a.tags.map(ui.esc).join(', ')) +
        '</dl>' +
        (a.aspect !== '9:16' ? '<div class="callout callout--warn">' + ui.icon('warning') + '<div>' + ui.esc(t('library.formatWarn')) + '</div></div>' : '') +
        (a.ai_label_required ? '<div class="callout callout--ai">' + ui.icon('sparkle') + '<div>' + ui.esc(t('studio.qcAi_b')) + '</div></div>' : '') +
        subhead('queue', 'drawer.usedIn') +
        (used.length
          ? '<ul class="post-list">' + used.map(function (r) {
              return '<li data-dw-render="' + ui.esc(r.id) + '" tabindex="0" role="button">' +
                '<span class="post-list__title">' + ui.esc(r.title) + '</span>' +
                '<span class="post-list__when mono">' + ui.esc(r.id) + '</span>' +
                ui.statusBadge(r.status) + '</li>';
            }).join('') + '</ul>'
          : '<p class="faint" style="font-size:12px">' + ui.esc(t('drawer.notUsed')) + '</p>');

      var d = ui.detailDrawer({
        title: a.title,
        subtitle: a.id + ' · ' + a.type + ' · ' + a.duration_s + 's',
        chips: '<span class="chip ' + (a.type === 'ai' ? 'chip--ai' : 'chip--neutral') + '">' +
          ui.esc(t('library.t' + a.type.charAt(0).toUpperCase() + a.type.slice(1))) + '</span>' +
          ui.statusBadge(a.status),
        body: body
      });

      d.querySelectorAll('[data-dw-render]').forEach(function (li) {
        li.addEventListener('click', function () {
          PL.drawers.openRender(li.getAttribute('data-dw-render'));
        });
      });
    }
  };
})();
