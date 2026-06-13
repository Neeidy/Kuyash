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
  <div><strong><?= View::t('dash.worker_down_title') ?></strong> <?= View::t('dash.worker_down_body') ?> <span class="mono">php bin/worker.php</span>.</div>
</div>
<?php endif; ?>
<div class="screen-head">
  <div>
    <h1><?= View::t('dash.title') ?></h1>
    <p class="screen-sub"><?= View::t('dash.signed_in_as') ?> <?= View::e($name !== '' ? $name : $email) ?>
      <span class="mono">(<?= View::e($email) ?>)</span> · <?= View::e($workspaceName) ?></p>
  </div>
  <div class="screen-head__actions">
    <a class="btn btn--ghost" href="/trends"><?= View::t('nav.trends') ?></a>
    <a class="btn btn--primary" href="/queue"><?= View::t('dash.open_queue') ?></a>
  </div>
</div>

<!-- next-post countdown is a Phase 10 slot (schedule queue); placeholder for now -->
<div class="cockpit-topline mono" aria-hidden="true">
  <?= View::t('dash.next_up') ?> — <span class="muted"><?= View::t('dash.next_up_hint') ?></span>
</div>

<div class="kpi-strip">
  <div class="kpi"><span class="kpi__num num"><?= (int) $kpis['active'] ?></span><span class="kpi__label"><?= View::t('dash.kpi_active') ?></span></div>
  <div class="kpi"><span class="kpi__num num"><?= (int) $kpis['awaiting'] ?></span><span class="kpi__label"><?= View::t('dash.kpi_awaiting') ?></span></div>
  <div class="kpi"><span class="kpi__num num"><?= (int) $kpis['completed'] ?></span><span class="kpi__label"><?= View::t('dash.kpi_completed') ?></span></div>
  <div class="kpi"><span class="kpi__num num"><?= (int) $kpis['renders'] ?></span><span class="kpi__label"><?= View::t('dash.kpi_renders') ?></span></div>
  <div class="kpi"><span class="kpi__num num"><?= (int) $kpis['cache_hits'] ?></span><span class="kpi__label"><?= View::t('dash.kpi_cache') ?></span></div>
</div>

<div class="cockpit-grid">
  <div class="card">
    <div class="card__head"><h2><?= View::t('dash.active_runs') ?></h2>
      <span class="card__action"><a class="btn btn--ghost btn--sm" href="/queue"><?= View::t('nav.queue') ?></a></span>
    </div>
    <div class="card__body">
      <?php if ($cockpit['activeRuns'] === []): ?>
      <div class="ui-state">
        <span class="ui-state__icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M8 1.5l6 3-6 3-6-3z"/><path d="M2 8l6 3 6-3M2 11l6 3 6-3"/></svg></span>
        <h3><?= View::t('dash.nothing_running') ?></h3>
        <p><?= View::t('dash.start_from') ?> <a href="/trends"><?= View::t('dash.a_trend') ?></a> <?= View::t('dash.or_a') ?> <a href="/workflows"><?= View::t('dash.a_workflow') ?></a>.</p>
      </div>
      <?php else: ?>
      <ul class="job-list">
        <?php foreach ($cockpit['activeRuns'] as $run): ?>
        <li class="job-row">
          <div class="job-row__main">
            <span class="job-row__type"><?= View::t('dash.run_n', ['n' => (int) $run['id']]) ?> — <?= View::e((string) $run['workflow_name']) ?></span>
            <span class="job-row__entity mono"><?= View::e((string) $run['template']) ?><?= $run['current_node'] !== null ? ' · ' . View::t('dash.at') . ' ' . View::e((string) $run['current_node']) : '' ?></span>
          </div>
          <span class="chip chip--<?= Format::statusTone((string) $run['status']) ?>"><span class="dot dot--<?= Format::statusTone((string) $run['status']) ?>"></span><?= View::e(Messages::status((string) $run['status'])) ?></span>
          <a class="btn btn--ghost btn--sm" href="/runs/<?= (int) $run['id'] ?>"><?= View::t('dash.timeline') ?></a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2><?= View::t('dash.awaiting_approval') ?></h2>
      <span class="card__action"><span class="chip chip--<?= $cockpit['awaiting'] === [] ? 'ok' : 'warn' ?> num"><?= count($cockpit['awaiting']) ?></span></span>
    </div>
    <div class="card__body">
      <?php if ($cockpit['awaiting'] === []): ?>
      <p class="muted"><?= View::t('dash.nothing_waiting') ?></p>
      <?php else: ?>
      <div class="approval-strip">
        <?php foreach ($cockpit['awaiting'] as $job): ?>
        <a class="approval-thumb" href="/queue" title="<?= View::e((string) $job['node']) ?> · <?= View::t('dash.run_n', ['n' => (int) $job['run_id']]) ?>">
          <?php if ($job['draft_render_id'] !== null): ?>
          <img src="/render/<?= (int) $job['draft_render_id'] ?>/poster" alt="" loading="lazy">
          <?php else: ?>
          <span class="approval-thumb__ph mono"><?= View::e((string) $job['type']) ?></span>
          <?php endif; ?>
          <span class="approval-thumb__cap mono">#<?= (int) $job['run_id'] ?> · <?= View::e((string) $job['node']) ?><?= $job['ai_label_required'] ? ' · AI' : '' ?></span>
        </a>
        <?php endforeach; ?>
      </div>
      <p class="note"><?= View::t('dash.thumbs_note_1') ?>
        <a href="/queue"><?= View::t('dash.queue_word') ?></a> <?= View::t('dash.thumbs_note_2') ?></p>
      <?php endif; ?>
    </div>
  </div>
</div>
