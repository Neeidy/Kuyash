/* Screen 12 — Settings: approval mode, guardrails, workspace, connections */
(function () {
  'use strict';
  var PL = window.Kuyash, ui = PL.ui, store = PL.store, t = PL.t;

  PL.screens.settings = {
    id: 'settings', icon: 'gear',

    render: function (el) {
      var ws = store.workspace();
      var mode = store.approvalMode();
      var gr = store.guardrails();
      var killed = PL.state.killSwitch[ws.id];

      el.innerHTML = `
        <header class="screen-head">
          <div><h1 class="vt-page-title">${ui.esc(t('nav.settings'))}</h1>
          <p class="screen-sub">${ui.esc(t('set.sub', { ws: ws.name }))}</p></div>
        </header>

        <div class="settings-grid">
          ${ui.card({
            title: t('set.secApproval'),
            body: `
              <div class="approval-choice ${mode === 'manual' ? 'is-active' : ''}" data-mode="manual" role="button" tabindex="0">
                ${ui.icon('users')}
                <div><strong>${ui.esc(t('set.manual'))} <span class="chip chip--ok">${ui.esc(t('set.default'))}</span></strong>
                  <p>${ui.esc(t('set.manual_b'))}</p></div>
                <span class="radio ${mode === 'manual' ? 'is-on' : ''}"></span>
              </div>
              <div class="approval-choice ${mode === 'auto' ? 'is-active' : ''}" data-mode="auto" role="button" tabindex="0">
                ${ui.icon('shield')}
                <div><strong>${ui.esc(t('set.auto'))}</strong>
                  <p>${ui.esc(t('set.auto_b'))}</p></div>
                <span class="radio ${mode === 'auto' ? 'is-on' : ''}"></span>
              </div>
              ${ui.note(t('set.truthNote'))}`
          })}

          ${ui.card({
            title: t('set.secGuard'),
            body: `
              <label class="field"><span>${ui.esc(t('set.dailyCap'))}</span>
                <input type="number" min="1" max="10" value="${gr.daily_cap_default}" data-gr-cap></label>
              <label class="field"><span>${ui.esc(t('set.budget'))}</span>
                <input type="number" min="50" step="50" value="${gr.budget_cap}" data-gr-budget></label>
              <label class="field"><span>${ui.esc(t('set.digest'))}</span>
                <select data-gr-digest>
                  <option value="daily_email" ${gr.digest === 'daily_email' ? 'selected' : ''}>${ui.esc(t('set.digestEmail'))}</option>
                  <option value="off" ${gr.digest === 'off' ? 'selected' : ''}>${ui.esc(t('set.digestOff'))}</option>
                </select></label>
              <div class="killswitch ${killed ? 'is-on' : ''}">
                <div class="killswitch__text">
                  <strong>${ui.icon('warning')}${ui.esc(t('set.kill'))}</strong>
                  <p>${ui.esc(t('set.kill_b'))}</p>
                </div>
                <button class="btn ${killed ? 'btn--primary' : 'btn--danger-ghost'}" data-killswitch>${ui.esc(t(killed ? 'set.killResume' : 'set.killPause'))}</button>
              </div>
              ${ui.note(t('set.fallbackNote'))}`
          })}

          ${ui.card({
            title: t('set.secWs'),
            body: `
              <label class="field"><span>${ui.esc(t('set.wsName'))}</span><input type="text" value="${ui.esc(ws.name)}" data-ws-name></label>
              <label class="field"><span>${ui.esc(t('set.wsNiche'))}</span><input type="text" value="${ui.esc(ws.niche)}" data-ws-niche></label>
              <div class="field"><span>${ui.esc(t('topbar.density'))}</span>
                <div class="seg" role="group" aria-label="${ui.esc(t('topbar.density'))}">
                  <button class="seg__btn ${document.documentElement.getAttribute('data-density') === 'compact' ? '' : 'is-active'}" data-density-set="comfortable">${ui.esc(t('density.comfortable'))}</button>
                  <button class="seg__btn ${document.documentElement.getAttribute('data-density') === 'compact' ? 'is-active' : ''}" data-density-set="compact">${ui.esc(t('density.compact'))}</button>
                </div></div>
              <button class="btn btn--primary btn--sm" data-ws-save>${ui.esc(t('set.wsSave'))}</button>`
          })}

          ${ui.card({
            title: t('set.secConn'),
            body: `<ul class="conn-list">
              ${PL.state.data.settings_integrations.map(function (c) {
                return `<li class="conn-row">
                  <div><strong>${ui.esc(t(c.name_key))}</strong><span class="faint">${ui.esc(t(c.status_key))}</span></div>
                  <span class="chip chip--faint">${ui.icon('lock')}${ui.esc(t('set.keyPh'))}</span>
                </li>`;
              }).join('')}
            </ul>
            <div class="callout callout--warn">${ui.icon('warning')}<div><strong>${ui.esc(t('set.connWarn_t'))}</strong> ${ui.esc(t('set.connWarn_b'))}</div></div>`
          })}

          ${ui.card({
            title: t('set.secSec'),
            body: `<ul class="stat-list">
              <li>${ui.icon('lock')}${ui.esc(t('set.sec1'))}</li>
              <li>${ui.icon('shield')}${ui.esc(t('set.sec2'))}</li>
              <li>${ui.icon('check')}${ui.esc(t('set.sec3'))}</li>
              <li>${ui.icon('eye')}${ui.esc(t('set.sec4'))}</li>
            </ul>`
          })}

          ${ui.card({
            title: t('set.secNotif'),
            body: ['set.n1', 'set.n2', 'set.n3', 'set.n4'].map(function (k, i) {
              return `<label class="switch-row"><span>${ui.esc(t(k))}</span><input type="checkbox" ${i !== 2 ? 'checked' : ''} data-notif></label>`;
            }).join('')
          })}
        </div>`;

      /* approval mode switching */
      el.querySelectorAll('[data-mode]').forEach(function (c) {
        var pick = function () {
          var target = c.getAttribute('data-mode');
          if (target === mode) return;
          if (target === 'auto') {
            ui.confirm({
              title: t('dlg.auto_t'),
              body: ui.esc(t('dlg.auto_b')),
              detail: ui.icon('shield') + ' ' + ui.esc(t('dlg.auto_d', { cap: gr.budget_cap })),
              confirmLabel: t('dlg.auto_ok'), danger: true,
              onConfirm: function () {
                store.setApprovalMode('auto');
                if (PL.state.killSwitch[ws.id]) {
                  /* kill switch gates ALL automation — Auto arms, nothing approves while paused */
                  ui.toast(t('toast.autoOnKilled'), 'info');
                } else {
                  store.byWorkspace('renders').forEach(function (r) {
                    if (r.status === 'awaiting_approval' && r.risk === 'low') {
                      r.status = 'ready';
                      r.approval = { mode: 'auto', label_key: 'badge.autoApproved', at: { day: 'now' } };
                      store.audit('log.auto_approved', { render: r.id, slop: r.compliance ? r.compliance.slop_score : '?' });
                    }
                  });
                  ui.toast(t('toast.autoOn'), 'success');
                }
                PL.App.rerender();
              }
            });
          } else {
            store.setApprovalMode('manual');
            ui.toast(t('toast.manualOn'), 'info');
            PL.App.rerender();
          }
        };
        c.addEventListener('click', pick);
        c.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); } });
      });

      /* guardrails */
      el.querySelector('[data-gr-cap]').addEventListener('change', function (e) {
        var v = parseInt(e.target.value, 10);
        if (!v || v < 1) { e.target.value = gr.daily_cap_default; ui.toast(t('toast.capInvalid'), 'danger'); return; }
        gr.daily_cap_default = v;
        store.audit('log.cap_set', { n: v, ws: ws.name }, 'info', 'guardrail');
        ui.toast(t('toast.capSaved'), 'success');
      });
      el.querySelector('[data-gr-budget]').addEventListener('change', function (e) {
        var v = parseInt(e.target.value, 10);
        if (!v || v < 1) { e.target.value = gr.budget_cap; ui.toast(t('toast.budgetInvalid'), 'danger'); return; }
        gr.budget_cap = v;
        store.credits().budget_cap = v;
        store.audit('log.budget_set', { n: v, ws: ws.name }, 'info', 'guardrail');
        ui.toast(t('toast.budgetSaved'), 'success');
      });
      el.querySelector('[data-gr-digest]').addEventListener('change', function (e) {
        gr.digest = e.target.value;
        store.audit('log.digest_set', { v: e.target.value, ws: ws.name }, 'info', 'guardrail');
        ui.toast(t('toast.digestSaved'), 'success');
      });

      el.querySelector('[data-killswitch]').addEventListener('click', function () {
        if (!killed) {
          ui.confirm({
            title: t('dlg.kill_t'),
            body: ui.esc(t('dlg.kill_b', { ws: ws.name })),
            confirmLabel: t('dlg.kill_ok'), danger: true,
            onConfirm: function () {
              PL.state.killSwitch[ws.id] = true;
              store.audit('log.kill_on', { ws: ws.name }, 'warn', 'guardrail');
              ui.toast(t('toast.killOn'), 'danger');
              PL.App.rerender();
            }
          });
        } else {
          PL.state.killSwitch[ws.id] = false;
          store.audit('log.kill_off', { ws: ws.name }, 'info', 'guardrail');
          ui.toast(t('toast.killOff'), 'success');
          PL.App.rerender();
        }
      });

      el.querySelector('[data-ws-save]').addEventListener('click', function () {
        ws.name = el.querySelector('[data-ws-name]').value.trim() || ws.name;
        ws.niche = el.querySelector('[data-ws-niche]').value.trim() || ws.niche;
        ui.toast(t('toast.wsSaved'), 'success');
        PL.App.rerender();
      });

      /* density (comfortable / compact) — persisted like the language pref */
      el.querySelectorAll('[data-density-set]').forEach(function (b) {
        b.addEventListener('click', function () {
          var d = b.getAttribute('data-density-set');
          PL.App.setDensity(d);
          ui.toast(t('toast.density', { mode: t('density.' + d) }), 'info');
          PL.App.rerender();
        });
      });

      el.querySelectorAll('[data-notif]').forEach(function (n) {
        n.addEventListener('change', function () { ui.toast(t('toast.notifSaved'), 'info'); });
      });
    }
  };
})();
