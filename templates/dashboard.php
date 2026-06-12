<?php

declare(strict_types=1);

use Kuyash\Core\View;

/** @var string $email */
/** @var string $name */
/** @var string $workspaceName */
/** @var string $role */
/** @var bool $workerAlive */
?>
<?php if (($workerAlive ?? true) === false): ?>
<div class="callout callout--warn callout--banner" role="alert">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2L1.5 13.5h13z"/><path d="M8 6.5V10M8 12h.01"/></svg></span>
  <div><strong>The background worker is not running.</strong> Queued pipeline jobs won’t
    progress until it starts. Start it with <span class="mono">php bin/worker.php</span>.</div>
</div>
<?php endif; ?>
<div class="screen-head">
  <div>
    <h1>Dashboard</h1>
    <p class="screen-sub">Signed in as <?= View::e($name !== '' ? $name : $email) ?>
      <span class="mono">(<?= View::e($email) ?>)</span></p>
  </div>
  <div class="screen-head__actions">
    <a class="btn btn--ghost" href="/workflows">Workflows</a>
    <a class="btn btn--primary" href="/queue">Open Queue</a>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Workspace</h2></div>
  <div class="card__body">
    <dl class="kv-list">
      <div class="kv"><dt>Workspace</dt><dd><?= View::e($workspaceName) ?></dd></div>
      <div class="kv"><dt>Your role</dt><dd><?= View::e($role) ?></dd></div>
    </dl>
    <p class="note">Library, Workflows, Queue and Logs are live in the sidebar — start a run
    from a <a href="/workflows">workflow</a> and approve it in the <a href="/queue">queue</a>.
    Analytics and KPI cards arrive with later phases.</p>
  </div>
</div>
