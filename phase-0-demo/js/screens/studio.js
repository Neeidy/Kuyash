/* Screen 3 — Content Studio: Ideas / Script editor / Shooting briefs / Quick Create */
(function () {
  'use strict';
  var PL = window.Kuyash, ui = PL.ui, store = PL.store, t = PL.t;

  var TABS = [['ideas', 'studio.tabIdeas'], ['script', 'studio.tabScript'], ['brief', 'studio.tabBriefs'], ['quickcreate', 'studio.tabQuick']];

  function ideasTab(ws) {
    var ideas = store.byWorkspace('ideas');
    if (!ideas.length) return ui.emptyState({ icon: 'studio', title: t('studio.ideasEmpty_t'), body: t('studio.ideasEmpty_b'),
      cta: { label: t('cta.openTrends'), nav: 'trends', icon: 'radar' } });
    return `<div class="idea-list stagger">${ideas.map(function (i) {
      var trend = i.trend_id ? store.find('trends', i.trend_id) : null;
      var script = PL.state.data.scripts.find(function (s) { return s.idea_id === i.id; });
      return `<article class="idea-card lift">
        <div class="idea-card__main">
          <div class="idea-card__title"><h3>${ui.esc(i.title)}</h3>${ui.statusBadge(i.status)}</div>
          <p class="idea-card__hook">“${ui.esc(i.hook)}”</p>
          ${trend ? `<span class="idea-card__trend">${ui.icon('radar')}${ui.esc(t('studio.fromTrend'))} ${ui.esc(trend.title)}</span>` : ''}
          ${ui.whyIdea(i, trend)}
        </div>
        <div class="idea-card__actions">
          ${i.status === 'draft' ? `<button class="btn btn--ghost btn--sm" data-approve-idea="${i.id}">${ui.icon('check')}${ui.esc(t('studio.approveIdea'))}</button>` : ''}
          ${script ? `<button class="btn btn--ghost btn--sm" data-open-script="${script.id}">${ui.esc(t('studio.openScript'))}</button>`
                   : `<button class="btn btn--ghost btn--sm" data-gen-script="${i.id}">${ui.icon('sparkle')}${ui.esc(t('studio.genScript'))}</button>`}
        </div>
      </article>`;
    }).join('')}</div>`;
  }

  function scriptTab(ws) {
    var scripts = store.byWorkspace('scripts');
    if (!scripts.length) return ui.emptyState({ icon: 'studio', title: t('studio.scriptsEmpty_t'), body: t('studio.scriptsEmpty_b') });
    var cur = store.find('scripts', PL.state.studioScriptId);
    if (!cur || cur.workspace_id !== ws.id) cur = scripts[0];

    return `
      <div class="script-editor">
        <div class="script-editor__main panel">
          <div class="field-row">
            <label class="field"><span>${ui.esc(t('studio.script'))}</span>
              <select data-script-select>${scripts.map(function (s) {
                return `<option value="${s.id}" ${s.id === cur.id ? 'selected' : ''}>${ui.esc(s.hook.slice(0, 60))}</option>`;
              }).join('')}</select>
            </label>
            <span class="chip chip--neutral mono">${ui.icon('clock')}${ui.esc(t('studio.target', { n: cur.target_duration_s }))}</span>
            ${ui.statusBadge(cur.status)}
          </div>
          <label class="field"><span>${ui.esc(t('studio.hook'))} <button class="btn btn--ghost btn--xs" data-regen-hook>${ui.icon('refresh')}${ui.esc(t('studio.regen'))}</button></span>
            <textarea rows="2" data-field="hook">${ui.esc(cur.hook)}</textarea></label>
          <label class="field"><span>${ui.esc(t('studio.body'))}</span>
            <textarea rows="5" data-field="body">${ui.esc(cur.body)}</textarea></label>
          <label class="field"><span>${ui.esc(t('studio.cta'))}</span>
            <textarea rows="2" data-field="cta">${ui.esc(cur.cta)}</textarea></label>
          <div class="script-editor__actions">
            <button class="btn btn--ghost" data-save-script>${ui.esc(t('studio.saveDraft'))}</button>
            <button class="btn btn--primary" data-to-queue>${ui.icon('play')}${ui.esc(t('studio.send'))}</button>
          </div>
          ${ui.note(t('studio.regenNote'))}
        </div>

        <aside class="script-editor__side panel">
          ${ui.sectionHead(t('studio.captionPreview'))}
          <div class="platform-tabs" role="group" aria-label="${ui.esc(t('skin.tabAria'))}">
            ${['instagram', 'tiktok', 'youtube'].map(function (p) {
              return `<button class="platform-tab ${PL.state.previewPlatform === p ? 'is-active' : ''}" data-cap-platform="${p}">${ui.platformIcon(p)}${ui.platformName(p)}</button>`;
            }).join('')}
          </div>
          ${(function () {
            var p = PL.state.previewPlatform;
            var cap = cur.captions[p];
            var tags = (cur.hashtags[p] || []);
            if (!cap) return `<div class="callout callout--warn">${ui.icon('warning')}<div>${ui.esc(t('studio.noCaption', { platform: ui.platformName(p) }))}</div></div>`;
            return `<p class="caption-preview">${ui.esc(cap)}</p>
              <div class="tag-row">${tags.map(function (tg) { return '<span class="tag">' + ui.esc(tg) + '</span>'; }).join('')}</div>`;
          })()}
        </aside>
      </div>`;
  }

  function briefTab(ws) {
    var briefs = store.byWorkspace('briefs');
    if (!briefs.length) return ui.emptyState({ icon: 'phone', title: t('studio.briefsEmpty_t'), body: t('studio.briefsEmpty_b') });
    return `<div class="brief-list stagger">${briefs.map(function (b) {
      var script = store.find('scripts', b.script_id);
      return `<article class="panel brief-card">
        <div class="brief-card__head">
          <h3>${ui.esc(script ? script.hook : b.id)}</h3>
          ${b.recorded ? '<span class="chip chip--ok">' + ui.icon('check') + ui.esc(t('studio.recorded')) + '</span>'
                       : '<span class="chip chip--warn">' + ui.icon('clock') + ui.esc(t('studio.awaitingRec')) + '</span>'}
        </div>
        <dl class="brief-card__grid">
          <div><dt>${ui.esc(t('studio.what'))}</dt><dd>${ui.esc(b.what_to_record)}</dd></div>
          <div><dt>${ui.esc(t('studio.duration'))}</dt><dd>${ui.esc(b.duration)}</dd></div>
          <div><dt>${ui.esc(t('studio.framing'))}</dt><dd>${ui.esc(b.framing)}</dd></div>
          <div><dt>${ui.esc(t('studio.hookTiming'))}</dt><dd>${ui.esc(b.hook_timing)}</dd></div>
        </dl>
        ${b.recorded ? '' : `<div class="brief-card__foot">
          ${ui.note(t('studio.pausedNote'))}
          <button class="btn btn--primary btn--sm" data-mark-recorded="${b.id}">${ui.icon('check')}${ui.esc(t('studio.markRecorded'))}</button>
        </div>`}
      </article>`;
    }).join('')}</div>`;
  }

  /* Quick Create merged into the Create composer (iteration 2 §D) —
     this tab stays as a slim pointer so the 13-screen spec naming holds. */
  function quickCreateTab(ws) {
    return `<div class="panel quickcreate">
      ${ui.sectionHead(t('studio.qcTitle'))}
      <p class="screen-sub">${ui.esc(t('studio.qcSub'))}</p>
      <div class="callout callout--info">${ui.icon('info')}<div><strong>${ui.esc(t('studio.qcMoved_t'))}</strong><br>
        ${ui.esc(t('studio.qcMoved_b'))}</div></div>
      <div class="callout callout--ai">${ui.icon('sparkle')}<div><strong>${ui.esc(t('studio.qcAi_t'))}</strong><br>${ui.esc(t('studio.qcAi_b'))}</div></div>
      <button class="btn btn--primary btn--lg" data-nav="create">${ui.icon('plus')}${ui.esc(t('studio.qcOpen'))}</button>
    </div>`;
  }

  PL.screens.studio = {
    id: 'studio', icon: 'studio',

    render: function (el) {
      var ws = store.workspace();
      var tab = PL.state.studioTab;

      el.innerHTML = `
        <header class="screen-head">
          <div><h1 class="vt-page-title">${ui.esc(t('nav.studio'))}</h1>
          <p class="screen-sub">${ui.esc(t('studio.sub', { ws: ws.name }))}</p></div>
        </header>
        <div class="tabbar" role="tablist">
          ${TABS.map(function (tb) {
            return `<button class="tab ${tab === tb[0] ? 'is-active' : ''}" data-tab="${tb[0]}" role="tab" aria-selected="${tab === tb[0]}">${ui.esc(t(tb[1]))}</button>`;
          }).join('')}
        </div>
        <div class="tab-body">
          ${tab === 'ideas' ? ideasTab(ws) : tab === 'script' ? scriptTab(ws) : tab === 'brief' ? briefTab(ws) : quickCreateTab(ws)}
        </div>`;

      el.querySelectorAll('.stagger').forEach(PL.motion.stagger);

      el.querySelectorAll('[data-tab]').forEach(function (b) {
        b.addEventListener('click', function () {
          PL.state.studioTab = b.getAttribute('data-tab'); PL.App.rerender();
        });
      });

      /* ideas */
      el.querySelectorAll('[data-approve-idea]').forEach(function (b) {
        b.addEventListener('click', function () {
          store.find('ideas', b.getAttribute('data-approve-idea')).status = 'approved';
          ui.toast(t('toast.ideaApproved'), 'success'); PL.App.rerender();
        });
      });
      el.querySelectorAll('[data-open-script]').forEach(function (b) {
        b.addEventListener('click', function () {
          PL.state.studioScriptId = b.getAttribute('data-open-script');
          PL.state.studioTab = 'script'; PL.App.rerender();
        });
      });
      el.querySelectorAll('[data-gen-script]').forEach(function (b) {
        b.addEventListener('click', function () { ui.toast(t('toast.genPhase5'), 'info'); });
      });

      /* script editor */
      var sel = el.querySelector('[data-script-select]');
      if (sel) sel.addEventListener('change', function () {
        PL.state.studioScriptId = sel.value; PL.App.rerender();
      });
      el.querySelectorAll('[data-cap-platform]').forEach(function (b) {
        b.addEventListener('click', function () {
          PL.state.previewPlatform = b.getAttribute('data-cap-platform'); PL.App.rerender();
        });
      });
      var cur = store.find('scripts', PL.state.studioScriptId);
      if (!cur || cur.workspace_id !== ws.id) cur = store.byWorkspace('scripts')[0];
      var regen = el.querySelector('[data-regen-hook]');
      if (regen) regen.addEventListener('click', function (e) {
        e.preventDefault();
        if (!cur || !cur.alt_hooks || !cur.alt_hooks.length) return;
        var next = cur.alt_hooks.shift();
        cur.alt_hooks.push(cur.hook);
        cur.hook = next;
        ui.toast(t('toast.hookRegen'), 'info');
        PL.App.rerender();
      });
      el.querySelectorAll('textarea[data-field]').forEach(function (ta) {
        ta.addEventListener('input', function () { if (cur) cur[ta.getAttribute('data-field')] = ta.value; });
      });
      var save = el.querySelector('[data-save-script]');
      if (save) save.addEventListener('click', function () { ui.toast(t('toast.scriptSaved'), 'success'); });
      var toQueue = el.querySelector('[data-to-queue]');
      if (toQueue) toQueue.addEventListener('click', function () {
        ui.confirm({
          title: t('dlg.sendProd_t'),
          body: ui.esc(t('dlg.sendProd_b')),
          confirmLabel: t('dlg.sendProd_ok'),
          onConfirm: function () {
            if (cur) {
              cur.status = 'approved';
              store.logKeyed('log.script_approved', { hook: cur.hook.slice(0, 40) });
            }
            ui.toast(t('toast.scriptApproved'), 'success');
            PL.App.rerender();
          }
        });
      });

      /* briefs */
      el.querySelectorAll('[data-mark-recorded]').forEach(function (b) {
        b.addEventListener('click', function () {
          var brief = store.find('briefs', b.getAttribute('data-mark-recorded'));
          brief.recorded = true;
          var job = PL.state.data.jobs.find(function (j) { return j.status === 'awaiting_recording' && j.workspace_id === ws.id; });
          if (job) { job.status = 'queued'; job.note_key = 'job.note_resume'; job.note_params = null; }
          store.logKeyed('log.recording_done', {});
          ui.toast(t('toast.briefRecorded'), 'success');
          PL.App.rerender();
        });
      });

      /* quick create tab is a pointer into the composer — handled by data-nav */
    }
  };
})();
