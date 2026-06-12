<?php

declare(strict_types=1);

use Kuyash\Core\Format;
use Kuyash\Core\Messages;
use Kuyash\Core\View;

/** @var list<array<string, mixed>> $awaiting jobs paused for approval */
/** @var list<array<string, mixed>> $jobs newest first */
/** @var list<array<string, mixed>> $runs newest first */
/** @var string $csrfField trusted generated HTML */
?>
<div class="screen-head">
  <div>
    <h1>Queue</h1>
    <p class="screen-sub">Pipeline jobs, approvals and runs. Mock executors finish instantly —
      refresh after running the worker.</p>
  </div>
  <div class="screen-head__actions">
    <span class="chip chip--<?= $awaiting === [] ? 'ok' : 'warn' ?> num"><?= count($awaiting) ?> waiting for you</span>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Approvals</h2></div>
  <div class="card__body">
    <?php if ($awaiting === []): ?>
    <p class="muted">Nothing is waiting for your decision.</p>
    <?php else: ?>
    <div class="approve-list">
      <?php foreach ($awaiting as $job): ?>
      <article class="approve-card">
        <div class="approve-card__main">
          <h3 class="mono"><?= View::e($job['node']) ?> · run #<?= (int) $job['run_id'] ?></h3>
          <div class="approve-card__meta">
            <span class="chip chip--neutral mono"><?= View::e($job['type']) ?></span>
            <span class="chip chip--warn"><span class="dot"></span>awaiting approval</span>
            <a class="btn btn--ghost btn--sm" href="/runs/<?= (int) $job['run_id'] ?>">View run</a>
          </div>
          <?php if ($job['type'] === 'script_draft' && isset($job['result']['script'])): ?>
          <blockquote class="approve-card__quote"><?= nl2br(View::e(mb_substr((string) $job['result']['script'], 0, 400))) ?></blockquote>
          <?php elseif (isset($job['result']['summary'])): ?>
          <p class="approve-card__note"><?= View::e((string) $job['result']['summary']) ?></p>
          <?php endif; ?>
        </div>
        <div class="approve-card__actions">
          <form method="post" action="/queue/job/<?= (int) $job['id'] ?>/approve">
            <?= $csrfField ?>
            <button type="submit" class="btn btn--primary btn--sm">Approve</button>
          </form>
          <form method="post" action="/queue/job/<?= (int) $job['id'] ?>/reject"
                data-confirm="Reject this and cancel the whole run? This cannot be undone.">
            <?= $csrfField ?>
            <button type="submit" class="btn btn--danger-ghost btn--sm">Reject</button>
          </form>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <p class="note">Approving records “Approved by you” with your account and timestamp —
      approval records are never faked.</p>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Jobs</h2>
    <span class="card__action"><span class="chip chip--faint num"><?= count($jobs) ?> shown</span></span>
  </div>
  <div class="card__body">
    <?php if ($jobs === []): ?>
    <div class="ui-state">
      <span class="ui-state__icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M8 1.5l6 3-6 3-6-3z"/><path d="M2 8l6 3 6-3M2 11l6 3 6-3"/></svg></span>
      <h3>The queue is empty</h3>
      <p>Start a run from a <a href="/workflows">workflow</a> — its jobs appear here for the worker.</p>
    </div>
    <?php else: ?>
    <ul class="job-list">
      <?php foreach ($jobs as $job): ?>
      <li class="job-row">
        <div class="job-row__main">
          <span class="job-row__type mono"><?= View::e($job['type']) ?> <span class="muted">#<?= (int) $job['id'] ?></span></span>
          <span class="job-row__entity">run #<?= (int) $job['run_id'] ?> · <?= View::e($job['node']) ?><?= $job['provider'] !== null ? ' · ' . View::e((string) $job['provider']) : '' ?></span>
          <?php if ($job['status'] === 'failed' && $job['error_message'] !== null): ?>
          <span class="job-row__error"><?= View::e((string) $job['error_message']) ?> (retry <?= $job['retry_count'] ?>/<?= $job['max_retries'] ?>)</span>
          <?php endif; ?>
        </div>
        <span class="chip chip--<?= Format::statusTone((string) $job['status']) ?>"><span class="dot dot--<?= Format::statusTone((string) $job['status']) ?>"></span><?= View::e(Messages::status((string) $job['status'])) ?></span>
        <?php if ($job['status'] === 'failed'): ?>
        <form method="post" action="/queue/job/<?= (int) $job['id'] ?>/retry">
          <?= $csrfField ?>
          <button type="submit" class="btn btn--ghost btn--sm">Retry</button>
        </form>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Runs</h2></div>
  <div class="card__body">
    <?php if ($runs === []): ?>
    <p class="muted">No runs yet.</p>
    <?php else: ?>
    <ul class="job-list">
      <?php foreach ($runs as $run): ?>
      <li class="job-row">
        <div class="job-row__main">
          <span class="job-row__type">run #<?= (int) $run['id'] ?> — <?= View::e($run['workflow_name']) ?></span>
          <span class="job-row__entity mono"><?= View::e($run['workflow_template']) ?><?= $run['current_node'] !== null ? ' · at ' . View::e((string) $run['current_node']) : '' ?></span>
        </div>
        <span class="chip chip--<?= Format::statusTone((string) $run['status']) ?>"><span class="dot dot--<?= Format::statusTone((string) $run['status']) ?>"></span><?= View::e(Messages::status((string) $run['status'])) ?></span>
        <a class="btn btn--ghost btn--sm" href="/runs/<?= (int) $run['id'] ?>">Timeline</a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
