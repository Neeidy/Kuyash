<?php

declare(strict_types=1);

use Kuyash\Core\I18n;
use Kuyash\Core\View;

/** @var string $content */
/** @var string $title */
/** @var string $active     'dashboard' | 'quick' | 'trends' | 'library' | 'workflows' | 'queue' | 'accounts' | 'logs' | 'digest' | 'usage' | 'settings' */
/** @var string $workspaceName */
/** @var string $csrfField  trusted generated HTML (logout form) */
/** @var list<array{type: string, text: string}>|null $flashes rendered once here for every app page */
?>
<!DOCTYPE html>
<html lang="<?= View::e(I18n::locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title ?? 'Kuyash') ?></title>
<!-- mark JS-on synchronously so JS-only affordances (⌘K trigger, sliding pill)
     resolve before first paint; without JS the .js class is never set and the
     server-rendered shell stands on its own -->
<script>document.documentElement.classList.add('js');</script>
<link rel="stylesheet" href="/assets/css/base.css">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar__logo">
      <span class="logo-mark">K</span>
      <span class="logo-word">Kuyash</span>
    </div>
    <nav class="sidebar__nav" aria-label="<?= View::t('nav.main') ?>">
      <a class="nav-item<?= ($active ?? '') === 'dashboard' ? ' is-active' : '' ?>"<?= ($active ?? '') === 'dashboard' ? ' aria-current="page"' : '' ?> href="/dashboard">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1.5" y="1.5" width="5.5" height="5.5" rx="1"/><rect x="9" y="1.5" width="5.5" height="5.5" rx="1"/><rect x="1.5" y="9" width="5.5" height="5.5" rx="1"/><rect x="9" y="9" width="5.5" height="5.5" rx="1"/></svg></span>
        <?= View::t('nav.dashboard') ?>
      </a>
      <a class="nav-item<?= ($active ?? '') === 'quick' ? ' is-active' : '' ?>"<?= ($active ?? '') === 'quick' ? ' aria-current="page"' : '' ?> href="/quick">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1.5l1.8 4.2 4.7.4-3.6 3 1.1 4.6L8 11.3 3.9 13.7 5 9.1 1.5 6.1l4.7-.4z"/></svg></span>
        <?= View::t('nav.create') ?>
      </a>
      <a class="nav-item<?= ($active ?? '') === 'trends' ? ' is-active' : '' ?>"<?= ($active ?? '') === 'trends' ? ' aria-current="page"' : '' ?> href="/trends">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1.5 11l3.5-4 2.5 2.5 4-5"/><path d="M11.5 4.5h2v2"/></svg></span>
        <?= View::t('nav.trends') ?>
      </a>
      <a class="nav-item<?= ($active ?? '') === 'library' ? ' is-active' : '' ?>"<?= ($active ?? '') === 'library' ? ' aria-current="page"' : '' ?> href="/library">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1.5" y="2.5" width="13" height="11" rx="1.5"/><path d="M4.5 2.5v11M11.5 2.5v11M1.5 6h3M1.5 10h3M11.5 6h3M11.5 10h3"/></svg></span>
        <?= View::t('nav.library') ?>
      </a>
      <a class="nav-item<?= ($active ?? '') === 'workflows' ? ' is-active' : '' ?>"<?= ($active ?? '') === 'workflows' ? ' aria-current="page"' : '' ?> href="/workflows">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1.5" y="5" width="4.5" height="6" rx="1"/><rect x="10" y="5" width="4.5" height="6" rx="1"/><path d="M6 8h4"/></svg></span>
        <?= View::t('nav.workflows') ?>
      </a>
      <a class="nav-item<?= ($active ?? '') === 'queue' ? ' is-active' : '' ?>"<?= ($active ?? '') === 'queue' ? ' aria-current="page"' : '' ?> href="/queue">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1.5l6 3-6 3-6-3z"/><path d="M2 8l6 3 6-3M2 11l6 3 6-3"/></svg></span>
        <?= View::t('nav.queue') ?>
      </a>
      <a class="nav-item<?= ($active ?? '') === 'accounts' ? ' is-active' : '' ?>"<?= ($active ?? '') === 'accounts' ? ' aria-current="page"' : '' ?> href="/accounts">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="5.2" r="2.6"/><path d="M3 13.5c0-2.6 2.2-4.3 5-4.3s5 1.7 5 4.3"/></svg></span>
        <?= View::t('nav.accounts') ?>
      </a>
      <a class="nav-item<?= ($active ?? '') === 'logs' ? ' is-active' : '' ?>"<?= ($active ?? '') === 'logs' ? ' aria-current="page"' : '' ?> href="/logs">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1.5" y="2.5" width="13" height="11" rx="1.5"/><path d="M4.5 6l2 2-2 2M8.5 10h3"/></svg></span>
        <?= View::t('nav.logs') ?>
      </a>
      <a class="nav-item<?= ($active ?? '') === 'digest' ? ' is-active' : '' ?>"<?= ($active ?? '') === 'digest' ? ' aria-current="page"' : '' ?> href="/digest">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="1.5" width="12" height="13" rx="1.5"/><path d="M5 5h6M5 8h6M5 11h3.5"/></svg></span>
        <?= View::t('nav.digest') ?>
      </a>
      <a class="nav-item<?= ($active ?? '') === 'usage' ? ' is-active' : '' ?>"<?= ($active ?? '') === 'usage' ? ' aria-current="page"' : '' ?> href="/usage">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1.5v13M11.2 4.2H6.4a1.9 1.9 0 000 3.8h3.2a1.9 1.9 0 010 3.8H4.8"/></svg></span>
        <?= View::t('nav.usage') ?>
      </a>
      <a class="nav-item<?= ($active ?? '') === 'settings' ? ' is-active' : '' ?>"<?= ($active ?? '') === 'settings' ? ' aria-current="page"' : '' ?> href="/settings">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="2.2"/><path d="M8 1.8v2M8 12.2v2M1.8 8h2M12.2 8h2M3.6 3.6l1.4 1.4M11 11l1.4 1.4M12.4 3.6L11 5M5 11l-1.4 1.4"/></svg></span>
        <?= View::t('nav.settings') ?>
      </a>
    </nav>
    <div class="sidebar__foot">
      <p><?= View::t('nav.foot_title') ?><br><?= View::t('nav.foot_text') ?></p>
    </div>
  </aside>

  <header class="topbar">
    <button type="button" class="iconbtn topbar__menu" data-sidebar-toggle aria-label="<?= View::t('nav.toggle') ?>" aria-expanded="false">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4h12M2 8h12M2 12h12"/></svg>
    </button>
    <span class="mode-chip"><span class="dot"></span><span><?= View::e(($workspaceName ?? '') !== '' ? $workspaceName : I18n::t('nav.workspace')) ?></span></span>
    <div class="topbar__right">
      <button type="button" class="cmdk-trigger" data-cmdk-open aria-keyshortcuts="Meta+K Control+K">
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="7" cy="7" r="4.5"/><path d="M11 11l3 3"/></svg></span>
        <span class="cmdk-trigger__label"><?= View::t('cmd.trigger') ?></span>
        <b>⌘K</b>
      </button>
      <div class="lang-switch" role="group" aria-label="<?= View::t('nav.language') ?>">
        <?php foreach (['en' => 'EN', 'tr' => 'TR'] as $code => $label): ?>
          <?php if ($code === I18n::locale()): ?>
            <span class="lang-switch__opt is-active" aria-current="true"><?= $label ?></span>
          <?php else: ?>
            <form method="post" action="/locale">
              <?= $csrfField ?? '' ?>
              <input type="hidden" name="locale" value="<?= $code ?>">
              <button type="submit" class="lang-switch__opt"><?= $label ?></button>
            </form>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <form method="post" action="/logout">
        <?= $csrfField ?? '' ?>
        <button type="submit" class="btn btn--ghost btn--sm"><?= View::t('nav.sign_out') ?></button>
      </form>
    </div>
  </header>

  <main class="main">
    <?php foreach ($flashes ?? [] as $flash): ?>
    <div class="callout callout--banner callout--<?= $flash['type'] === 'success' ? 'ok' : 'err' ?>"
         role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>">
      <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6.5"/><?= $flash['type'] === 'success' ? '<path d="M5.2 8.4l1.9 1.9 3.7-4.4"/>' : '<path d="M8 5v3.5M8 11h.01"/>' ?></svg></span>
      <div><?= View::e($flash['text']) ?></div>
    </div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
</div>
<div class="scrim" data-sidebar-scrim></div>
<?php require __DIR__ . '/partials/command-palette.php'; ?>
<?php require __DIR__ . '/partials/drawer.php'; ?>
<script src="/assets/js/motion.js" defer></script>
<script src="/assets/js/drawer.js" defer></script>
<script src="/assets/js/palette.js" defer></script>
<script src="/assets/js/inline-player.js" defer></script>
<script src="/assets/js/node-graph.js" defer></script>
<script src="/assets/js/app.js" defer></script>
</body>
</html>
