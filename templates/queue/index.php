<?php

declare(strict_types=1);

use Kuyash\Core\Format;
use Kuyash\Core\Messages;
use Kuyash\Core\View;

/** @var list<array<string, mixed>> $awaiting jobs paused for approval */
/** @var list<array<string, mixed>> $jobs newest first */
/** @var list<array<string, mixed>> $runs newest first */
/** @var string $csrfField trusted generated HTML */
/** @var bool $workerAlive */
?>
<?php if (($workerAlive ?? true) === false): ?>
<div class="callout callout--warn callout--banner" role="alert">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2L1.5 13.5h13z"/><path d="M8 6.5V10M8 12h.01"/></svg></span>
  <div><strong>The background worker is not running.</strong> Queued jobs below will sit idle
    until it starts — run <span class="mono">php bin/worker.php</span>.</div>
</div>
<?php endif; ?>
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
            <?php if ($job['provider'] !== null): ?>
            <span class="chip chip--faint mono"><?= View::e((string) $job['provider']) ?></span>
            <?php endif; ?>
            <?php if (isset($job['result']['prompt_version'])): ?>
            <span class="chip chip--faint mono"><?= View::e((string) $job['result']['prompt_version']) ?></span>
            <?php endif; ?>
            <?php if (isset($job['result']['word_count'], $job['result']['estimated_duration_s'])): ?>
            <span class="chip chip--neutral num"><?= (int) $job['result']['word_count'] ?> words · ~<?= View::e((string) $job['result']['estimated_duration_s']) ?>s</span>
            <?php endif; ?>
            <?php if ($job['type'] === 'render_review' && isset($job['result']['compliance']['status'])): ?>
            <?php $cs = (string) $job['result']['compliance']['status']; ?>
            <?php if ($cs === 'warn'): ?>
            <span class="chip chip--warn"><span class="dot"></span>slop <?= View::e(number_format((float) ($job['result']['compliance']['slop_score'] ?? 0), 2)) ?> — too similar to recent posts</span>
            <?php elseif ($cs === 'pass_with_ai_label'): ?>
            <span class="chip chip--ai">AI label will be set</span>
            <?php elseif ($cs === 'pass'): ?>
            <span class="chip chip--ok"><span class="dot"></span>compliance pass</span>
            <?php endif; ?>
            <?php endif; ?>
            <a class="btn btn--ghost btn--sm" href="/runs/<?= (int) $job['run_id'] ?>">View run</a>
          </div>
          <?php if ($job['type'] === 'script_draft' && isset($job['result']['script'])): ?>
          <blockquote class="approve-card__quote"><?= nl2br(View::e(mb_substr((string) $job['result']['script'], 0, 400))) ?></blockquote>
          <?php elseif ($job['type'] === 'render_review'): ?>
            <?php $draftId = $job['result']['draft_render_id'] ?? null; $libId = $job['result']['library_asset_id'] ?? null; ?>
            <?php if ($draftId !== null): ?>
            <video class="approve-card__video" src="/render/<?= (int) $draftId ?>" poster="/render/<?= (int) $draftId ?>/poster" controls preload="metadata" playsinline></video>
            <?php elseif ($libId !== null): ?>
            <video class="approve-card__video" src="/media/<?= (int) $libId ?>" controls preload="metadata" playsinline></video>
            <?php endif; ?>
            <?php if (isset($job['result']['summary'])): ?>
            <p class="approve-card__note"><?= View::e((string) $job['result']['summary']) ?><?= ($job['result']['ai_label_required'] ?? false) ? ' · AI label required' : '' ?></p>
            <?php endif; ?>
          <?php elseif (isset($job['result']['summary'])): ?>
          <p class="approve-card__note"><?= View::e((string) $job['result']['summary']) ?></p>
          <?php endif; ?>
        </div>
        <div class="approve-card__actions">
          <form method="post" action="/queue/job/<?= (int) $job['id'] ?>/approve">
            <?= $csrfField ?>
            <?php if ($job['type'] === 'render_review'): ?>
            <label class="approve-card__schedule">
              <span class="muted">Schedule (UTC, optional)</span>
              <input type="datetime-local" name="scheduled_for">
            </label>
            <?php endif; ?>
            <button type="submit" class="btn btn--primary btn--sm">Approve<?= $job['type'] === 'render_review' ? ' &amp; publish' : '' ?></button>
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
    <p class="note">Approving here records “Approved by you” with your account and timestamp.
      In Auto mode the agent's own approvals are recorded as “Auto-approved by compliance agent” —
      records are never faked either way.</p>
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
          <span class="job-row__entity">run #<?= (int) $job['run_id'] ?> · <?= View::e($job['node']) ?><?= $job['provider'] !== null ? ' · ' . View::e((string) $job['provider']) : '' ?><?php if (isset($job['result']['cost_usd']) && (float) $job['result']['cost_usd'] > 0): ?> · ~$<?= View::e(number_format((float) $job['result']['cost_usd'], 4)) ?><?php endif; ?></span>
          <?php if ($job['status'] === 'failed' && $job['error_message'] !== null): ?>
          <span class="job-row__error"><?= View::e((string) $job['error_message']) ?> (retry <?= $job['retry_count'] ?>/<?= $job['max_retries'] ?>)</span>
          <?php elseif ($job['status'] === 'queued' && str_starts_with((string) $job['error_message'], 'deferred:')): ?>
          <span class="job-row__entity">held by guardrail — <?= View::e((string) $job['error_message']) ?> · retries at <?= View::e(Format::utcTime((string) $job['run_after'])) ?> UTC</span>
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
