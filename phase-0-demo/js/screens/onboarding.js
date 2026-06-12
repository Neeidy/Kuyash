/* Screen 13 — Onboarding Wizard. The one cinematic flow (allowed exception):
   staggered scene builds, progress choreography. Mock data follows the ACTIVE
   workspace so the wizard never contradicts the rest of the demo. */
(function () {
  'use strict';
  var PL = window.Kuyash, ui = PL.ui, store = PL.store, t = PL.t;

  PL.screens.onboarding = {
    id: 'onboarding', icon: 'rocket',

    render: function (el) {
      var ws = store.workspace();
      var steps = PL.state.data.onboarding.steps;
      var idx = PL.state.onboardingStep;
      var ob = PL.state.onboarding;
      var step = steps[idx];
      /* workspace-consistent sample content (followup #2) */
      var sampleTrend = store.byWorkspace('trends')[0];
      var sampleScript = store.byWorkspace('scripts')[0];

      function stepBody() {
        switch (step.id) {
          case 'workspace':
            return `<label class="field"><span>${ui.esc(t('ob.wsLabel'))}</span>
              <input type="text" placeholder="${ui.esc(t('ob.wsPh'))}" value="${ui.esc(ob.workspace)}" data-ob-wsname></label>
              ${ui.note(t('ob.wsNote'))}`;
          case 'account':
            if (ob.oauth === 'connected') {
              return `<div class="callout callout--info">${ui.icon('check')}<div><strong>${ui.esc(t('ob.connected_t', { platform: ui.platformName(ob.platform) }))}</strong> ${ui.esc(t('ob.connected_b'))}</div></div>`;
            }
            if (ob.oauth === 'consent') {
              return `<div class="oauth-mock">
                <div class="oauth-mock__head">${ui.platformIcon(ob.platform)}<strong>${ui.esc(ui.platformName(ob.platform))}</strong> · ${ui.esc(t('ob.consent'))}</div>
                <p>${ui.esc(t('ob.asking'))}</p>
                ${ui.note(t('ob.pwNote'))}
                <div class="modal__foot" style="padding:12px 0 0">
                  <button class="btn btn--ghost" data-ob-oauth-deny>${ui.esc(t('common.deny'))}</button>
                  <button class="btn btn--primary" data-ob-oauth-ok>${ui.esc(t('dlg.oauth_ok'))}</button>
                </div></div>`;
            }
            return `<p class="muted">${ui.esc(t('ob.pickPlatform'))}</p>
              <div class="platform-pick">
                ${['instagram', 'tiktok', 'youtube'].map(function (p) {
                  return `<button class="platform-pick__btn ${ob.platform === p ? 'is-active' : ''}" data-ob-platform="${p}">${ui.platformIcon(p)}<span>${ui.platformName(p)}</span></button>`;
                }).join('')}
              </div>`;
          case 'niche':
            return `<p class="muted">${ui.esc(t('ob.nicheNote'))}</p>
              <div class="chip-row chip-row--wrap">
                ${PL.state.data.onboarding.niche_options.map(function (n) {
                  return `<button class="fchip ${ob.niche === n ? 'is-active' : ''}" data-ob-niche="${ui.esc(n)}">${ui.esc(n)}</button>`;
                }).join('')}
              </div>`;
          case 'trend':
            return `<article class="trend-card trend-card--solo">
              <div class="trend-card__top">${ui.sourceBadge(sampleTrend.source)}<span class="trend-card__fresh mono">${ui.icon('clock')}${ui.esc(PL.fmt.ago(sampleTrend.freshness))}</span></div>
              <h3>${ui.esc(sampleTrend.title)}</h3>
              <div class="trend-card__meta">${ui.velocityBadge(sampleTrend)}
                ${ui.sparkbars(sampleTrend.spark)}
                ${ui.faceBadge(sampleTrend.recommended_format)}</div>
              <p class="trend-card__angle">${ui.esc(sampleTrend.angle)}</p>
            </article>
            ${ob.trendReviewed ? '<div class="callout callout--info">' + ui.icon('check') + '<div>' + ui.esc(t('ob.trendDone')) + '</div></div>'
              : '<button class="btn btn--primary" data-ob-trend>' + ui.esc(t('ob.useTrend')) + '</button>'}`;
          case 'content':
            return ob.contentCreated
              ? `<div class="callout callout--info">${ui.icon('check')}<div><strong>${ui.esc(t('ob.draftDone'))}</strong></div></div>
                 <div class="panel ob-script">
                   <p><strong>${ui.esc(t('ob.draftHook'))}</strong> “${ui.esc(sampleScript.hook)}”</p>
                   <p><strong>${ui.esc(t('ob.draftCaption'))}</strong> ${ui.esc(sampleScript.captions.instagram)}</p>
                   ${ui.note(t('ob.draftNote'))}
                 </div>`
              : `<p class="muted">${ui.esc(t('ob.contentNote'))}</p>
                 <button class="btn btn--primary" data-ob-content>${ui.icon('sparkle')}${ui.esc(t('ob.genDraft'))}</button>`;
          case 'testpost':
            if (ob.testRun === 'done') {
              return `<div class="callout callout--info">${ui.icon('check')}<div><strong>${ui.esc(t('ob.done_t'))}</strong> ${ui.esc(t('ob.done_b'))}</div></div>`;
            }
            if (ob.testRun === 'running') {
              return ui.loadingState();
            }
            return `<p class="muted">${ui.esc(t('ob.testNote'))}</p>
              <button class="btn btn--primary" data-ob-test>${ui.icon('play')}${ui.esc(t('ob.runTest'))}</button>`;
        }
        return '';
      }

      function canNext() {
        switch (step.id) {
          case 'workspace': return ob.workspace.trim().length > 0;
          case 'account': return ob.oauth === 'connected';
          case 'niche': return !!ob.niche;
          case 'trend': return ob.trendReviewed;
          case 'content': return ob.contentCreated;
          case 'testpost': return ob.testRun === 'done';
        }
        return true;
      }

      el.innerHTML = `
        <header class="screen-head">
          <div><h1 class="vt-page-title">${ui.esc(t('nav.onboarding'))}</h1>
          <p class="screen-sub">${ui.esc(t('ob.sub'))}</p></div>
          <button class="btn btn--ghost btn--sm" data-ob-reset>${ui.icon('refresh')}${ui.esc(t('ob.restart'))}</button>
        </header>

        <div class="wizard panel">
          <ol class="wizard__steps stagger">
            ${steps.map(function (s, i) {
              var cls = i < idx ? 'is-done' : i === idx ? 'is-current' : '';
              return `<li class="${cls}"><span class="wizard__num mono">${i < idx ? '✓' : i + 1}</span><span class="wizard__label">${ui.esc(t(s.t))}</span></li>`;
            }).join('')}
          </ol>

          <div class="wizard__body">
            <h2>${ui.esc(t(step.t))}</h2>
            <p class="screen-sub">${ui.esc(t(step.d))}</p>
            <div class="wizard__content stagger">${stepBody()}</div>
          </div>

          <footer class="wizard__foot">
            <button class="btn btn--ghost" data-ob-back ${idx === 0 ? 'disabled' : ''}>${ui.esc(t('common.back'))}</button>
            <span class="faint mono">${ui.esc(t('ob.stepOf', { i: idx + 1, n: steps.length }))}</span>
            ${idx === steps.length - 1
              ? `<button class="btn btn--primary" data-ob-finish ${canNext() ? '' : 'disabled'}>${ui.icon('rocket')}${ui.esc(t('common.finish'))}</button>`
              : `<button class="btn btn--primary" data-ob-next ${canNext() ? '' : 'disabled'}>${ui.esc(t('common.next'))} ${ui.icon('chevR')}</button>`}
          </footer>
        </div>`;

      el.querySelectorAll('.stagger').forEach(PL.motion.stagger);

      el.querySelector('[data-ob-back]').addEventListener('click', function () {
        if (idx > 0) { PL.state.onboardingStep = idx - 1; PL.App.rerender(); }
      });
      var next = el.querySelector('[data-ob-next]');
      if (next) next.addEventListener('click', function () {
        PL.state.onboardingStep = idx + 1; PL.App.rerender();
      });
      var finish = el.querySelector('[data-ob-finish]');
      if (finish) finish.addEventListener('click', function () {
        ui.toast(t('toast.obDone'), 'success');
        location.hash = '#/dashboard';
      });
      el.querySelector('[data-ob-reset]').addEventListener('click', function () {
        PL.state.onboardingStep = 0;
        PL.state.onboarding = { workspace: '', platform: null, oauth: 'idle', niche: null, trendReviewed: false, contentCreated: false, testRun: 'idle' };
        PL.App.rerender();
      });

      var wsName = el.querySelector('[data-ob-wsname]');
      if (wsName) wsName.addEventListener('input', function () {
        ob.workspace = wsName.value;
        var n = el.querySelector('[data-ob-next]');
        if (n) n.disabled = !canNext();
      });
      el.querySelectorAll('[data-ob-platform]').forEach(function (b) {
        b.addEventListener('click', function () {
          ob.platform = b.getAttribute('data-ob-platform'); ob.oauth = 'consent'; PL.App.rerender();
        });
      });
      var ok = el.querySelector('[data-ob-oauth-ok]');
      if (ok) ok.addEventListener('click', function () { ob.oauth = 'connected'; PL.App.rerender(); });
      var deny = el.querySelector('[data-ob-oauth-deny]');
      if (deny) deny.addEventListener('click', function () { ob.oauth = 'idle'; ob.platform = null; PL.App.rerender(); });
      el.querySelectorAll('[data-ob-niche]').forEach(function (b) {
        b.addEventListener('click', function () { ob.niche = b.getAttribute('data-ob-niche'); PL.App.rerender(); });
      });
      var tr = el.querySelector('[data-ob-trend]');
      if (tr) tr.addEventListener('click', function () { ob.trendReviewed = true; PL.App.rerender(); });
      var ct = el.querySelector('[data-ob-content]');
      if (ct) ct.addEventListener('click', function () { ob.contentCreated = true; PL.App.rerender(); });
      var tp = el.querySelector('[data-ob-test]');
      if (tp) tp.addEventListener('click', function () {
        ob.testRun = 'running'; PL.App.rerender();
        setTimeout(function () {
          if (PL.state.onboarding.testRun === 'running') {
            PL.state.onboarding.testRun = 'done';
            if (PL.state.route === 'onboarding') PL.App.rerender();
          }
        }, 1600);
      });
    }
  };
})();
