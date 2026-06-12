<?php

declare(strict_types=1);

use Kuyash\Core\View;

/** @var string $content */
/** @var string $title */
/** @var string $active     'dashboard' | 'trends' | 'library' | 'workflows' | 'queue' | 'logs' */
/** @var string $workspaceName */
/** @var string $csrfField  trusted generated HTML (logout form) */
/** @var list<array{type: string, text: string}>|null $flashes rendered once here for every app page */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title ?? 'Kuyash') ?></title>
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
    <nav class="sidebar__nav" aria-label="Main">
      <a class="nav-item<?= ($active ?? '') === 'dashboard' ? ' is-active' : '' ?>"<?= ($active ?? '') === 'dashboard' ? ' aria-current="page"' : '' ?> href="/dashboard">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1.5" y="1.5" width="5.5" height="5.5" rx="1"/><rect x="9" y="1.5" width="5.5" height="5.5" rx="1"/><rect x="1.5" y="9" width="5.5" height="5.5" rx="1"/><rect x="9" y="9" width="5.5" height="5.5" rx="1"/></svg></span>
        Dashboard
      </a>
      <a class="nav-item<?= ($active ?? '') === 'trends' ? ' is-active' : '' ?>"<?= ($active ?? '') === 'trends' ? ' aria-current="page"' : '' ?> href="/trends">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1.5 11l3.5-4 2.5 2.5 4-5"/><path d="M11.5 4.5h2v2"/></svg></span>
        Trends
      </a>
      <a class="nav-item<?= ($active ?? '') === 'library' ? ' is-active' : '' ?>"<?= ($active ?? '') === 'library' ? ' aria-current="page"' : '' ?> href="/library">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1.5" y="2.5" width="13" height="11" rx="1.5"/><path d="M4.5 2.5v11M11.5 2.5v11M1.5 6h3M1.5 10h3M11.5 6h3M11.5 10h3"/></svg></span>
        Library
      </a>
      <a class="nav-item<?= ($active ?? '') === 'workflows' ? ' is-active' : '' ?>"<?= ($active ?? '') === 'workflows' ? ' aria-current="page"' : '' ?> href="/workflows">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1.5" y="5" width="4.5" height="6" rx="1"/><rect x="10" y="5" width="4.5" height="6" rx="1"/><path d="M6 8h4"/></svg></span>
        Workflows
      </a>
      <a class="nav-item<?= ($active ?? '') === 'queue' ? ' is-active' : '' ?>"<?= ($active ?? '') === 'queue' ? ' aria-current="page"' : '' ?> href="/queue">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1.5l6 3-6 3-6-3z"/><path d="M2 8l6 3 6-3M2 11l6 3 6-3"/></svg></span>
        Queue
      </a>
      <a class="nav-item<?= ($active ?? '') === 'logs' ? ' is-active' : '' ?>"<?= ($active ?? '') === 'logs' ? ' aria-current="page"' : '' ?> href="/logs">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1.5" y="2.5" width="13" height="11" rx="1.5"/><path d="M4.5 6l2 2-2 2M8.5 10h3"/></svg></span>
        Logs
      </a>
    </nav>
    <div class="sidebar__foot">
      <p>Phase 6 · Trend Radar<br>Trend discovery is mock-first (real YouTube / Google optional).</p>
    </div>
  </aside>

  <header class="topbar">
    <button type="button" class="iconbtn topbar__menu" data-sidebar-toggle aria-label="Toggle navigation" aria-expanded="false">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4h12M2 8h12M2 12h12"/></svg>
    </button>
    <span class="mode-chip"><span class="dot"></span><span><?= View::e($workspaceName ?? 'Workspace') ?></span></span>
    <div class="topbar__right">
      <form method="post" action="/logout">
        <?= $csrfField ?? '' ?>
        <button type="submit" class="btn btn--ghost btn--sm">Sign out</button>
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
<script src="/assets/js/app.js" defer></script>
</body>
</html>
