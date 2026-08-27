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
/** @var list<array<string, mixed>> $posts per-account publish targets (may be unset) */

$posts ??= [];
$postTone = static fn (string $s): string => match ($s) {
    'published' => 'ok',
    'failed' => 'err',
    'publishing' => 'info',
    default => 'neutral',
};

$jobsByNode = [];
$contentByType = [];
// kept OUT of $contentByType on purpose: that map drives the "Generated content"
// card, and the compliance result is not content — it is the verdict about it.
$complianceResult = [];
foreach ($jobs as $job) {
    $jobsByNode[(string) $job['node']][] = $job;
    if (in_array($job['type'], ['idea_generation', 'script_draft', 'caption_generation', 'hashtag_generation'], true)) {
        $contentByType[(string) $job['type']] = $job;
    }
    if ((string) $job['type'] === 'compliance_check') {
        $complianceResult = (array) ($job['result'] ?? []);
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
    <h1><?= View::t('runs.run_n', ['n' => (int) $run['id']]) ?> — <?= View::e($run['workflow_name']) ?></h1>
    <p class="screen-sub mono"><?= View::t('runs.entity_label') ?> <?= View::e($run['entity_type']) ?><?= $run['entity_id'] !== null ? ' #' . (int) $run['entity_id'] : '' ?> ·
      <?= View::t('runs.started_label') ?> <?= View::e($run['created_at']) ?></p>
  </div>
  <div class="screen-head__actions">
    <span class="chip chip--<?= Format::statusTone((string) $run['status']) ?>"><span class="dot dot--<?= Format::statusTone((string) $run['status']) ?>"></span><?= View::e(Messages::status((string) $run['status'])) ?></span>
    <a class="btn btn--ghost btn--sm" href="/queue"><?= View::t('runs.back_to_queue') ?></a>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('runs.node_track') ?></h2></div>
  <div class="card__body">
    <div class="wf-canvas">
      <div class="node-track">
        <?php foreach ($run['nodes'] as $node): ?>
        <?php $state = $nodeState((string) $node['node']); $stateLabel = View::t('runs.state_' . $state); ?>
        <div class="node-wrap">
          <div class="node<?= ($node['locked'] ?? false) ? ' node--locked' : '' ?><?= $state === 'pending' ? ' node--pending' : '' ?>">
            <span class="node__status node__status--<?= View::e($state) ?>" title="<?= $stateLabel ?>"></span>
            <span class="node__name mono"><?= View::e($node['node']) ?></span>
            <span class="node__desc"><?= $stateLabel ?></span>
          </div>
          <span class="node-connector" aria-hidden="true"></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php /* THE VIDEO. This page carried a run's steps, its text, its jobs and its
         approval record, and nowhere on it a picture of the thing all of that
         is about — not even on a published run, where the page is read as
         evidence that it went out. The poster rides on the <video>, never a
         sibling <img>: an absolutely-positioned <video preload="metadata">
         paints black over anything stacked under it. */ ?>
<?php $preview ??= null; ?>
<?php if ($preview !== null): ?>
<div class="card">
  <div class="card__head"><h2><?= View::t('run.preview') ?></h2></div>
  <div class="card__body">
    <div class="inline-player run-player" data-inline-player>
      <video class="inline-player__video" src="<?= View::e((string) $preview['src']) ?>"<?= $preview['poster'] !== null ? ' poster="' . View::e((string) $preview['poster']) . '"' : '' ?> preload="metadata" playsinline></video>
      <button type="button" class="inline-player__play" aria-label="<?= View::t('player.play') ?>">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
      </button>
      <span class="inline-player__badge"><span class="inline-player__badge-dot"></span><?= View::t('player.playing') ?></span>
      <span class="inline-player__progress"></span>
    </div>
  </div>
</div>
<?php endif; ?>

<?php $editorShown = ($text ?? null) !== null; ?>
<?php if ($editorShown): ?>
<?php /* Phase 25 — the same editor the approval card shows. Placed above the
         read-only record below, because while the text can still be changed,
         changing it is the thing an operator came here to do. */ ?>
<div class="card">
  <div class="card__head"><h2><?= View::t('content.card') ?></h2></div>
  <div class="card__body">
    <?php
      $limits = $text['limits'];
      $disclosureLine = $text['disclosure'];
      $runId = (int) $run['id'];
      $backTo = 'run';
      $withHeading = false;
      // this screen has no approval card, so the editor carries the chip itself —
      // for every run, so that "was this checked?" is not answered differently
      // depending on whether somebody edited the words
      $showBadge = true;
      $generatedCompliance = [
          'status' => (string) ($complianceResult['status'] ?? ''),
          'slop' => $complianceResult['checks']['slop']['score'] ?? null,
      ];
      $text = $text['text'];
      require __DIR__ . '/../partials/text-editor.php';
    ?>
  </div>
</div>
<?php endif; ?>

<?php
$idea = $contentByType['idea_generation']['result'] ?? [];
$script = $contentByType['script_draft']['result'] ?? [];
$captions = $contentByType['caption_generation']['result']['captions'] ?? [];
$hashtags = $contentByType['hashtag_generation']['result']['hashtags'] ?? [];
// The editor above already shows the caption and the tags — as fields, current,
// and editable. Repeating them here printed the same words a second time under
// a different (and more technical) name, so the record card keeps only what the
// editor does not carry.
$showsText = !$editorShown;
$hasRecord = isset($idea['hook']) || isset($idea['idea']) || isset($script['script'])
    || ($showsText && ($captions !== [] || $hashtags !== []));
?>
<?php if ($contentByType !== [] && $hasRecord): ?>
<div class="card">
  <div class="card__head"><h2><?= View::t('runs.generated_content') ?></h2></div>
  <div class="card__body">
    <?php if (isset($idea['hook']) || isset($idea['idea'])): ?>
    <div class="content-block">
      <h3 class="content-block__label"><?= View::t('runs.idea') ?></h3>
      <?php if (isset($idea['hook'])): ?><p class="content-block__hook">“<?= View::e((string) $idea['hook']) ?>”</p><?php endif; ?>
      <?php if (isset($idea['idea'])): ?><p class="content-block__body"><?= View::e((string) $idea['idea']) ?></p><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (isset($script['script'])): ?>
    <div class="content-block">
      <h3 class="content-block__label"><?= View::t('runs.script') ?>
        <?php if (isset($script['word_count'])): ?><span class="chip chip--faint num"><?= (int) $script['word_count'] ?> <?= View::t('queue.words') ?> · ~<?= View::e((string) ($script['estimated_duration_s'] ?? '?')) ?>s</span><?php endif; ?>
      </h3>
      <blockquote class="content-block__script"><?= nl2br(View::e((string) $script['script'])) ?></blockquote>
    </div>
    <?php endif; ?>

    <?php if ($showsText && $captions !== []): ?>
    <div class="content-block">
      <h3 class="content-block__label"><?= View::t('runs.captions') ?></h3>
      <dl class="caption-grid">
        <?php foreach ($captions as $platform => $caption): ?>
        <div class="caption-grid__row">
          <dt><?= View::e(Messages::platform((string) $platform)) ?></dt>
          <dd><?= View::e((string) $caption) ?></dd>
        </div>
        <?php endforeach; ?>
      </dl>
    </div>
    <?php endif; ?>

    <?php if ($showsText && $hashtags !== []): ?>
    <div class="content-block">
      <h3 class="content-block__label"><?= View::t('runs.hashtags') ?></h3>
      <div class="tag-row">
        <?php foreach ($hashtags as $tag): ?><span class="tag"><?= View::e((string) $tag) ?></span><?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <p class="note"><?= View::t('runs.content_note') ?></p>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><h2><?= View::t('queue.jobs') ?></h2></div>
  <div class="card__body">
    <ul class="job-list">
      <?php foreach ($jobs as $job): ?>
      <li class="job-row">
        <div class="job-row__main">
          <span class="job-row__type"><?= (int) $job['step'] ?>. <?= View::e(Messages::jobType((string) $job['type'])) ?> <span class="muted">#<?= (int) $job['id'] ?></span></span>
          <span class="job-row__entity"><?php
            $jmeta = [];
            if (isset($job['result']['cost_usd']) && (float) $job['result']['cost_usd'] > 0) { $jmeta[] = '~$' . number_format((float) $job['result']['cost_usd'], 4); }
            if ($job['finished_at'] !== null) { $jmeta[] = Format::utcTime((string) $job['finished_at']); }
            echo View::e(implode(' · ', $jmeta));
          ?></span>
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

<?php if ($posts !== []): ?>
<div class="card">
  <div class="card__head"><h2><?= View::t('runs.published_targets') ?></h2>
    <span class="card__action"><span class="chip chip--faint num"><?= count($posts) ?></span></span>
  </div>
  <div class="card__body">
    <ul class="job-list">
      <?php foreach ($posts as $post): ?>
      <li class="job-row">
        <div class="job-row__main">
          <span class="job-row__type"><?= View::e((string) $post['account_handle']) ?>
            <span class="muted">· <?= View::e(Messages::platform((string) $post['platform'])) ?></span></span>
          <span class="job-row__entity">
            <?php if (($post['ai_label_applied'] ?? false)): ?><span class="chip chip--ai"><?= View::t('runs.ai_label_set') ?></span> <?php endif; ?>
            <?php if (($post['scheduled_for'] ?? null) !== null): ?><?= View::t('runs.scheduled_label') ?> <?= View::e(substr((string) $post['scheduled_for'], 0, 16)) ?>Z · <?php endif; ?>
            <?php if (preg_match('#^https?://#i', (string) ($post['external_url'] ?? '')) === 1): ?><a href="<?= View::e((string) $post['external_url']) ?>" rel="noopener noreferrer nofollow" target="_blank"><?= View::t('runs.view_post') ?></a><?php endif; ?>
            <?php if (($post['error_message'] ?? null) !== null): ?><span class="job-row__error"><?= View::e((string) $post['error_message']) ?></span><?php endif; ?>
          </span>
        </div>
        <span class="chip chip--<?= $postTone((string) $post['status']) ?>"><span class="dot dot--<?= $postTone((string) $post['status']) ?>"></span><?= View::e(Messages::status((string) $post['status'])) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <p class="note"><?= View::t('runs.targets_note') ?></p>
  </div>
</div>
<?php endif; ?>

<?php if ($approvals !== []): ?>
<div class="card">
  <div class="card__head"><h2><?= View::t('runs.approval_records') ?></h2></div>
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
          <span class="job-row__type"><?= View::e(Messages::node((string) $approval['node'])) ?></span>
          <span class="job-row__entity"><?= View::t('digest.mode_label') ?> <?= View::e($approval['mode']) ?><?php if ($isAuto && isset($scoreSnap['quality']['score'])): ?> · <?= View::t('runs.quality_score_label') ?> <?= (int) $scoreSnap['quality']['score'] ?><?php endif; ?></span>
        </div>
        <?php if ($isAuto): ?>
        <span class="chip chip--ai chip--record">
          <?= View::t('digest.approved_by_agent', ['policy' => (string) ($approval['policy_version'] ?? '?')]) ?>
          · <?= View::e(substr((string) $approval['decided_at'], 0, 10) . ' ' . Format::utcTime((string) $approval['decided_at'])) ?>
        </span>
        <?php else: ?>
        <?php
        /* "by you" ONLY when the viewer is the person who decided. The label was
           hard-coded, so every manual record told whoever was looking that THEY
           approved it — false for a second operator in the same workspace, and
           false for any record made by another account. The email sat right
           beside it saying otherwise, which makes it a contradiction rather than
           a mere omission: the claim was in the chip, the truth was in the
           metadata. */
        $byMe = $viewerId !== null && (int) ($approval['decided_by'] ?? 0) === $viewerId;
        $who = trim((string) ($approval['decided_by_name'] ?? '')) !== ''
            ? (string) $approval['decided_by_name'] . ' · ' . (string) ($approval['decided_by_email'] ?? '?')
            : (string) ($approval['decided_by_email'] ?? '?');
        ?>
        <span class="chip chip--<?= $approval['decision'] === 'approved' ? 'ok' : 'err' ?> chip--record">
          <?= $approval['decision'] === 'approved'
              ? View::t($byMe ? 'runs.approved_by_you' : 'runs.approved_by')
              : View::t($byMe ? 'runs.rejected_by_you' : 'runs.rejected_by') ?>
          · <?= View::e($who) ?>
          · <?= View::e(substr((string) $approval['decided_at'], 0, 10) . ' ' . Format::utcTime((string) $approval['decided_at'])) ?>
        </span>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <p class="note"><?= View::t('runs.records_note') ?></p>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><h2><?= View::t('runs.event_timeline') ?></h2>
    <span class="card__action"><span class="chip chip--faint"><?= View::t('runs.append_only') ?></span></span>
  </div>
  <div class="card__body">
    <?php if ($timeline === []): ?>
    <p class="muted"><?= View::t('runs.no_events') ?></p>
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
