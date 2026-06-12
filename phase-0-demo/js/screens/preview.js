/* Screen 8 — Post Preview: phone frame (shared-element morph target),
   per-platform variations, approval-gated publish/schedule mocks. */
(function () {
  'use strict';
  var PL = window.Kuyash, ui = PL.ui, store = PL.store, t = PL.t;

  PL.screens.preview = {
    id: 'preview', icon: 'phone',

    render: function (el) {
      var ws = store.workspace();
      var renders = store.byWorkspace('renders').filter(function (r) {
        return ['blocked', 'cancelled'].indexOf(r.status) === -1;
      });
      if (!renders.length) {
        el.innerHTML = `<header class="screen-head"><div><h1 class="vt-page-title">${ui.esc(t('nav.preview'))}</h1></div></header>` +
          ui.emptyState({ icon: 'phone', title: t('preview.empty_t'), body: t('preview.empty_b'),
            cta: { label: t('cta.create'), nav: 'create', icon: 'plus' } });
        return;
      }
      var cur = store.find('renders', PL.state.previewRenderId);
      if (!cur || cur.workspace_id !== ws.id || ['blocked', 'cancelled'].indexOf(cur.status) !== -1) cur = renders[0];
      var platform = PL.state.previewPlatform;
      var script = store.find('scripts', cur.script_id);
      var caption = script ? script.captions[platform] : '';
      var hashtags = script ? (script.hashtags[platform] || []) : [];
      /* publish unlocks ONLY with a real approval record — status alone is never proof */
      var approved = !!cur.approval;
      var canPublish = approved && cur.status !== 'published' && cur.status !== 'processing' && caption;

      el.innerHTML = `
        <header class="screen-head">
          <div><h1 class="vt-page-title">${ui.esc(t('nav.preview'))}</h1>
          <p class="screen-sub">${ui.esc(t('preview.sub'))}</p></div>
        </header>

        <div class="preview-layout">
          ${ui.card({
            title: t('preview.renders'), cls: 'preview-picker',
            body: `<ul class="picker-list">
              ${renders.map(function (r) {
                return `<li><button class="picker-item ${r.id === cur.id ? 'is-active' : ''}" data-pick-render="${r.id}">
                  ${ui.thumb(r.thumb)}
                  <div><strong>${ui.esc(r.title)}</strong><span class="mono">${r.duration_s}s</span>${ui.statusBadge(r.status)}</div>
                </button></li>`;
              }).join('')}
            </ul>`
          })}

          <div class="phone-wrap">
            <div class="phone">
              <div class="phone__screen thumb thumb--n${(cur.thumb % 10) || 10} vt-media-hero">
                ${ui.platformSkin(platform, {
                  caption: caption || '—',
                  hashtags: hashtags,
                  handle: (function () {
                    var acc = store.byWorkspace('accounts').find(function (a) { return a.platform === platform; });
                    return acc ? acc.handle : '';
                  })(),
                  ai_label: cur.ai_label
                })}
                <span class="phone__duration mono">${cur.duration_s}s · 9:16</span>
              </div>
            </div>
            ${cur.ai_label ? ui.note(t('preview.aiOverlay')) : ''}
          </div>

          <div class="preview-meta panel">
            <div class="platform-tabs" role="group" aria-label="${ui.esc(t('skin.tabAria'))}">
              ${['instagram', 'tiktok', 'youtube'].map(function (p) {
                return `<button class="platform-tab ${platform === p ? 'is-active' : ''}" data-platform="${p}">${ui.platformIcon(p)}${ui.platformName(p)}</button>`;
              }).join('')}
            </div>

            ${caption
              ? `<label class="field"><span>${ui.esc(t('preview.caption', { platform: ui.platformName(platform) }))}</span><p class="caption-preview">${ui.esc(caption)}</p></label>
                 <div class="tag-row">${hashtags.map(function (tg) { return '<span class="tag">' + ui.esc(tg) + '</span>'; }).join('')}</div>`
              : `<div class="callout callout--warn">${ui.icon('warning')}<div><strong>${ui.esc(t('preview.missing_t', { platform: ui.platformName(platform) }))}</strong> ${ui.esc(t('preview.missing_b'))}</div></div>`}

            <div class="preview-meta__status">
              ${ui.statusBadge(cur.status)}
              ${cur.ai_label ? ui.aiLabelTag() : ''}
              ${ui.complianceBadge(cur.compliance)}
              ${cur.approval ? ui.approvalBadge(cur.approval) : ''}
            </div>

            ${cur.status === 'awaiting_approval' ? `<div class="callout callout--info">${ui.icon('lock')}<div>${ui.esc(t('preview.awaiting_b'))}</div>
              <button class="btn btn--ghost btn--sm" data-nav="queue">${ui.esc(t('preview.openQueue'))}</button></div>` : ''}

            <div class="preview-actions">
              <button class="btn btn--primary" data-publish ${canPublish ? '' : 'disabled'}>${ui.icon('rocket')}${ui.esc(t('preview.publish'))}</button>
              <button class="btn btn--ghost" data-schedule ${canPublish ? '' : 'disabled'}>${ui.icon('clock')}${ui.esc(t('preview.schedule'))}</button>
            </div>
            ${cur.status === 'published' ? ui.note(t('preview.publishedAt', { when: PL.fmt.when(cur.published_at) })) : ''}
            ${cur.status === 'scheduled' ? ui.note(t('preview.scheduledFor', { when: PL.fmt.when(cur.publish_at) })) : ''}
            ${ui.note(t('preview.note'))}
          </div>
        </div>`;

      el.querySelectorAll('[data-pick-render]').forEach(function (b) {
        b.addEventListener('click', function () {
          PL.state.previewRenderId = b.getAttribute('data-pick-render'); PL.App.rerender();
        });
      });
      el.querySelectorAll('[data-platform]').forEach(function (b) {
        b.addEventListener('click', function () {
          PL.state.previewPlatform = b.getAttribute('data-platform'); PL.App.rerender();
        });
      });

      var pub = el.querySelector('[data-publish]');
      if (pub) pub.addEventListener('click', function () {
        ui.confirm({
          title: t('dlg.publish_t'),
          body: ui.esc(t('dlg.publish_b', { title: cur.title, platform: ui.platformName(platform) })),
          detail: ui.icon('info') + ' ' + ui.esc(t('dlg.publish_d')),
          confirmLabel: t('dlg.publish_ok'), danger: true,
          onConfirm: function () {
            cur.status = 'published'; cur.published_at = { day: 'now' };
            if (cur.timeline) cur.timeline.push({ state: 'published', at: { day: 'now' } });
            store.logKeyed('log.mock_publish', { render: cur.id });
            ui.toast(t('toast.published'), 'success');
            PL.App.rerender();
            /* choreography: brief success pulse on the platform (account) chip */
            PL.motion.pulseOnce(document.querySelector('.platform-tab.is-active'));
          }
        });
      });
      var sch = el.querySelector('[data-schedule]');
      if (sch) sch.addEventListener('click', function () {
        ui.confirm({
          title: t('dlg.schedule_t'),
          body: ui.esc(t('dlg.schedule_b', { title: cur.title })),
          confirmLabel: t('dlg.schedule_ok'),
          onConfirm: function () {
            cur.status = 'scheduled'; cur.publish_at = { day: 'tomorrow', time: '07:30' };
            store.logKeyed('log.mock_schedule', { render: cur.id });
            ui.toast(t('toast.scheduled'), 'success');
            PL.App.rerender();
          }
        });
      });
    }
  };
})();
