/* Kuyash Phase 0 — shared UI: icons, badges, card anatomy, terminal blocks,
   dialogs, toasts. All user-facing strings via t(); all colors via tokens. */
(function () {
  'use strict';
  var PL = window.Kuyash;
  var t = PL.t;
  var ui = PL.ui = {};

  /* ---------- escaping (all dynamic text goes through this) ---------- */
  ui.esc = function (s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  };
  var esc = ui.esc;

  /* ---------- inline SVG icons ---------- */
  var P = function (d) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' + d + '</svg>'; };
  var ICONS = {
    home: P('<path d="M3 11l9-8 9 8"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-6h6v6"/>'),
    radar: P('<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/><path d="M12 12l6-6"/>'),
    studio: P('<path d="M4 20l4-1L20 7l-3-3L5 16l-1 4z"/><path d="M14 6l3 3"/>'),
    library: P('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 9h18"/><path d="M8 5v4"/><path d="M16 5v4"/><path d="M10 13.5l4 2-4 2v-4z" fill="currentColor" stroke="none"/>'),
    workflow: P('<rect x="2.5" y="9" width="6" height="6" rx="1.5"/><rect x="15.5" y="3" width="6" height="6" rx="1.5"/><rect x="15.5" y="15" width="6" height="6" rx="1.5"/><path d="M8.5 12h3.5M15.5 6h-2a2 2 0 00-2 2v8a2 2 0 002 2h2"/>'),
    queue: P('<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h10"/><circle cx="19" cy="18" r="2.5"/>'),
    users: P('<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.2 3.4-5 6.5-5s5.7 1.8 6.5 5"/><circle cx="17.5" cy="9" r="2.5"/><path d="M16 14.6c2.4.3 4.4 1.8 5.2 4.4"/>'),
    phone: P('<rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18.5h2"/>'),
    list: P('<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1" fill="currentColor"/><circle cx="4" cy="12" r="1" fill="currentColor"/><circle cx="4" cy="18" r="1" fill="currentColor"/>'),
    chart: P('<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-5M12 16V8M16 16v-3M20 16V6"/>'),
    credits: P('<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5v9M9.5 9.5c0-1 1-1.8 2.5-1.8s2.5.7 2.5 1.7c0 2.6-5 1.6-5 4.2 0 1 1 1.8 2.5 1.8s2.5-.8 2.5-1.8"/>'),
    gear: P('<circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 00-.1-1.2l2-1.5-2-3.4-2.3 1a7 7 0 00-2-1.2L14.2 3h-4l-.4 2.7a7 7 0 00-2 1.2l-2.3-1-2 3.4 2 1.5A7 7 0 005 12c0 .4 0 .8.1 1.2l-2 1.5 2 3.4 2.3-1a7 7 0 002 1.2l.4 2.7h4l.4-2.7a7 7 0 002-1.2l2.3 1 2-3.4-2-1.5c.1-.4.1-.8.1-1.2z"/>'),
    rocket: P('<path d="M12 15c5-4 6-9 6-12-3 0-8 1-12 6l-3 5 4 4 5-3z"/><path d="M9 15l-4.5 4.5"/><circle cx="13.5" cy="8.5" r="1.5"/>'),
    plus: P('<path d="M12 5v14M5 12h14"/>'),
    play: P('<path d="M7 5l12 7-12 7V5z"/>'),
    check: P('<path d="M4.5 12.5l5 5 10-11"/>'),
    x: P('<path d="M6 6l12 12M18 6L6 18"/>'),
    warning: P('<path d="M12 3l10 18H2L12 3z"/><path d="M12 10v5"/><circle cx="12" cy="17.6" r="0.4" fill="currentColor"/>'),
    info: P('<circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><circle cx="12" cy="7.6" r="0.4" fill="currentColor"/>'),
    search: P('<circle cx="11" cy="11" r="6.5"/><path d="M16 16l5 5"/>'),
    upload: P('<path d="M12 16V4m0 0L7 9m5-5l5 5"/><path d="M4 20h16"/>'),
    lock: P('<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/>'),
    bolt: P('<path d="M13 2L4 14h6l-1 8 9-12h-6l1-8z"/>'),
    moon: P('<path d="M20 14.5A8.5 8.5 0 119.5 4 7 7 0 0020 14.5z"/>'),
    refresh: P('<path d="M20 8a8 8 0 10.6 6"/><path d="M20 3v5h-5"/>'),
    chevR: P('<path d="M9 5l7 7-7 7"/>'),
    menu: P('<path d="M4 7h16M4 12h16M4 17h16"/>'),
    sparkle: P('<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3z"/>'),
    instagram: P('<rect x="3.5" y="3.5" width="17" height="17" rx="4.5"/><circle cx="12" cy="12" r="4"/><circle cx="17" cy="7" r="0.6" fill="currentColor"/>'),
    tiktok: P('<path d="M14 4v9.5a3.75 3.75 0 11-3.75-3.75"/><path d="M14 4c.5 2.5 2.3 4.2 5 4.5"/>'),
    youtube: P('<rect x="2.5" y="6" width="19" height="12" rx="3.5"/><path d="M10.5 9.5l5 2.5-5 2.5v-5z" fill="currentColor" stroke="none"/>'),
    clock: P('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>'),
    shield: P('<path d="M12 3l8 3v6c0 4.5-3.2 7.8-8 9-4.8-1.2-8-4.5-8-9V6l8-3z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>'),
    eye: P('<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>'),
    pauseIcon: P('<path d="M8 5v14M16 5v14"/>'),
    up: P('<path d="M12 19V5m0 0l-6 6m6-6l6 6"/>'),
    heart: P('<path d="M12 20s-7-4.4-9-8.8C1.8 8.4 3.5 5 6.6 5c2 0 3.4 1.2 4.4 3 1-1.8 2.4-3 4.4-3 3.1 0 4.8 3.4 3.6 6.2-2 4.4-9 8.8-9 8.8z"/>'),
    comment: P('<path d="M21 11.5a8.5 8.5 0 01-8.5 8.5c-1.5 0-2.9-.4-4.1-1L3 20l1.1-4A8.5 8.5 0 1121 11.5z"/>'),
    share: P('<path d="M15 5l6 6-6 6"/><path d="M21 11H9a6 6 0 00-6 6v2"/>'),
    music: P('<path d="M9 18V5l11-2v13"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="17.5" cy="16" r="2.5"/>'),
    cmd: P('<path d="M9 9V6.5A2.5 2.5 0 106.5 9H9zm0 0h6m-6 0v6m6-6V6.5A2.5 2.5 0 1117.5 9H15zm0 6h2.5a2.5 2.5 0 11-2.5 2.5V15zm0 0H9m0 0H6.5A2.5 2.5 0 109 17.5V15z"/>'),
    /* vertical-collapse arrows — must NOT read as a hamburger menu */
    density: P('<path d="M8 4l4 4 4-4"/><path d="M8 20l4-4 4 4"/><path d="M5 12h14"/>')
  };
  ui.icon = function (name, cls) {
    return '<span class="icon ' + (cls || '') + '" aria-hidden="true">' + (ICONS[name] || ICONS.info) + '</span>';
  };

  /* ---------- status dots & badges (color = status ONLY) ---------- */
  var STATUS_TONE = {
    queued: 'neutral', processing: 'info', awaiting_approval: 'warn', awaiting_recording: 'warn',
    ready: 'ok', failed: 'err', published: 'ok', cancelled: 'neutral', blocked: 'err',
    scheduled: 'info', draft: 'neutral', approved: 'ok', connected: 'ok', warning: 'warn', error: 'err'
  };
  ui.statusBadge = function (status) {
    var tone = STATUS_TONE[status] || 'neutral';
    var live = status === 'processing';
    return '<span class="chip chip--' + tone + '"><span class="dot dot--' + tone + (live ? ' pulse' : '') + '"></span>' +
      esc(t('status.' + status)) + '</span>';
  };
  ui.dot = function (tone, live) {
    return '<span class="dot dot--' + tone + (live ? ' pulse' : '') + '"></span>';
  };

  ui.complianceBadge = function (compliance) {
    if (!compliance) return '<span class="chip chip--neutral">' + esc(t('badge.compliancePending')) + '</span>';
    var map = {
      passed: ['badge.passed', 'ok'],
      ai_label_applied: ['badge.labelApplied', 'ai'],
      warn: ['badge.slopWarn', 'warn'],
      blocked: ['badge.blocked', 'err']
    };
    var m = map[compliance.result] || ['badge.compliancePending', 'neutral'];
    return '<span class="chip chip--' + m[1] + '">' + esc(t(m[0], { n: compliance.slop_score })) + '</span>';
  };
  ui.complianceNote = function (compliance) {
    if (!compliance || !compliance.note_key) return '';
    return esc(t(compliance.note_key, compliance.note_params || {}));
  };

  /* Truthful approval badges — text comes from the stored record (label_key),
     never re-derived. The two kinds stay verbally and visually distinct. */
  ui.approvalBadge = function (approval) {
    if (!approval) return '';
    if (approval.mode === 'manual') {
      return '<span class="chip chip--ok chip--record" title="' + esc((approval.by || '') + (approval.at ? ' · ' + PL.fmt.when(approval.at) : '')) + '">' +
        ui.icon('check') + esc(t(approval.label_key || 'badge.approvedByYou')) + '</span>';
    }
    if (approval.mode === 'auto') {
      /* agent action = info blue; fuchsia stays exclusive to AI-content labels */
      return '<span class="chip chip--info chip--record" title="' + esc(approval.at ? PL.fmt.when(approval.at) : '') + '">' +
        ui.icon('shield') + esc(t(approval.label_key || 'badge.autoApproved')) + '</span>';
    }
    return '<span class="chip chip--warn chip--record">' + ui.icon('warning') + esc(t('badge.unknownApproval')) + '</span>';
  };

  var SOURCE_KEYS = {
    google: 'badge.srcGoogle', youtube: 'badge.srcYouTube',
    tiktok_3p: 'badge.srcTikTok', instagram_be: 'badge.srcInstagram'
  };
  ui.sourceBadge = function (src) {
    var official = src === 'google' || src === 'youtube';
    return '<span class="chip ' + (official ? 'chip--neutral' : 'chip--faint') + '">' + esc(t(SOURCE_KEYS[src] || src)) + '</span>';
  };

  /* velocity encodes direction/intensity (followup #3): surging=warn, rising=ok,
     steady=neutral, cooling=faint — still within the 5 semantic colors */
  ui.velocityBadge = function (trend) {
    var tone = { surging: 'warn', rising: 'ok', steady: 'neutral', cooling: 'faint' }[trend.velocity] || 'neutral';
    var arrow = trend.velocity === 'cooling' ? '▼' : trend.velocity === 'steady' ? '–' : '▲';
    return '<span class="chip chip--' + tone + ' mono" data-live="trend:' + trend.id + '">' + arrow + ' ' +
      '<span class="num">' + trend.velocity_score + '</span>&nbsp;' + esc(t('velocity.' + trend.velocity)) + '</span>';
  };

  ui.platformIcon = function (p) { return ui.icon(p, 'icon--platform icon--' + p); };
  /* brand names are data, not translatable — and never miscased */
  var PLATFORM_NAMES = { instagram: 'Instagram', tiktok: 'TikTok', youtube: 'YouTube' };
  ui.platformName = function (p) { return PLATFORM_NAMES[p] || p; };
  ui.aiLabelTag = function () {
    return '<span class="chip chip--ai">' + ui.icon('sparkle') + esc(t('badge.aiLabel')) + '</span>';
  };
  ui.faceBadge = function (fmt) {
    return '<span class="chip chip--neutral">' + esc(t(fmt === 'face' ? 'badge.face' : 'badge.faceless')) + '</span>';
  };

  /* ---------- card anatomy: header → body → footer (same skeleton everywhere) ---------- */
  ui.card = function (o) {
    return '<section class="card ' + (o.cls || '') + '"' + (o.attrs || '') + '>' +
      (o.title != null
        ? '<header class="card__head"><h2>' + esc(o.title) + '</h2>' +
          (o.chip || '') + (o.action ? '<span class="card__action">' + o.action + '</span>' : '') + '</header>'
        : '') +
      '<div class="card__body">' + (o.body || '') + '</div>' +
      (o.footer ? '<footer class="card__foot">' + o.footer + '</footer>' : '') +
      '</section>';
  };

  /* ---------- terminal block (Logs pane, audit trail, live feed) ---------- */
  ui.termLine = function (log) {
    var lvl = log.level === 'error' ? 'error' : log.level === 'warn' ? 'warn' : 'info';
    var msg = log.key ? t(log.key, log.params || {}) : (log.message || '');
    return '<div class="term__line"' + (log.job_id ? ' data-job="' + esc(log.job_id) + '" tabindex="0" role="button"' : '') + '>' +
      '<span class="term__time">' + esc(log.at && log.at.time ? log.at.time : PL.fmt.when(log.at)) + '</span>' +
      '<span class="term__lvl term__lvl--' + lvl + '">' + esc(log.level) + '</span>' +
      '<span class="term__msg">' + esc(msg) + '</span></div>';
  };
  ui.term = function (o) {
    return '<div class="term ' + (o.cls || '') + '">' +
      '<div class="term__head"><span class="dot dot--ok' + (o.live ? ' pulse' : '') + '"></span>' +
      esc(o.title) + (o.controls ? '<span class="term__controls">' + o.controls + '</span>' : '') + '</div>' +
      '<div class="term__body" ' + (o.bodyAttrs || '') + ' style="' + (o.height ? 'max-height:' + o.height : '') + '">' +
      (o.lines || '') + '</div></div>';
  };

  /* ---------- empty / loading / error states (same card skeleton) ----------
     Teaching empty states: one-line explanation + a single CTA into the right
     flow (cta: { label, nav } or { label, attr } for screen-bound actions). */
  ui.emptyState = function (o) {
    var cta = '';
    if (o.cta) {
      cta = '<button class="btn btn--primary btn--sm" ' +
        (o.cta.nav ? 'data-nav="' + esc(o.cta.nav) + '"' : (o.cta.attr || '')) + '>' +
        (o.cta.icon ? ui.icon(o.cta.icon) : '') + esc(o.cta.label) + '</button>';
    }
    return '<div class="ui-state ui-state--empty">' + ui.icon(o.icon || 'radar', 'ui-state__icon') +
      '<h3>' + esc(o.title || '') + '</h3><p>' + esc(o.body || '') + '</p>' + cta + '</div>';
  };
  ui.loadingState = function () {
    return '<div class="ui-state ui-state--loading">' +
      '<div class="skel-stack"><span class="skel" style="width:38%"></span><span class="skel" style="width:72%"></span><span class="skel" style="width:55%"></span></div>' +
      '<p>' + esc(t('state.loading')) + '</p></div>';
  };
  ui.errorState = function (o) {
    return '<div class="ui-state ui-state--error">' + ui.icon('warning', 'ui-state__icon') +
      '<h3>' + esc(o.title || '') + '</h3><p>' + esc(o.body || '') + '</p>' +
      '<button class="btn btn--ghost" data-action="retry-ui">' + ui.icon('refresh') + esc(t('common.retry')) + '</button></div>';
  };

  ui.uiStateBar = function (screenKey) {
    var cur = PL.state.uiStates[screenKey];
    return '<div class="uistate-bar" role="group" aria-label="' + esc(t('uistate.label')) + '">' +
      '<span class="uistate-bar__label">' + esc(t('uistate.label')) + '</span>' +
      ['data', 'empty', 'loading', 'error'].map(function (s) {
        return '<button class="uistate-bar__btn' + (cur === s ? ' is-active' : '') + '" data-uistate="' + screenKey + ':' + s + '">' + esc(t('uistate.' + s)) + '</button>';
      }).join('') + '</div>';
  };
  ui.resolveUiState = function (screenKey, emptyOpts, errorOpts) {
    var s = PL.state.uiStates[screenKey];
    if (s === 'loading') return ui.loadingState();
    if (s === 'empty') return ui.emptyState(emptyOpts);
    if (s === 'error') return ui.errorState(errorOpts);
    return null;
  };

  /* ---------- small viz ---------- */
  ui.sparkbars = function (values, mod) {
    var max = Math.max.apply(null, values) || 1;
    return '<span class="spark ' + (mod || '') + '">' + values.map(function (v) {
      return '<i style="height:' + Math.max(8, Math.round(v / max * 100)) + '%"></i>';
    }).join('') + '</span>';
  };
  ui.progressBar = function (pct, liveId) {
    var p = Math.round(pct * 100);
    return '<div class="progress"' + (liveId ? ' data-live="progress:' + esc(liveId) + '"' : '') + '>' +
      '<div class="progress__fill" style="width:' + p + '%"></div>' +
      '<span class="progress__num mono">' + p + '%</span></div>';
  };
  ui.meter = function (value, max) {
    var p = Math.min(100, Math.round(value / max * 100));
    var cls = p >= 90 ? 'is-err' : (p >= 75 ? 'is-warn' : '');
    return '<div class="meter ' + cls + '"><div class="meter__fill" style="width:' + p + '%"></div></div>';
  };
  ui.thumb = function (n, label, big, aspect) {
    return '<div class="thumb thumb--n' + ((n % 10) || 10) + (big ? ' thumb--big' : '') + '">' +
      '<span class="thumb__ratio mono">' + esc(aspect || '9:16') + '</span>' +
      (label ? '<span class="thumb__label mono">' + esc(label) + '</span>' : '') + '</div>';
  };
  ui.stat = function (labelKey, value, sub, opts) {
    opts = opts || {};
    return '<div class="stat ' + (opts.cls || '') + '"' + (opts.attrs || '') + '>' +
      '<span class="stat__label">' + esc(t(labelKey)) + '</span>' +
      '<span class="stat__value mono num"' + (opts.count != null ? ' data-count="' + opts.count + '"' : '') + (opts.valueAttrs || '') + '>' + value + '</span>' +
      (sub ? '<span class="stat__sub">' + sub + '</span>' : '') + '</div>';
  };

  /* ---------- compliance check bars (scored checks → tone-tinted bars) ---------- */
  ui.checkBars = function (checks) {
    return '<ul class="checkbars">' + (checks || []).map(function (c) {
      var tone = c.result === 'pass' ? 'ok' : c.result === 'warn' ? 'warn' : 'err';
      var pct = Math.round(c.score / (c.max || 100) * 100);
      return '<li class="checkbar">' +
        '<span class="checkbar__name">' + esc(t('check.' + c.key)) + '</span>' +
        '<div class="checkbar__bar"><div class="checkbar__fill checkbar__fill--' + tone + '" style="width:' + pct + '%"></div></div>' +
        '<b class="checkbar__score mono num">' + c.score + '</b>' +
        '<span class="dot dot--' + tone + '"></span></li>';
    }).join('') + '</ul>';
  };

  /* ---------- status timeline (mock of the Phase 4 append-only event log) ---------- */
  ui.timeline = function (events) {
    var ORDER = ['queued', 'processing', 'ready', 'published'];
    var byState = {};
    (events || []).forEach(function (e) { byState[e.state] = e; });
    var lastIdx = -1;
    ORDER.forEach(function (s, i) { if (byState[s]) lastIdx = i; });
    return '<ol class="tl">' + ORDER.map(function (s, i) {
      var e = byState[s];
      var cls = e ? (i === lastIdx ? 'is-current' : 'is-done') : 'is-future';
      return '<li class="tl__step ' + cls + '">' +
        '<span class="tl__dot' + (i === lastIdx && s === 'processing' ? ' pulse' : '') + '"></span>' +
        '<span class="tl__label">' + esc(t('status.' + s)) + '</span>' +
        '<span class="tl__time mono">' + (e ? esc(PL.fmt.when(e.at)) : '—') + '</span></li>';
    }).join('') + '</ol>';
  };

  /* ---------- "Why?" disclosure (transparency for scores & decisions) ---------- */
  ui.why = function (bodyHtml) {
    return '<details class="why"><summary>' + ui.icon('info') + '<span>' + esc(t('why.label')) + '</span></summary>' +
      '<div class="why__body">' + bodyHtml + '</div></details>';
  };
  /* compliance "Why?" — policy version + slop reading + the decision note */
  ui.whyCompliance = function (c) {
    return ui.why(
      '<div class="why__row"><em>' + esc(t('why.policy', { v: c.policy_version })) + '</em></div>' +
      '<div class="why__row">' + esc(t('why.slop', { n: c.slop_score })) + '</div>' +
      (c.note_key ? '<div class="why__row"><em>' + ui.complianceNote(c) + '</em></div>' : '')
    );
  };
  /* idea-score "Why?" — source trend + mock audit components */
  ui.whyIdea = function (idea, trend) {
    var s = idea.score || {};
    function row(key, v) {
      return '<div class="why__row"><em>' + esc(t(key)) + '</em><b>' + (v || 0) + '</b></div>';
    }
    return ui.why(
      (trend
        ? '<div class="why__row">' + esc(t('why.fromTrend', { title: trend.title })) + '</div>'
        : '<div class="why__row"><em>' + esc(t('why.noTrend')) + '</em></div>') +
      row('why.velocity', s.velocity) +
      row('why.fit', s.fit) +
      row('why.novelty', s.novelty) +
      row('why.total', s.total)
    );
  };

  /* ---------- keycap hints (J/K · A/R · Enter); parsed from the i18n string ---------- */
  ui.kbdHint = function () {
    return '<span class="kbd-hint" aria-label="' + esc(t('kbd.hint')) + '">' +
      t('kbd.hint').split('·').map(function (part) {
        var p = part.trim();
        var i = p.indexOf(' ');
        if (i === -1) return '<kbd>' + esc(p) + '</kbd>';
        return '<span class="kbd-hint__pair"><kbd>' + esc(p.slice(0, i)) + '</kbd><em>' + esc(p.slice(i + 1)) + '</em></span>';
      }).join('') + '</span>';
  };

  /* ---------- platform-skin preview chrome (IG / TikTok / YT Shorts mock) ----------
     Neutral zinc only — the platform identity comes from layout, not brand colors. */
  ui.platformSkin = function (platform, o) {
    var tags = (o.hashtags || []).slice(0, 3).join(' ');
    var ai = o.ai_label ? '<span class="skin__ai chip chip--ai">' + ui.icon('sparkle') + esc(t('badge.aiLabel')) + '</span>' : '';
    var rail = '<div class="skin__rail">' +
      '<span class="skin__avatar"></span>' +
      ui.icon('heart') + ui.icon('comment') + ui.icon('share') + '</div>';
    var head = '', meta = '';
    if (platform === 'tiktok') {
      head = '<div class="skin__tabs"><span>' + esc(t('skin.following')) + '</span><b>' + esc(t('skin.forYou')) + '</b></div>';
      meta = '<div class="skin__meta"><span class="skin__handle mono">' + esc(o.handle || '') + '</span>' +
        '<p class="skin__cap">' + esc(o.caption || '') + (tags ? ' <span class="skin__tags mono">' + esc(tags) + '</span>' : '') + '</p>' +
        '<div class="skin__sound">' + ui.icon('music') + '<span>' + esc(t('skin.sound')) + (o.handle ? ' · ' + esc(o.handle) : '') + '</span></div></div>';
    } else if (platform === 'youtube') {
      head = '<div class="skin__yttop mono">' + esc(t('skin.shorts')) + '</div>';
      meta = '<div class="skin__meta">' +
        '<p class="skin__cap skin__cap--title">' + esc(o.caption || '') + '</p>' +
        '<div class="skin__channel"><span class="skin__avatar skin__avatar--sm"></span>' +
        '<span class="skin__handle mono">' + esc(o.handle || '') + '</span>' +
        '<span class="skin__subscribe">' + esc(t('skin.subscribe')) + '</span></div></div>';
    } else { /* instagram reels */
      meta = '<div class="skin__meta"><span class="skin__handle mono">' + esc(o.handle || '') + '</span>' +
        '<p class="skin__cap">' + esc(o.caption || '') + (tags ? ' <span class="skin__tags mono">' + esc(tags) + '</span>' : '') + '</p>' +
        '<div class="skin__sound">' + ui.icon('music') + '<span>' + esc(t('skin.sound')) + '</span></div></div>';
    }
    return '<div class="skin skin--' + platform + '">' + head + ai + rail + meta + '</div>';
  };

  /* ---------- modal / drawer (HTML SINKS: callers escape all dynamic values) ---------- */
  var modalRoot = null;
  var lastFocus = null;
  function root() { modalRoot = modalRoot || document.getElementById('modal-root'); return modalRoot; }
  function applyEnterFallback(overlay) {
    /* @starting-style fallback: Safari et al. without allow-discrete */
    if (window.CSS && CSS.supports && CSS.supports('transition-behavior', 'allow-discrete')) return;
    overlay.classList.add('enter-init');
    requestAnimationFrame(function () { requestAnimationFrame(function () { overlay.classList.remove('enter-init'); }); });
  }

  ui.modal = function (o) {
    /* drill-downs (overlay → overlay) keep the ORIGINAL opener as the focus target */
    if (!root().contains(document.activeElement)) lastFocus = document.activeElement;
    root().innerHTML =
      '<div class="modal-overlay" data-action="modal-overlay">' +
      '  <div class="modal" role="dialog" aria-modal="true" aria-label="' + esc(o.title) + '">' +
      '    <header class="modal__head"><h3>' + esc(o.title) + '</h3>' +
      '      <button class="iconbtn" data-action="modal-close" aria-label="' + esc(t('common.close')) + '">' + ui.icon('x') + '</button></header>' +
      '    <div class="modal__body">' + o.body + '</div>' +
      (o.footer ? '<footer class="modal__foot">' + o.footer + '</footer>' : '') +
      '  </div></div>';
    var overlay = root().querySelector('.modal-overlay');
    applyEnterFallback(overlay);
    root().querySelectorAll('[data-action="modal-close"]').forEach(function (b) { b.addEventListener('click', ui.closeModal); });
    overlay.addEventListener('click', function (e) { if (e.target === e.currentTarget) ui.closeModal(); });
    var m = root().querySelector('.modal');
    var f = m.querySelector('button:not([data-action="modal-close"]), input, select, textarea');
    if (f) f.focus(); else m.querySelector('[data-action="modal-close"]').focus();
    return m;
  };
  ui.closeModal = function () {
    root().innerHTML = '';
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) { /* gone */ } }
    lastFocus = null;
  };
  /* overlays built outside components.js (e.g. the palette) register their
     opener here so closeModal can return focus to it */
  ui.captureFocus = function () {
    /* drill-downs (overlay → overlay) keep the ORIGINAL opener as the focus target */
    if (!root().contains(document.activeElement)) lastFocus = document.activeElement;
  };

  ui.confirm = function (o) {
    var m = ui.modal({
      title: o.title,
      body: '<p class="modal__text">' + o.body + '</p>' +
        (o.detail ? '<div class="callout callout--' + (o.danger ? 'err' : 'info') + '">' + o.detail + '</div>' : ''),
      footer:
        '<button class="btn btn--ghost" data-action="modal-close">' + esc(t('common.cancel')) + '</button>' +
        '<button class="btn ' + (o.danger ? 'btn--danger' : 'btn--primary') + '" data-action="confirm-ok">' +
        esc(o.confirmLabel || t('common.confirm')) + '</button>'
    });
    m.querySelector('[data-action="modal-close"]').addEventListener('click', ui.closeModal);
    m.querySelector('[data-action="confirm-ok"]').addEventListener('click', function () {
      ui.closeModal();
      if (o.onConfirm) o.onConfirm();
    });
  };

  ui.drawer = function (title, bodyHtml) {
    /* drill-downs (overlay → overlay) keep the ORIGINAL opener as the focus target */
    if (!root().contains(document.activeElement)) lastFocus = document.activeElement;
    root().innerHTML =
      '<div class="modal-overlay modal-overlay--drawer" data-action="modal-overlay">' +
      '  <aside class="drawer" role="dialog" aria-modal="true" aria-label="' + esc(title) + '">' +
      '    <header class="modal__head"><h3 class="mono">' + esc(title) + '</h3>' +
      '      <button class="iconbtn" data-action="modal-close" aria-label="' + esc(t('common.close')) + '">' + ui.icon('x') + '</button></header>' +
      '    <div class="drawer__body">' + bodyHtml + '</div>' +
      '  </aside></div>';
    var overlay = root().querySelector('.modal-overlay');
    applyEnterFallback(overlay);
    root().querySelector('[data-action="modal-close"]').addEventListener('click', ui.closeModal);
    overlay.addEventListener('click', function (e) { if (e.target === e.currentTarget) ui.closeModal(); });
    root().querySelector('[data-action="modal-close"]').focus();
  };

  /* Global Detail Drawer (iteration 2): one slide-over anatomy for renders,
     jobs, trends, accounts, assets. Esc/overlay close + focus-return come
     from the shared modal plumbing; route changes close it (router). */
  ui.detailDrawer = function (o) {
    /* drill-downs (overlay → overlay) keep the ORIGINAL opener as the focus target */
    if (!root().contains(document.activeElement)) lastFocus = document.activeElement;
    root().innerHTML =
      '<div class="modal-overlay modal-overlay--drawer" data-action="modal-overlay">' +
      '  <aside class="drawer drawer--detail" role="dialog" aria-modal="true" aria-label="' + esc(o.title) + '">' +
      '    <header class="drawer__head">' +
      '      <div class="drawer__titles"><h3>' + esc(o.title) + '</h3>' +
      (o.subtitle ? '<p class="drawer__subtitle mono">' + esc(o.subtitle) + '</p>' : '') + '</div>' +
      '      <button class="iconbtn" data-action="modal-close" aria-label="' + esc(t('drawer.close')) + '">' + ui.icon('x') + '</button>' +
      '    </header>' +
      (o.chips ? '<div class="drawer__chips">' + o.chips + '</div>' : '') +
      '    <div class="drawer__body">' + (o.body || '') + '</div>' +
      (o.actions ? '<footer class="drawer__foot">' + o.actions + '</footer>' : '') +
      '  </aside></div>';
    var overlay = root().querySelector('.modal-overlay');
    applyEnterFallback(overlay);
    root().querySelectorAll('[data-action="modal-close"]').forEach(function (b) { b.addEventListener('click', ui.closeModal); });
    overlay.addEventListener('click', function (e) { if (e.target === e.currentTarget) ui.closeModal(); });
    var d = root().querySelector('.drawer');
    var f = d.querySelector('.drawer__foot button, .drawer__foot a') || d.querySelector('[data-action="modal-close"]');
    if (f) f.focus();
    return d;
  };
  ui.drawerOpen = function () {
    return !!root().querySelector('.drawer');
  };

  /* ---------- toasts (root has aria-live) ---------- */
  ui.toast = function (message, type) {
    var rootEl = document.getElementById('toast-root');
    var el = document.createElement('div');
    el.className = 'toast toast--' + (type || 'info');
    el.innerHTML = ui.icon(type === 'success' ? 'check' : type === 'danger' ? 'warning' : 'info') +
      '<span>' + esc(message) + '</span>';
    rootEl.appendChild(el);
    setTimeout(function () { el.classList.add('is-out'); }, 3000);
    setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 3600);
  };

  /* ---------- misc ---------- */
  ui.sectionHead = function (title, extra) {
    return '<div class="section-head"><h2>' + esc(title) + '</h2>' + (extra || '') + '</div>';
  };
  ui.note = function (text) {
    return '<p class="note">' + ui.icon('info') + esc(text) + '</p>';
  };
  ui.kv = function (label, valueHtml) {
    return '<div class="kv"><dt>' + esc(label) + '</dt><dd>' + valueHtml + '</dd></div>';
  };
})();
