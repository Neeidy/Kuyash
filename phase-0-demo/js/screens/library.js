/* Screen 4 — Content Library */
(function () {
  'use strict';
  var PL = window.Kuyash, ui = PL.ui, store = PL.store, t = PL.t;

  var TYPES = [['all', 'library.tAll'], ['own', 'library.tOwn'], ['face', 'library.tFace'], ['stock', 'library.tStock'], ['ai', 'library.tAi']];

  PL.screens.library = {
    id: 'library', icon: 'library',

    render: function (el) {
      var ws = store.workspace();
      var q = PL.state.librarySearch.toLowerCase();
      var type = PL.state.libraryType;
      var assets = store.byWorkspace('assets').filter(function (a) {
        if (type !== 'all' && a.type !== type) return false;
        if (q && (a.title + ' ' + a.tags.join(' ')).toLowerCase().indexOf(q) === -1) return false;
        return true;
      });

      var stateHtml = ui.resolveUiState('library',
        { icon: 'library', title: t('library.empty_t'), body: t('library.empty_b'),
          cta: { label: t('common.upload'), attr: 'data-upload', icon: 'upload' } },
        { title: t('library.error_t'), body: t('library.error_b') });

      el.innerHTML = `
        <header class="screen-head">
          <div><h1 class="vt-page-title">${ui.esc(t('nav.library'))}</h1>
          <p class="screen-sub">${ui.esc(t('library.sub'))}</p></div>
          <div class="screen-head__actions">
            ${ui.uiStateBar('library')}
            <button class="btn btn--primary" data-upload>${ui.icon('upload')}${ui.esc(t('common.upload'))}</button>
          </div>
        </header>

        <div class="filter-row">
          <label class="search">${ui.icon('search')}<input type="search" placeholder="${ui.esc(t('library.searchPh'))}" value="${ui.esc(PL.state.librarySearch)}" data-search aria-label="${ui.esc(t('common.search'))}"></label>
          <div class="chip-row">${TYPES.map(function (tp) {
            return `<button class="fchip ${type === tp[0] ? 'is-active' : ''}" data-type="${tp[0]}">${ui.esc(t(tp[1]))}</button>`;
          }).join('')}</div>
        </div>

        ${stateHtml ? stateHtml : (assets.length === 0
          ? ui.emptyState({ icon: 'search', title: t('library.noMatch_t'), body: t('library.noMatch_b') })
          : `<div class="asset-grid stagger">${assets.map(function (a) {
              return `<article class="asset-card lift" data-open-asset="${a.id}" tabindex="0" role="button">
                ${ui.thumb(a.thumb, a.duration_s + 's', true, a.aspect)}
                <div class="asset-card__body">
                  <h3>${ui.esc(a.title)}</h3>
                  <div class="asset-card__meta">
                    <span class="chip ${a.type === 'ai' ? 'chip--ai' : 'chip--neutral'}">${ui.esc(t('library.t' + a.type.charAt(0).toUpperCase() + a.type.slice(1)))}</span>
                    ${a.aspect === '9:16' ? '' : `<span class="chip chip--warn" title="${ui.esc(t('library.formatWarn'))}">${ui.icon('warning')}${ui.esc(a.aspect)}</span>`}
                    ${ui.statusBadge(a.status)}
                  </div>
                  ${a.ai_label_required ? '<p class="asset-card__note">' + ui.aiLabelTag() + ' <span class="faint">' + ui.esc(t('library.aiRequired')) + '</span></p>' : ''}
                  <div class="asset-card__platforms">${a.platform_fit.map(ui.platformIcon).join('')}</div>
                  <div class="tag-row">${a.tags.map(function (tg) { return '<span class="tag">' + ui.esc(tg) + '</span>'; }).join('')}</div>
                </div>
              </article>`;
            }).join('')}</div>`)}`;

      var grid = el.querySelector('.asset-grid');
      if (grid) {
        PL.motion.stagger(grid);
        /* asset cards open the Detail Drawer */
        grid.addEventListener('click', function (e) {
          var card = e.target.closest('[data-open-asset]');
          if (card && !e.target.closest('button')) PL.drawers.openAsset(card.getAttribute('data-open-asset'));
        });
        grid.addEventListener('keydown', function (e) {
          if (e.key !== 'Enter' && e.key !== ' ') return;
          var card = e.target.closest('[data-open-asset]');
          if (card && !e.target.closest('button')) { e.preventDefault(); PL.drawers.openAsset(card.getAttribute('data-open-asset')); }
        });
      }

      el.querySelector('[data-search]').addEventListener('input', function (e) {
        PL.state.librarySearch = e.target.value;
        PL.App.rerender(true);
      });
      el.querySelectorAll('[data-type]').forEach(function (b) {
        b.addEventListener('click', function () {
          PL.state.libraryType = b.getAttribute('data-type'); PL.App.rerender();
        });
      });

      /* both the header button and the empty-state CTA open the upload modal */
      el.querySelectorAll('[data-upload]').forEach(function (ub) { ub.addEventListener('click', openUpload); });
      function openUpload() {
        var m = ui.modal({
          title: t('dlg.upload_t'),
          body: `
            <span class="upload-box" data-up-box tabindex="0">${ui.icon('upload')}<span data-up-label>${ui.esc(t('dlg.upload_choose'))}</span>
              <input type="file" accept="video/*" hidden data-up-file></span>
            <label class="field"><span>${ui.esc(t('dlg.upload_title'))}</span><input type="text" data-up-title placeholder="${ui.esc(t('dlg.upload_titlePh'))}"></label>
            <label class="field"><span>${ui.esc(t('dlg.upload_type'))}</span>
              <select data-up-type><option value="own">${ui.esc(t('dlg.upload_own'))}</option><option value="face">${ui.esc(t('dlg.upload_face'))}</option></select></label>
            ${ui.note(t('dlg.upload_note'))}`,
          footer: `<button class="btn btn--ghost" data-action="modal-close">${ui.esc(t('common.cancel'))}</button>
                   <button class="btn btn--primary" data-up-save>${ui.esc(t('dlg.upload_ok'))}</button>`
        });
        var f = m.querySelector('[data-up-file]');
        m.querySelector('[data-up-box]').addEventListener('click', function () { f.click(); });
        f.addEventListener('change', function () {
          if (f.files && f.files[0]) m.querySelector('[data-up-label]').textContent = t('studio.qcNotUploaded', { name: f.files[0].name });
        });
        m.querySelector('[data-up-save]').addEventListener('click', function () {
          var title = m.querySelector('[data-up-title]').value.trim() || t('dlg.upload_untitled');
          PL.state.data.assets.unshift({
            id: 'as_x' + Math.random().toString(36).slice(2, 6), workspace_id: ws.id,
            type: m.querySelector('[data-up-type]').value, title: title,
            duration_s: 30, aspect: '9:16', status: 'processing',
            thumb: Math.floor(Math.random() * 10) + 1, tags: ['new'], platform_fit: ['instagram', 'tiktok', 'youtube']
          });
          ui.closeModal();
          ui.toast(t('toast.assetAdded'), 'success');
          PL.App.rerender();
        });
      }
    }
  };
})();
