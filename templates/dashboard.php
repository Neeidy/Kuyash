<?php

declare(strict_types=1);

use Kuyash\Core\Format;
use Kuyash\Core\Messages;
use Kuyash\Core\View;

/** @var string $email */
/** @var string $name */
/** @var string $workspaceName */
/** @var string $role */
/** @var bool $workerAlive */
/** @var array{kpis: array<string,int>, activeRuns: list<array<string,mixed>>, awaiting: list<array<string,mixed>>} $cockpit */

$kpis = $cockpit['kpis'];
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
      <span class="mono">(<?= View::e($email) ?>)</span> · <?= View::e($workspaceName) ?></p>
  </div>
  <div class="screen-head__actions">
    <a class="btn btn--ghost" href="/trends">Trends</a>
    <a class="btn btn--primary" href="/queue">Open Queue</a>
  </div>
</div>

<!-- next-post countdown is a Phase 10 slot (schedule queue); placeholder for now -->
<div class="cockpit-topline mono" aria-hidden="true">
  NEXT UP — <span class="muted">scheduling arrives in Phase 10</span>
</div>

<div class="kpi-strip">
  <div class="kpi"><span class="kpi__num num"><?= (int) $kpis['active'] ?></span><span class="kpi__label">active runs</span></div>
  <div class="kpi"><span class="kpi__num num"><?= (int) $kpis['awaiting'] ?></span><span class="kpi__label">awaiting you</span></div>
  <div class="kpi"><span class="kpi__num num"><?= (int) $kpis['completed'] ?></span><span class="kpi__label">completed</span></div>
  <div class="kpi"><span class="kpi__num num"><?= (int) $kpis['renders'] ?></span><span class="kpi__label">renders</span></div>
  <div class="kpi"><span class="kpi__num num"><?= (int) $kpis['cache_hits'] ?></span><span class="kpi__label">cache reuses</span></div>
</div>

<div class="cockpit-grid">
  <div class="card">
    <div class="card__head"><h2>Active runs</h2>
      <span class="card__action"><a class="btn btn--ghost btn--sm" href="/queue">Queue</a></span>
    </div>
    <div class="card__body">
      <?php if ($cockpit['activeRuns'] === []): ?>
      <div class="ui-state">
        <span class="ui-state__icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M8 1.5l6 3-6 3-6-3z"/><path d="M2 8l6 3 6-3M2 11l6 3 6-3"/></svg></span>
        <h3>Nothing running</h3>
        <p>Start a run from <a href="/trends">a trend</a> or a <a href="/workflows">workflow</a>.</p>
      </div>
      <?php else: ?>
      <ul class="job-list">
        <?php foreach ($cockpit['activeRuns'] as $run): ?>
        <li class="job-row">
          <div class="job-row__main">
            <span class="job-row__type">run #<?= (int) $run['id'] ?> — <?= View::e((string) $run['workflow_name']) ?></span>
            <span class="job-row__entity mono"><?= View::e((string) $run['template']) ?><?= $run['current_node'] !== null ? ' · at ' . View::e((string) $run['current_node']) : '' ?></span>
          </div>
          <span class="chip chip--<?= Format::statusTone((string) $run['status']) ?>"><span class="dot dot--<?= Format::statusTone((string) $run['status']) ?>"></span><?= View::e(Messages::status((string) $run['status'])) ?></span>
          <a class="btn btn--ghost btn--sm" href="/runs/<?= (int) $run['id'] ?>">Timeline</a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2>Awaiting approval</h2>
      <span class="card__action"><span class="chip chip--<?= $cockpit['awaiting'] === [] ? 'ok' : 'warn' ?> num"><?= count($cockpit['awaiting']) ?></span></span>
    </div>
    <div class="card__body">
      <?php if ($cockpit['awaiting'] === []): ?>
      <p class="muted">Nothing is waiting for your decision.</p>
      <?php else: ?>
      <div class="approval-strip">
        <?php foreach ($cockpit['awaiting'] as $job): ?>
        <a class="approval-thumb" href="/queue" title="<?= View::e((string) $job['node']) ?> · run #<?= (int) $job['run_id'] ?>">
          <?php if ($job['draft_render_id'] !== null): ?>
          <img src="/render/<?= (int) $job['draft_render_id'] ?>/poster" alt="" loading="lazy">
          <?php else: ?>
          <span class="approval-thumb__ph mono"><?= View::e((string) $job['type']) ?></span>
          <?php endif; ?>
          <span class="approval-thumb__cap mono">#<?= (int) $job['run_id'] ?> · <?= View::e((string) $job['node']) ?><?= $job['ai_label_required'] ? ' · AI' : '' ?></span>
        </a>
        <?php endforeach; ?>
      </div>
      <p class="note">Thumbnails are real draft-render frames. Approve in the
        <a href="/queue">queue</a> to trigger the full-res final render.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
