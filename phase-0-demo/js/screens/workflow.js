/* Screen 5 — Workflow Builder. Canonical nodes, fixed linear layout — NOT n8n. */
(function () {
  'use strict';
  var PL = window.Kuyash, ui = PL.ui, store = PL.store, t = PL.t;

  var runTimer = null;

  function nodeDef(id) {
    var wf = PL.state.data.workflow;
    if (id === 'LIBRARY') return wf.library_node;
    return wf.nodes.find(function (n) { return n.id === id; });
  }
  function currentTemplate() {
    return PL.state.data.workflow.templates[PL.state.workflowTemplate];
  }
  function visualsNode() { return nodeDef('VISUALS'); }

  function pushHistory(entry) {
    PL.state.workflowHistory.push(entry);
    PL.state.workflowFuture = [];
  }

  function settingsPanel(sel) {
    var n = nodeDef(sel);
    if (!n) return '';
    var rows = Object.keys(n.settings).map(function (k) {
      return ui.kv(k.replace(/_/g, ' '), ui.esc(n.settings[k]));
    }).join('');

    var extra = '';
    if (n.id === 'VISUALS') {
      extra = `<div class="field"><span class="field__label">${ui.esc(t('wf.visualsSource'))}</span>
        <div class="seg" role="group" aria-label="${ui.esc(t('wf.visualsSource'))}">
          ${n.source_options.map(function (o) {
            return `<button class="seg__btn ${n.settings.source === o ? 'is-active' : ''}" data-visuals-source="${o}">${o}</button>`;
          }).join('')}
        </div></div>` +
        (n.settings.source === 'AI' ? `<div class="callout callout--ai">${ui.icon('sparkle')}<div>${ui.esc(t('wf.aiNote'))}</div></div>` : '') +
        (n.settings.source === 'LIBRARY' ? ui.note(t('wf.libNote')) : '') +
        (n.settings.source === 'STOCK' ? ui.note(t('wf.stockNote')) : '');
    }
    if (n.locked) {
      extra = `<div class="callout callout--info">${ui.icon('lock')}<div><strong>${ui.esc(t('wf.locked_t'))}</strong> ${ui.esc(t('wf.locked_b'))}</div></div>`;
    }
    if (n.id === 'MUSIC NOTE / STYLE') {
      extra = `<div class="callout callout--warn">${ui.icon('warning')}<div>${ui.esc(t('wf.music_b'))}</div></div>`;
    }

    return `<h3 class="wf-panel__title mono">${ui.esc(n.id)} ${n.locked ? ui.icon('lock') : ''}</h3>
      <p class="wf-panel__desc">${ui.esc(t(n.dkey))}</p>
      <dl class="kv-list">${rows}</dl>${extra}`;
  }

  PL.screens.workflow = {
    id: 'workflow', icon: 'workflow',

    render: function (el) {
      if (runTimer) { clearInterval(runTimer); runTimer = null; }
      var tpl = PL.state.workflowTemplate;
      var nodes = currentTemplate();
      var sel = PL.state.workflowSelected;
      if (nodes.indexOf(sel) === -1) sel = PL.state.workflowSelected = 'COMPLIANCE';
      var vis = visualsNode();

      el.innerHTML = `
        <header class="screen-head">
          <div><h1 class="vt-page-title">${ui.esc(t('nav.workflow'))}</h1>
          <p class="screen-sub">${ui.esc(t('wf.sub'))}</p></div>
          <div class="seg" role="group">
            <button class="seg__btn ${tpl === 'full' ? 'is-active' : ''}" data-tpl="full">${ui.esc(t('wf.tplFull'))}</button>
            <button class="seg__btn ${tpl === 'distribution' ? 'is-active' : ''}" data-tpl="distribution">${ui.esc(t('wf.tplDist'))}</button>
          </div>
        </header>

        <div class="wf-toolbar panel">
          <button class="btn btn--ghost btn--sm" data-wf-undo ${PL.state.workflowHistory.length ? '' : 'disabled'}>${ui.esc(t('common.undo'))}</button>
          <button class="btn btn--ghost btn--sm" data-wf-redo ${PL.state.workflowFuture.length ? '' : 'disabled'}>${ui.esc(t('common.redo'))}</button>
          <span class="wf-toolbar__spacer"></span>
          <button class="btn btn--ghost btn--sm" data-wf-json>${ui.icon('list')}${ui.esc(t('wf.json'))}</button>
          <button class="btn btn--ghost btn--sm" data-wf-save>${ui.esc(t('common.save'))}</button>
          <button class="btn btn--primary btn--sm" data-wf-run>${ui.icon('play')}${ui.esc(t('wf.run'))}</button>
        </div>

        <div class="wf-layout">
          <div class="wf-canvas">
            <div class="node-track">
              ${nodes.map(function (id) {
                var n = nodeDef(id);
                return `<div class="node-wrap">
                  <button class="node ${sel === id ? 'is-selected' : ''} ${n.locked ? 'node--locked' : ''}" data-node="${ui.esc(id)}">
                    <span class="node__status" data-node-status="${ui.esc(id)}"></span>
                    <span class="node__name mono">${ui.esc(id)}${n.locked ? ui.icon('lock', 'node__lock') : ''}</span>
                    <span class="node__desc">${ui.esc(id === 'VISUALS' ? t('wf.sourcePrefix', { src: vis.settings.source }) : t(n.dkey))}</span>
                  </button>
                  <span class="node-connector" aria-hidden="true"></span>
                </div>`;
              }).join('')}
            </div>
            <pre class="wf-json mono" data-wf-json-panel hidden></pre>
          </div>

          <aside class="wf-panel panel">
            <div data-wf-settings>${settingsPanel(sel)}</div>
            <div class="wf-preview">
              ${ui.sectionHead(t('wf.outTitle'))}
              <ul class="stat-list">
                <li>${ui.icon('clock')}${ui.esc(t('wf.out1'))}</li>
                <li>${ui.icon('phone')}${ui.esc(t('wf.out2'))}</li>
                <li>${ui.icon('shield')}${ui.esc(t('wf.out3'))}</li>
                <li>${ui.icon('rocket')}${ui.esc(t('wf.out4'))}</li>
              </ul>
            </div>
          </aside>
        </div>`;

      /* keep the selected node visible — but only after a USER pick; the first
         paint must show the pipeline from TREND, not scrolled to the tail */
      if (PL.state.workflowUserPicked) {
        var selEl = el.querySelector('.node.is-selected');
        if (selEl && selEl.scrollIntoView) selEl.scrollIntoView({ inline: 'center', block: 'nearest' });
      }

      el.querySelectorAll('[data-node]').forEach(function (b) {
        b.addEventListener('click', function () {
          PL.state.workflowSelected = b.getAttribute('data-node');
          PL.state.workflowUserPicked = true;
          PL.App.rerender();
        });
      });

      el.querySelectorAll('[data-tpl]').forEach(function (b) {
        b.addEventListener('click', function () {
          var to = b.getAttribute('data-tpl');
          if (to === PL.state.workflowTemplate) return;
          pushHistory({ field: 'template', from: PL.state.workflowTemplate, to: to });
          PL.state.workflowTemplate = to;
          PL.App.rerender();
        });
      });

      el.querySelectorAll('[data-visuals-source]').forEach(function (b) {
        b.addEventListener('click', function () {
          var to = b.getAttribute('data-visuals-source');
          var n = visualsNode();
          if (n.settings.source === to) return;
          pushHistory({ field: 'visuals', from: n.settings.source, to: to });
          n.settings.source = to;
          PL.App.rerender();
        });
      });

      function applyEntry(e, dir) {
        var val = dir === 'undo' ? e.from : e.to;
        if (e.field === 'template') PL.state.workflowTemplate = val;
        if (e.field === 'visuals') visualsNode().settings.source = val;
      }
      el.querySelector('[data-wf-undo]').addEventListener('click', function () {
        var e = PL.state.workflowHistory.pop();
        if (!e) return;
        applyEntry(e, 'undo'); PL.state.workflowFuture.push(e); PL.App.rerender();
      });
      el.querySelector('[data-wf-redo]').addEventListener('click', function () {
        var e = PL.state.workflowFuture.pop();
        if (!e) return;
        applyEntry(e, 'redo'); PL.state.workflowHistory.push(e); PL.App.rerender();
      });

      el.querySelector('[data-wf-save]').addEventListener('click', function () {
        ui.toast(t('toast.wfSaved'), 'success');
      });
      el.querySelector('[data-wf-json]').addEventListener('click', function () {
        var panel = el.querySelector('[data-wf-json-panel]');
        if (panel.hidden) {
          var summary = {
            template: PL.state.workflowTemplate,
            nodes: currentTemplate().map(function (id) {
              var n = nodeDef(id);
              return { node: id, locked: !!n.locked, settings: n.settings };
            })
          };
          panel.textContent = JSON.stringify(summary, null, 2);
          panel.hidden = false;
        } else panel.hidden = true;
      });

      /* run test — status dots travel the line */
      el.querySelector('[data-wf-run]').addEventListener('click', function () {
        if (runTimer) return;
        var ids = currentTemplate().slice();
        var i = 0;
        ui.toast(t('toast.testStart'), 'info');
        el.querySelectorAll('[data-node-status]').forEach(function (s) { s.className = 'node__status'; });
        runTimer = setInterval(function () {
          var dots = el.querySelectorAll('[data-node-status]');
          if (!dots.length) { clearInterval(runTimer); runTimer = null; return; }
          if (i > 0) {
            var prev = el.querySelector('[data-node-status="' + ids[i - 1] + '"]');
            if (prev) prev.className = 'node__status is-done';
          }
          if (i >= ids.length) {
            clearInterval(runTimer); runTimer = null;
            ui.toast(t('toast.testDone'), 'success');
            return;
          }
          var curDot = el.querySelector('[data-node-status="' + ids[i] + '"]');
          if (curDot) curDot.className = 'node__status is-running pulse';
          i++;
        }, 420);
      });
    }
  };
})();
