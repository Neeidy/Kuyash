/* Screen 14 — Create Composer (iteration 2 §D): the primary manual pipeline
   entry. Media → 3-mode prompt (Claude-assisted / ChatGPT-assisted / manual)
   → settings → cost pre-flight (credit gate + mandatory AI label) → launch.
   EVERYTHING is mock: assistants are canned (the real TextProvider adapter —
   OpenAI + optional Anthropic — arrives in Phase 5); the cost model is the
   Phase 11 pre-flight's mock; nothing is uploaded or generated. */
(function () {
  'use strict';
  var PL = window.Kuyash, ui = PL.ui, store = PL.store, t = PL.t;

  /* provider-agnostic assist config — labels only, no vendor API shapes */
  var MODES = [
    { id: 'claude', labelKey: 'create.modeClaude', assisted: true },
    { id: 'chatgpt', labelKey: 'create.modeGpt', assisted: true },
    { id: 'manual', labelKey: 'create.modeManual', assisted: false }
  ];
  var STEPS = ['create.s1', 'create.s2', 'create.s3', 'create.s4'];

  function fresh() {
    return {
      step: 0,
      media: null, /* { kind:'upload'|'library'|'stock', label, assetId, thumb } */
      stockQuery: '',
      mode: 'claude', chat: [], typing: false,
      intent: '', prompt: '',
      platforms: { instagram: true, tiktok: true, youtube: true },
      duration: 30, voice: true, music: ''
    };
  }
  function c() {
    if (!PL.state.composer) PL.state.composer = fresh();
    return PL.state.composer;
  }

  function estimate(s) {
    var m = PL.state.data.quick_create.cost_model;
    var chars = s.voice ? Math.round(s.duration * 12) : 0;
    var nPlat = Object.keys(s.platforms).filter(function (p) { return s.platforms[p]; }).length;
    var tts = s.voice ? Math.ceil(chars / 100 * m.tts_per_100chars) : 0;
    var video = Math.ceil(s.duration * m.video_per_sec);
    var pub = nPlat * m.publish_per_platform;
    return { base: m.base, chars: chars, tts: tts, video: video, pub: pub, nPlat: nPlat,
      total: m.base + tts + video + pub };
  }

  /* ---------- step bodies ---------- */

  function mediaStep(s) {
    /* only ready assets are valid composer inputs */
    var assets = store.byWorkspace('assets').filter(function (a) { return a.status === 'ready'; });
    var stock = assets.filter(function (a) {
      return a.type === 'stock' &&
        (!s.stockQuery || (a.title + a.tags.join(' ')).toLowerCase().indexOf(s.stockQuery.toLowerCase()) !== -1);
    });
    function grid(list, kind) {
      if (!list.length) return '<p class="faint" style="font-size:12px">' + ui.esc(t('library.noMatch_t')) + '</p>';
      return '<div class="cmp-media-grid">' + list.map(function (a) {
        var sel = s.media && s.media.assetId === a.id;
        return '<button class="cmp-media' + (sel ? ' is-selected' : '') + '" data-pick-asset="' + a.id + '" data-pick-kind="' + kind + '">' +
          ui.thumb(a.thumb, a.duration_s + 's') +
          '<span>' + ui.esc(a.title) + '</span></button>';
      }).join('') + '</div>';
    }
    return `
      <div class="cmp-cols">
        <div class="panel">
          <h3 class="cmp-h">${ui.esc(t('create.mediaUpload'))}</h3>
          <span class="upload-box" data-cmp-upbox tabindex="0">${ui.icon('upload')}
            <span data-cmp-uplabel>${ui.esc(s.media && s.media.kind === 'upload' ? s.media.label : t('studio.qcChoose'))}</span>
            <input type="file" accept="image/*,video/*" hidden data-cmp-file></span>
          ${ui.note(t('create.mediaUploadNote'))}
        </div>
        <div class="panel">
          <h3 class="cmp-h">${ui.esc(t('create.mediaLibrary'))}</h3>
          ${grid(assets.filter(function (a) { return a.type !== 'stock'; }), 'library')}
        </div>
        <div class="panel">
          <h3 class="cmp-h">${ui.esc(t('create.mediaStock'))}</h3>
          <label class="search" style="max-width:none;margin-bottom:var(--s3)">${ui.icon('search')}
            <input type="search" data-search placeholder="${ui.esc(t('create.stockPh'))}" value="${ui.esc(s.stockQuery)}"></label>
          ${grid(stock, 'stock')}
          ${ui.note(t('create.stockNote'))}
        </div>
      </div>
      ${s.media
        ? `<div class="callout callout--info">${ui.icon('check')}<div><strong>${ui.esc(t('create.selected'))}:</strong> ${ui.esc(s.media.label)}</div></div>`
        : `<p class="faint cmp-hint">${ui.esc(t('create.noMedia'))}</p>`}`;
  }

  function promptStep(s) {
    var mode = MODES.find(function (m) { return m.id === s.mode; });
    var chatHtml = '';
    if (mode.assisted) {
      var msgs = s.chat.filter(function (m) { return m.mode === s.mode; });
      chatHtml = `
        <div class="cmp-chat">
          <div class="cmp-chat__log">
            <div class="cmp-msg cmp-msg--assistant"><span class="cmp-msg__who mono">${ui.esc(t(mode.labelKey))}</span>
              <p>${ui.esc(t('create.assistIntro'))}</p></div>
            ${msgs.map(function (m) {
              return m.role === 'user'
                ? `<div class="cmp-msg cmp-msg--user"><p>${ui.esc(m.text)}</p></div>`
                : `<div class="cmp-msg cmp-msg--assistant"><span class="cmp-msg__who mono">${ui.esc(t(mode.labelKey))}</span>
                    <p>${ui.esc(t('create.assistAck'))}</p>
                    <code class="cmp-msg__prompt">${ui.esc(m.text)}</code></div>`;
            }).join('')}
            ${s.typing ? `<div class="cmp-msg cmp-msg--assistant cmp-msg--typing"><span class="cmp-msg__who mono">${ui.esc(t(mode.labelKey))}</span>
              <p>${ui.esc(t('create.assistTyping'))}<span class="term__caret"></span></p></div>` : ''}
          </div>
          <div class="cmp-chat__inputrow">
            <textarea rows="2" data-cmp-intent placeholder="${ui.esc(t('create.intentPh'))}">${ui.esc(s.intent)}</textarea>
            <button class="btn btn--primary btn--sm" data-cmp-refine ${s.typing ? 'disabled' : ''}>${ui.icon('sparkle')}${ui.esc(t('create.assistSend'))}</button>
          </div>
        </div>
        ${ui.note(t('create.assistNote'))}`;
    }
    return `
      <div class="seg" role="group">
        ${MODES.map(function (m) {
          return `<button class="seg__btn ${s.mode === m.id ? 'is-active' : ''}" data-cmp-mode="${m.id}">${ui.esc(t(m.labelKey))}</button>`;
        }).join('')}
      </div>
      ${chatHtml}
      <label class="field" style="margin-top:var(--s4)"><span>${ui.esc(t('create.promptLabel'))}</span>
        <textarea rows="4" data-cmp-prompt placeholder="${ui.esc(t('create.promptPh'))}">${ui.esc(s.prompt)}</textarea></label>`;
  }

  function settingsStep(s) {
    return `
      <div class="cmp-cols cmp-cols--2">
        <div class="panel">
          <label class="field"><span>${ui.esc(t('create.platforms'))}</span></label>
          ${['instagram', 'tiktok', 'youtube'].map(function (p) {
            return `<label class="switch-row">${ui.platformIcon(p)} <span style="flex:1">${ui.platformName(p)}</span>
              <input type="checkbox" data-cmp-platform="${p}" ${s.platforms[p] ? 'checked' : ''}></label>`;
          }).join('')}
          <label class="field" style="margin-top:var(--s4)">
            <span>${ui.esc(t('create.durLabel'))} <b class="mono num" data-cmp-durval>${s.duration}s</b></span>
            <input type="range" min="15" max="45" step="1" value="${s.duration}" data-cmp-dur></label>
        </div>
        <div class="panel">
          <label class="switch-row"><span>${ui.esc(t('create.voice'))}</span>
            <input type="checkbox" data-cmp-voice ${s.voice ? 'checked' : ''}></label>
          <label class="field" style="margin-top:var(--s4)"><span>${ui.esc(t('create.musicNote'))}</span>
            <input type="text" data-cmp-music value="${ui.esc(s.music)}" placeholder="${ui.esc(t('create.musicPh'))}"></label>
          ${ui.note(t('wf.music_b'))}
        </div>
      </div>`;
  }

  function preflightStep(s) {
    var est = estimate(s);
    var cr = store.credits();
    var blocked = est.total > cr.balance;
    var noPlatform = est.nPlat === 0;
    return `
      <div class="cmp-cols cmp-cols--2">
        <div class="panel">
          <h3 class="cmp-h">${ui.esc(t('create.estimate'))}</h3>
          <dl class="kv-list">
            ${ui.kv(t('create.estBase'), '<b class="num">' + est.base + '</b>')}
            ${s.voice ? ui.kv(t('create.estTts', { n: est.chars }), '<b class="num">' + est.tts + '</b>') : ''}
            ${ui.kv(t('create.estVideo', { n: s.duration }), '<b class="num">' + est.video + '</b>')}
            ${ui.kv(t('create.estPub', { n: est.nPlat }), '<b class="num">' + est.pub + '</b>')}
          </dl>
          <div class="cmp-total"><span>${ui.esc(t('create.estTotal'))}</span>
            <b class="mono num">${est.total}</b></div>
          ${ui.note(t('create.estNote'))}
        </div>
        <div class="panel">
          <div class="callout callout--ai">${ui.icon('sparkle')}<div><strong>${ui.esc(t('studio.qcAi_t'))}</strong><br>${ui.esc(t('studio.qcAi_b'))}</div></div>
          ${blocked
            ? `<div class="callout callout--err">${ui.icon('warning')}<div><strong>${ui.esc(t('studio.qcInsuf_t'))}</strong><br>
                ${ui.esc(t('studio.qcInsuf_b', { bal: cr.balance, cost: est.total }))}</div></div>`
            : `<div class="callout callout--info">${ui.icon('credits')}<div>${ui.esc(t('studio.qcBalance', { n: cr.balance }))}</div></div>`}
          ${noPlatform ? `<div class="callout callout--warn">${ui.icon('warning')}<div>${ui.esc(t('create.noPlatform'))}</div></div>` : ''}
          <button class="btn btn--primary btn--lg" data-cmp-launch ${blocked || noPlatform ? 'disabled title="' + ui.esc(t('create.launchBlocked')) + '"' : ''}>
            ${ui.icon('rocket')}${ui.esc(t('create.launch'))}</button>
        </div>
      </div>`;
  }

  function runTitle(s) {
    var base = (s.intent || s.prompt || '').replace(/\s+/g, ' ').trim();
    return base ? base.slice(0, 42) : t('create.untitled');
  }

  function launch(s) {
    var est = estimate(s);
    var cr = store.credits();
    if (est.nPlat === 0) { ui.toast(t('create.noPlatform'), 'danger'); return; }
    if (est.total > cr.balance) { ui.toast(t('toast.qcRefused'), 'danger'); return; }
    ui.confirm({
      title: t('dlg.launch_t'),
      body: ui.esc(t('dlg.launch_b', { n: est.total })),
      detail: ui.icon('sparkle') + ' ' + ui.esc(t('studio.qcAi_b')),
      confirmLabel: t('dlg.qc_ok', { n: est.total }),
      onConfirm: function () {
        var ws = store.workspace();
        var title = runTitle(s);
        var rnd = function () { return Math.random().toString(36).slice(2, 6); };
        /* queue the mock chain — newest first so the run animates in on top */
        var chain = [
          { type: 'compliance_check', entity: title, max_retries: 1 },
          { type: 'assembly', entity: title, max_retries: 2, note_key: 'job.note_ffmpeg' },
          s.voice ? { type: 'tts', entity: title + ' voiceover', max_retries: 3 } : null,
          { type: 'ai_video_generation', entity: title, max_retries: 1, cost_cents: est.video * 10,
            idempotency_key: 'aivid_run_' + rnd(), note_key: 'job.note_qc' }
        ].filter(Boolean);
        chain.forEach(function (spec) {
          PL.state.data.jobs.unshift({
            id: 'jb_x' + rnd(), workspace_id: ws.id, type: spec.type, entity: spec.entity,
            status: 'queued', retry_count: 0, max_retries: spec.max_retries,
            cost_cents: spec.cost_cents, idempotency_key: spec.idempotency_key, note_key: spec.note_key
          });
        });
        cr.balance -= est.total;
        cr.used_this_month += est.total;
        cr.used_today = (cr.used_today || 0) + est.total;
        cr.history.unshift({ at: { day: 'now' }, label_key: 'usage.hVideo', label_params: { what: title }, amount: -est.total });
        store.logKeyed('log.run_launched', { title: title, cost: est.total });
        ui.toast(t('toast.runLaunched'), 'success');
        PL.state.composer = null;
        location.hash = '#/queue';
      }
    });
  }

  /* ---------- screen ---------- */
  PL.screens.create = {
    id: 'create', icon: 'plus',

    render: function (el) {
      var s = c();
      var canNext = s.step === 0 ? !!s.media : s.step === 1 ? !!s.prompt.trim() : true;

      el.innerHTML = `
        <header class="screen-head">
          <div><h1 class="vt-page-title">${ui.esc(t('create.title'))}</h1>
          <p class="screen-sub">${ui.esc(t('create.sub'))}</p></div>
        </header>

        <ol class="steps" aria-label="${ui.esc(t('create.title'))}">
          ${STEPS.map(function (key, i) {
            var cls = i < s.step ? 'is-done' : i === s.step ? 'is-current' : '';
            return `<li class="step ${cls}" ${i < s.step ? 'data-step-go="' + i + '" tabindex="0" role="button"' : ''}>
              <span class="step__num mono">${i < s.step ? '✓' : i + 1}</span>${ui.esc(t(key))}</li>`;
          }).join('')}
        </ol>

        <div class="cmp-step">
          ${s.step === 0 ? mediaStep(s) : s.step === 1 ? promptStep(s) : s.step === 2 ? settingsStep(s) : preflightStep(s)}
        </div>

        <div class="cmp-nav">
          ${s.step > 0 ? `<button class="btn btn--ghost" data-cmp-back>${ui.esc(t('common.back'))}</button>` : ''}
          ${s.step < 3 ? `<button class="btn btn--primary" data-cmp-next ${canNext ? '' : 'disabled'}>${ui.esc(t('common.next'))}${ui.icon('chevR')}</button>` : ''}
        </div>`;

      /* stepper nav */
      el.querySelectorAll('[data-step-go]').forEach(function (b) {
        b.addEventListener('click', function () { s.step = parseInt(b.getAttribute('data-step-go'), 10); PL.App.rerender(); });
      });
      var back = el.querySelector('[data-cmp-back]');
      if (back) back.addEventListener('click', function () { s.step -= 1; PL.App.rerender(); });
      var next = el.querySelector('[data-cmp-next]');
      if (next) next.addEventListener('click', function () { s.step += 1; PL.App.rerender(); });

      /* step 1 — media */
      var upbox = el.querySelector('[data-cmp-upbox]');
      if (upbox) {
        var file = el.querySelector('[data-cmp-file]');
        upbox.addEventListener('click', function () { file.click(); });
        file.addEventListener('change', function () {
          if (file.files && file.files[0]) {
            s.media = { kind: 'upload', label: t('studio.qcNotUploaded', { name: file.files[0].name }) };
            PL.App.rerender();
          }
        });
      }
      el.querySelectorAll('[data-pick-asset]').forEach(function (b) {
        b.addEventListener('click', function () {
          var a = store.find('assets', b.getAttribute('data-pick-asset'));
          s.media = { kind: b.getAttribute('data-pick-kind'), label: a.title, assetId: a.id, thumb: a.thumb };
          PL.App.rerender();
        });
      });
      var stockSearch = el.querySelector('.cmp-cols [data-search]');
      if (stockSearch) stockSearch.addEventListener('input', function () {
        s.stockQuery = stockSearch.value; PL.App.rerender(true);
      });

      /* step 2 — prompt */
      el.querySelectorAll('[data-cmp-mode]').forEach(function (b) {
        b.addEventListener('click', function () { s.mode = b.getAttribute('data-cmp-mode'); PL.App.rerender(); });
      });
      var intent = el.querySelector('[data-cmp-intent]');
      if (intent) intent.addEventListener('input', function () { s.intent = intent.value; });
      var prompt = el.querySelector('[data-cmp-prompt]');
      if (prompt) prompt.addEventListener('input', function () {
        s.prompt = prompt.value;
        var nx = el.querySelector('[data-cmp-next]');
        if (nx) nx.disabled = !s.prompt.trim();
      });
      var refine = el.querySelector('[data-cmp-refine]');
      if (refine) refine.addEventListener('click', function () {
        var text = s.intent.trim();
        if (!text || s.typing) return;
        var sendMode = s.mode; /* the reply belongs to the thread it was asked in */
        s.chat.push({ mode: sendMode, role: 'user', text: text });
        s.typing = true;
        PL.App.rerender();
        /* canned refinement after a short "drafting…" beat — no AI is called */
        setTimeout(function () {
          if (PL.state.route !== 'create' || PL.state.composer !== s) { s.typing = false; return; }
          var refined = PL.state.data.quick_create.assist_template
            .split('{intent}').join(text)
            .split('{dur}').join(String(s.duration));
          s.chat.push({ mode: sendMode, role: 'assistant', text: refined });
          s.prompt = refined;
          s.typing = false;
          PL.App.rerender();
        }, 700);
      });

      /* step 3 — settings */
      el.querySelectorAll('[data-cmp-platform]').forEach(function (cb) {
        cb.addEventListener('change', function () { s.platforms[cb.getAttribute('data-cmp-platform')] = cb.checked; });
      });
      var dur = el.querySelector('[data-cmp-dur]');
      if (dur) dur.addEventListener('input', function () {
        s.duration = parseInt(dur.value, 10);
        el.querySelector('[data-cmp-durval]').textContent = s.duration + 's';
      });
      var voice = el.querySelector('[data-cmp-voice]');
      if (voice) voice.addEventListener('change', function () { s.voice = voice.checked; });
      var music = el.querySelector('[data-cmp-music]');
      if (music) music.addEventListener('input', function () { s.music = music.value; });

      /* step 4 — pre-flight */
      var go = el.querySelector('[data-cmp-launch]');
      if (go) go.addEventListener('click', function () { launch(s); });
    }
  };
})();
