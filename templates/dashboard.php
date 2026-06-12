<?php

declare(strict_types=1);

use Kuyash\Core\View;

/** @var string $email */
/** @var string $name */
/** @var string $workspaceName */
/** @var string $role */
?>
<div class="screen-head">
  <div>
    <h1>Dashboard</h1>
    <p class="screen-sub">Signed in as <?= View::e($name !== '' ? $name : $email) ?>
      <span class="mono">(<?= View::e($email) ?>)</span></p>
  </div>
  <div class="screen-head__actions">
    <a class="btn btn--primary" href="/library">Open Library</a>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Workspace</h2></div>
  <div class="card__body">
    <dl class="kv-list">
      <div class="kv"><dt>Workspace</dt><dd><?= View::e($workspaceName) ?></dd></div>
      <div class="kv"><dt>Your role</dt><dd><?= View::e($role) ?></dd></div>
    </dl>
    <p class="note">Nothing else here yet — workflows, render queue and analytics
    arrive with Phases 4–7. The Content Library is live in the sidebar.</p>
  </div>
</div>
