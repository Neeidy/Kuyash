<?php

declare(strict_types=1);

use Kuyash\Core\View;

/** @var string $content */
/** @var string $title */
/** @var string $active     'dashboard' | 'library' */
/** @var string $workspaceName */
/** @var string $csrfField  trusted generated HTML (logout form) */
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
      <a class="nav-item<?= ($active ?? '') === 'dashboard' ? ' is-active' : '' ?>" href="/dashboard">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1.5" y="1.5" width="5.5" height="5.5" rx="1"/><rect x="9" y="1.5" width="5.5" height="5.5" rx="1"/><rect x="1.5" y="9" width="5.5" height="5.5" rx="1"/><rect x="9" y="9" width="5.5" height="5.5" rx="1"/></svg></span>
        Dashboard
      </a>
      <a class="nav-item<?= ($active ?? '') === 'library' ? ' is-active' : '' ?>" href="/library">
        <span class="nav-item__marker"></span>
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1.5" y="2.5" width="13" height="11" rx="1.5"/><path d="M4.5 2.5v11M11.5 2.5v11M1.5 6h3M1.5 10h3M11.5 6h3M11.5 10h3"/></svg></span>
        Library
      </a>
    </nav>
    <div class="sidebar__foot">
      <p>Phase 3 · Content Library<br>Workflows arrive in Phase 4.</p>
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
    <?= $content ?>
  </main>
</div>
<div class="scrim" data-sidebar-scrim></div>
<script src="/assets/js/app.js" defer></script>
</body>
</html>
