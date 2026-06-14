<?php

declare(strict_types=1);

use Kuyash\Core\View;

/* ⌘K command palette (Phase 16). Authed shell only. Items are static navigation
   (server-rendered hrefs) + one action that opens the shortcuts drawer. Opened,
   filtered and keyboard-driven by palette.js; hidden (display:none) until then. */
?>
<div class="cmdk" id="cmdk">
  <div class="cmdk__box" role="dialog" aria-modal="true" aria-label="<?= View::t('cmd.label') ?>">
    <input class="cmdk__input" type="text" autocomplete="off" spellcheck="false"
           aria-label="<?= View::t('cmd.label') ?>" placeholder="<?= View::t('cmd.placeholder') ?>">
    <ul class="cmdk__list" role="listbox" aria-label="<?= View::t('cmd.label') ?>">
      <li class="cmdk__item is-active" role="option" aria-selected="true" data-href="/dashboard">
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1.5" y="1.5" width="5.5" height="5.5" rx="1"/><rect x="9" y="1.5" width="5.5" height="5.5" rx="1"/><rect x="1.5" y="9" width="5.5" height="5.5" rx="1"/><rect x="9" y="9" width="5.5" height="5.5" rx="1"/></svg></span>
        <?= View::t('nav.dashboard') ?>
      </li>
      <li class="cmdk__item" role="option" aria-selected="false" data-href="/quick">
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1.5l1.8 4.2 4.7.4-3.6 3 1.1 4.6L8 11.3 3.9 13.7 5 9.1 1.5 6.1l4.7-.4z"/></svg></span>
        <?= View::t('nav.create') ?>
      </li>
      <li class="cmdk__item" role="option" aria-selected="false" data-href="/trends">
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1.5 11l3.5-4 2.5 2.5 4-5"/><path d="M11.5 4.5h2v2"/></svg></span>
        <?= View::t('nav.trends') ?>
      </li>
      <li class="cmdk__item" role="option" aria-selected="false" data-href="/library">
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1.5" y="2.5" width="13" height="11" rx="1.5"/><path d="M4.5 2.5v11M11.5 2.5v11"/></svg></span>
        <?= View::t('nav.library') ?>
      </li>
      <li class="cmdk__item" role="option" aria-selected="false" data-href="/workflows">
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1.5" y="5" width="4.5" height="6" rx="1"/><rect x="10" y="5" width="4.5" height="6" rx="1"/><path d="M6 8h4"/></svg></span>
        <?= View::t('nav.workflows') ?>
      </li>
      <li class="cmdk__item" role="option" aria-selected="false" data-href="/queue">
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1.5l6 3-6 3-6-3z"/><path d="M2 8l6 3 6-3M2 11l6 3 6-3"/></svg></span>
        <?= View::t('nav.queue') ?>
      </li>
      <li class="cmdk__item" role="option" aria-selected="false" data-href="/accounts">
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="5.2" r="2.6"/><path d="M3 13.5c0-2.6 2.2-4.3 5-4.3s5 1.7 5 4.3"/></svg></span>
        <?= View::t('nav.accounts') ?>
      </li>
      <li class="cmdk__item" role="option" aria-selected="false" data-href="/usage">
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1.5v13M11.2 4.2H6.4a1.9 1.9 0 000 3.8h3.2a1.9 1.9 0 010 3.8H4.8"/></svg></span>
        <?= View::t('nav.usage') ?>
      </li>
      <li class="cmdk__item" role="option" aria-selected="false" data-href="/settings">
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="2.2"/><path d="M8 1.8v2M8 12.2v2M1.8 8h2M12.2 8h2M3.6 3.6l1.4 1.4M11 11l1.4 1.4M12.4 3.6L11 5M5 11l-1.4 1.4"/></svg></span>
        <?= View::t('nav.settings') ?>
      </li>
      <li class="cmdk__item" role="option" aria-selected="false" data-action="shortcuts">
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1.5" y="3.5" width="13" height="9" rx="2"/><path d="M4 6.5h.01M7 6.5h.01M10 6.5h.01M5 9.5h6"/></svg></span>
        <?= View::t('cmd.shortcuts') ?>
      </li>
    </ul>
    <div class="cmdk__empty" hidden><?= View::t('cmd.empty') ?></div>
  </div>
</div>
