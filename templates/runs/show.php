<?php

declare(strict_types=1);

use Kuyash\Core\Format;
use Kuyash\Core\Messages;
use Kuyash\Core\View;
use Kuyash\Workflow\Nodes;

/** @var array<string, mixed> $run */
/** @var list<array<string, mixed>> $jobs ordered by step */
/** @var list<array<string, mixed>> $timeline events, chronological */
/** @var list<array<string, mixed>> $approvals with decided_by_email */

$jobsByNode = [];
foreach ($jobs as $job) {
    $jobsByNode[(string) $job['node']][] = $job;
}

// node display state from its jobs: failed > awaiting > running > done > pending
$nodeState = static function (string $node) use ($jobsByNode): string {
    $nodeJobs = $jobsByNode[$node] ?? [];
    if ($nodeJobs === []) {
        return 'pending';
    }
    $statuses = array_column($nodeJobs, 'status');
    if (in_array('failed', $statuses, true)) {
        return 'failed';
    }
    if (in_array('awaiting_approval', $statuses, true)) {
        return 'awaiting';
    }
    if (in_array('processing', $statuses, true) || in_array('queued', $statuses, true)) {
        return 'running';
    }
    if (in_array('cancelled', $statuses, true)) {
        return 'cancelled';
    }
    $expected = count(Nodes::NODE_JOBS[$node] ?? []);

    return count($nodeJobs) >= $expected ? 'done' : 'running';
};
?>
<div class="screen-head">
  <div>
    <h1>Run #<?= (int) $run['id'] ?> — <?= View::e($run['workflow_name']) ?></h1>
    <p class="screen-sub mono"><?= View::e($run['workflow_template']) ?> ·
      entity: <?= View::e($run['entity_type']) ?><?= $run['entity_id'] !== null ? ' #' . (int) $run['entity_id'] : '' ?> ·
      started <?= View::e($run['created_at']) ?></p>
  </div>
  <div class="screen-head__actions">
    <span class="chip chip--<?= Format::statusTone((string) $run['status']) ?>"><span class="dot dot--<?= Format::statusTone((string) $run['status']) ?>"></span><?= View::e(Messages::status((string) $run['status'])) ?></span>
    <a class="btn btn--ghost btn--sm" href="/queue">Back to queue</a>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Node track</h2></div>
  <div class="card__body">
    <div class="wf-canvas">
      <div class="node-track">
        <?php foreach ($run['nodes'] as $node): ?>
        <?php $state = $nodeState((string) $node['node']); ?>
        <div class="node-wrap">
          <div class="node<?= ($node['locked'] ?? false) ? ' node--locked' : '' ?><?= $state === 'pending' ? ' node--pending' : '' ?>">
            <span class="node__status node__status--<?= View::e($state) ?>" title="<?= View::e($state) ?>"></span>
            <span class="node__name mono"><?= View::e($node['node']) ?></span>
            <span class="node__desc"><?= View::e($state) ?></span>
          </div>
          <span class="node-connector" aria-hidden="true"></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Jobs</h2></div>
  <div class="card__body">
    <ul class="job-list">
      <?php foreach ($jobs as $job): ?>
      <li class="job-row">
        <div class="job-row__main">
          <span class="job-row__type mono"><?= (int) $job['step'] ?>. <?= View::e($job['type']) ?> <span class="muted">#<?= (int) $job['id'] ?></span></span>
          <span class="job-row__entity"><?= View::e($job['node']) ?><?= $job['provider'] !== null ? ' · provider: ' . View::e((string) $job['provider']) : '' ?><?= $job['finished_at'] !== null ? ' · ' . View::e(Format::utcTime((string) $job['finished_at'])) : '' ?></span>
          <?php if ($job['error_message'] !== null): ?>
          <span class="job-row__error"><?= View::e((string) $job['error_message']) ?></span>
          <?php endif; ?>
        </div>
        <span class="chip chip--<?= Format::statusTone((string) $job['status']) ?>"><span class="dot dot--<?= Format::statusTone((string) $job['status']) ?>"></span><?= View::e(Messages::status((string) $job['status'])) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<?php if ($approvals !== []): ?>
<div class="card">
  <div class="card__head"><h2>Approval records</h2></div>
  <div class="card__body">
    <ul class="job-list">
      <?php foreach ($approvals as $approval): ?>
      <li class="job-row">
        <div class="job-row__main">
          <span class="job-row__type mono"><?= View::e($approval['node']) ?></span>
          <span class="job-row__entity">mode: <?= View::e($approval['mode']) ?></span>
        </div>
        <span class="chip chip--<?= $approval['decision'] === 'approved' ? 'ok' : 'err' ?> chip--record">
          <?= $approval['decision'] === 'approved' ? 'Approved by you' : 'Rejected by you' ?>
          · <?= View::e($approval['decided_by_email']) ?>
          · <?= View::e(substr((string) $approval['decided_at'], 0, 10) . ' ' . Format::utcTime((string) $approval['decided_at'])) ?>
        </span>
      </li>
      <?php endforeach; ?>
    </ul>
    <p class="note">Records reflect what actually happened: manual decisions carry the deciding
      account; auto-approval (Phase 9) will be labelled as the compliance agent, never as a human.</p>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><h2>Event timeline</h2>
    <span class="card__action"><span class="chip chip--faint">append-only</span></span>
  </div>
  <div class="card__body">
    <?php if ($timeline === []): ?>
    <p class="muted">No events recorded.</p>
    <?php else: ?>
    <div class="tl">
      <?php foreach ($timeline as $event): ?>
      <div class="tl__step is-done">
        <span class="tl__dot tl__dot--<?= View::e($event['level']) ?>"></span>
        <span class="tl__label<?= $event['kind'] === 'compliance' ? ' tl__label--compliance' : '' ?>">
          <?= View::e(Messages::event((string) $event['key'], $event['params'])) ?>
        </span>
        <span class="tl__time mono"><?= View::e(Format::utcTime((string) $event['created_at'])) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
