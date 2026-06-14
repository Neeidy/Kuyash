<?php

declare(strict_types=1);

use Kuyash\Core\View;

/* Reusable global drawer (Phase 16). Authed shell only. Empty by default; later
   phases call PL.drawer.open()/openTemplate() to fill it. The shortcuts template
   below is its first, shell-level content. All copy is server-rendered + escaped. */
?>
<div class="drawer" id="drawer">
  <div class="drawer__scrim"></div>
  <div class="drawer__panel" role="dialog" aria-modal="true" aria-labelledby="drawer-title">
    <header class="drawer__head">
      <h3 id="drawer-title"></h3>
      <button type="button" class="drawer__close" aria-label="<?= View::t('help.close') ?>">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4l8 8M12 4l-8 8"/></svg>
      </button>
    </header>
    <div class="drawer__body"></div>
  </div>
</div>
<template id="tpl-shortcuts" data-title="<?= View::t('cmd.shortcuts') ?>">
  <div class="shortcut-list">
    <div class="shortcut-row"><span><?= View::t('help.open_palette') ?></span><kbd>⌘K</kbd></div>
    <div class="shortcut-row"><span><?= View::t('help.navigate') ?></span><kbd>↑ ↓</kbd></div>
    <div class="shortcut-row"><span><?= View::t('help.select') ?></span><kbd>↵</kbd></div>
    <div class="shortcut-row"><span><?= View::t('help.close') ?></span><kbd>Esc</kbd></div>
  </div>
</template>
