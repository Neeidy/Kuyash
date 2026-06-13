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
$contentByType = [];
foreach ($jobs as $job) {
    $jobsByNode[(string) $job['node']][] = $job;
    if (in_array($job['type'], ['idea_generation', 'script_draft', 'caption_generation', 'hashtag_generation'], true)) {
        $contentByType[(string) $job['type']] = $job;
    }
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

<?php if ($contentByType !== []): ?>
<div class="card">
  <div class="card__head"><h2>Generated content</h2></div>
  <div class="card__body">
    <?php
    $idea = $contentByType['idea_generation']['result'] ?? [];
    $script = $contentByType['script_draft']['result'] ?? [];
    $captions = $contentByType['caption_generation']['result']['captions'] ?? [];
    $hashtags = $contentByType['hashtag_generation']['result']['hashtags'] ?? [];
    ?>
    <?php if (isset($idea['hook']) || isset($idea['idea'])): ?>
    <div class="content-block">
      <h3 class="content-block__label">Idea</h3>
      <?php if (isset($idea['hook'])): ?><p class="content-block__hook">“<?= View::e((string) $idea['hook']) ?>”</p><?php endif; ?>
      <?php if (isset($idea['idea'])): ?><p class="content-block__body"><?= View::e((string) $idea['idea']) ?></p><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (isset($script['script'])): ?>
    <div class="content-block">
      <h3 class="content-block__label">Script
        <?php if (isset($script['word_count'])): ?><span class="chip chip--faint num"><?= (int) $script['word_count'] ?> words · ~<?= View::e((string) ($script['estimated_duration_s'] ?? '?')) ?>s</span><?php endif; ?>
        <?php if (isset($script['prompt_version'])): ?><span class="chip chip--faint mono"><?= View::e((string) $script['prompt_version']) ?></span><?php endif; ?>
      </h3>
      <blockquote class="content-block__script"><?= nl2br(View::e((string) $script['script'])) ?></blockquote>
    </div>
    <?php endif; ?>

    <?php if ($captions !== []): ?>
    <div class="content-block">
      <h3 class="content-block__label">Captions (per platform)</h3>
      <dl class="caption-grid">
        <?php foreach ($captions as $platform => $caption): ?>
        <div class="caption-grid__row">
          <dt class="mono"><?= View::e((string) $platform) ?></dt>
          <dd><?= View::e((string) $caption) ?></dd>
        </div>
        <?php endforeach; ?>
      </dl>
    </div>
    <?php endif; ?>

    <?php if ($hashtags !== []): ?>
    <div class="content-block">
      <h3 class="content-block__label">Hashtags</h3>
      <div class="tag-row">
        <?php foreach ($hashtags as $tag): ?><span class="tag"><?= View::e((string) $tag) ?></span><?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <p class="note">All content is generated mock-first; provider and any real cost are shown per job below.</p>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><h2>Jobs</h2></div>
  <div class="card__body">
    <ul class="job-list">
      <?php foreach ($jobs as $job): ?>
      <li class="job-row">
        <div class="job-row__main">
          <span class="job-row__type mono"><?= (int) $job['step'] ?>. <?= View::e($job['type']) ?> <span class="muted">#<?= (int) $job['id'] ?></span></span>
          <span class="job-row__entity"><?= View::e($job['node']) ?><?= $job['provider'] !== null ? ' · provider: ' . View::e((string) $job['provider']) : '' ?><?php if (isset($job['result']['cost_usd']) && (float) $job['result']['cost_usd'] > 0): ?> · ~$<?= View::e(number_format((float) $job['result']['cost_usd'], 4)) ?><?php endif; ?><?= $job['finished_at'] !== null ? ' · ' . View::e(Format::utcTime((string) $job['finished_at'])) : '' ?></span>
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
      <?php
      // truthful badges (compliance rule): the badge is rendered from the
      // STORED record's mode — an auto record NEVER reads "by you"
      $isAuto = ($approval['mode'] ?? 'manual') === 'auto';
      $scoreSnap = $isAuto ? (json_decode((string) ($approval['score_json'] ?? ''), true) ?: []) : [];
      ?>
      <li class="job-row">
        <div class="job-row__main">
          <span class="job-row__type mono"><?= View::e($approval['node']) ?></span>
          <span class="job-row__entity">mode: <?= View::e($approval['mode']) ?><?php if ($isAuto && isset($scoreSnap['quality']['score'])): ?> · quality score <?= (int) $scoreSnap['quality']['score'] ?><?php endif; ?></span>
        </div>
        <?php if ($isAuto): ?>
        <span class="chip chip--ai chip--record">
          Auto-approved by compliance agent (policy <?= View::e((string) ($approval['policy_version'] ?? '?')) ?>)
          · <?= View::e(substr((string) $approval['decided_at'], 0, 10) . ' ' . Format::utcTime((string) $approval['decided_at'])) ?>
        </span>
        <?php else: ?>
        <span class="chip chip--<?= $approval['decision'] === 'approved' ? 'ok' : 'err' ?> chip--record">
          <?= $approval['decision'] === 'approved' ? 'Approved by you' : 'Rejected by you' ?>
          · <?= View::e((string) ($approval['decided_by_email'] ?? '?')) ?>
          · <?= View::e(substr((string) $approval['decided_at'], 0, 10) . ' ' . Format::utcTime((string) $approval['decided_at'])) ?>
        </span>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <p class="note">Records reflect what actually happened: manual decisions carry the deciding
      account; auto-approvals are labelled as the compliance agent with their policy version —
      never as a human.</p>
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
