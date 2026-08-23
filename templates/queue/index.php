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
/** @var list<array<string, mixed>> $slots weekly plan slots, each with next_at resolved (Phase 23) */
/** @var string $timezone workspace zone the slot times are written in */
?>
<?php if (($workerAlive ?? true) === false): ?>
<div class="callout callout--warn callout--banner" role="alert">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2L1.5 13.5h13z"/><path d="M8 6.5V10M8 12h.01"/></svg></span>
  <div><strong><?= View::t('dash.worker_down_title') ?></strong> <?= View::t('queue.worker_down_body') ?></div>
</div>
<?php endif; ?>
<div class="screen-head">
  <div>
    <h1><?= View::t('queue.title') ?></h1>
    <p class="screen-sub"><?= View::t('queue.subtitle') ?></p>
  </div>
  <div class="screen-head__actions">
    <span class="chip chip--<?= $awaiting === [] ? 'ok' : 'warn' ?> num"><?= View::t('queue.waiting_for_you', ['n' => count($awaiting)]) ?></span>
  </div>
</div>

<div class="card card--primary">
  <div class="card__head"><h2><?= View::t('queue.approvals') ?></h2></div>
  <div class="card__body">
    <?php if ($awaiting === []): ?>
    <p class="muted"><?= View::t('dash.nothing_waiting') ?></p>
    <?php else: ?>
    <div class="approve-list">
      <?php foreach ($awaiting as $job): ?>
      <article class="approve-card">
        <div class="approve-card__main">
          <h3><?= View::e(Messages::jobType((string) $job['type'])) ?> · <?= View::t('common.run_n', ['n' => (int) $job['run_id']]) ?></h3>
          <div class="approve-card__meta">
            <span class="chip chip--warn"><span class="dot"></span><?= View::t('status.awaiting_approval') ?></span>
            <?php if (isset($job['result']['word_count'], $job['result']['estimated_duration_s'])): ?>
            <span class="chip chip--neutral num"><?= (int) $job['result']['word_count'] ?> <?= View::t('queue.words') ?> · ~<?= View::e((string) $job['result']['estimated_duration_s']) ?>s</span>
            <?php endif; ?>
            <?php if ($job['type'] === 'render_review' && isset($job['result']['compliance']['status'])): ?>
            <?php $cs = (string) $job['result']['compliance']['status']; ?>
            <?php if ($cs === 'warn'): ?>
            <span class="chip chip--warn chip--wrap"><span class="dot"></span><?= View::t('queue.slop_label') ?> <?= View::e(number_format((float) ($job['result']['compliance']['slop_score'] ?? 0), 2)) ?> <?= View::t('queue.slop_too_similar') ?></span>
            <?php elseif ($cs === 'pass_with_ai_label'): ?>
            <span class="chip chip--ai"><?= View::t('queue.ai_label_will_set') ?></span>
            <?php elseif ($cs === 'pass'): ?>
            <span class="chip chip--ok"><span class="dot"></span><?= View::t('queue.compliance_pass') ?></span>
            <?php endif; ?>
            <?php endif; ?>
            <a class="btn btn--ghost btn--sm" href="/runs/<?= (int) $job['run_id'] ?>"><?= View::t('common.view_run') ?></a>
          </div>
          <?php if ($job['type'] === 'script_draft' && isset($job['result']['script'])): ?>
          <blockquote class="approve-card__quote"><?= nl2br(View::e(mb_substr((string) $job['result']['script'], 0, 400))) ?></blockquote>
          <?php elseif ($job['type'] === 'render_review'): ?>
            <?php $draftId = $job['result']['draft_render_id'] ?? null; $libId = $job['result']['library_asset_id'] ?? null; ?>
            <?php if ($draftId !== null || $libId !== null): ?>
            <?php $src = $draftId !== null ? '/render/' . (int) $draftId : '/media/' . (int) $libId; ?>
            <div class="inline-player approve-card__player" data-inline-player>
              <?php if ($draftId !== null): ?><img class="inline-player__poster" src="/render/<?= (int) $draftId ?>/poster" alt="" loading="lazy"><?php endif; ?>
              <video class="inline-player__video" src="<?= $src ?>" preload="none" playsinline></video>
              <button type="button" class="inline-player__play" aria-label="<?= View::t('player.play') ?>">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
              </button>
              <span class="inline-player__badge"><span class="inline-player__badge-dot"></span><?= View::t('player.playing') ?></span>
              <span class="inline-player__progress"></span>
            </div>
            <?php else: ?>
            <div class="inline-player inline-player--pending approve-card__player">
              <span class="inline-player__ph-type"><?= View::e(Messages::jobType('render_review')) ?></span>
              <span class="inline-player__ph-note"><?= View::t('player.preview_pending') ?></span>
            </div>
            <?php endif; ?>
            <?php /* Phase 21: show a clean, truthful compliance line — never the raw
                     internal summary ("Render review (mock): … policy mock-v0"). */ ?>
            <?php $cs = $job['result']['compliance']['status'] ?? null; ?>
            <?php if ($cs === 'pass' || $cs === 'pass_with_ai_label'): ?>
            <p class="approve-card__note"><?= View::t('queue.compliance_passed') ?><?= ($job['result']['ai_label_required'] ?? false) ? ' · ' . View::t('queue.ai_label_required') : '' ?></p>
            <?php elseif (($job['result']['ai_label_required'] ?? false)): ?>
            <p class="approve-card__note"><?= View::t('queue.ai_label_required') ?></p>
            <?php endif; ?>
          <?php elseif (isset($job['result']['summary'])): ?>
          <p class="approve-card__note"><?= View::e((string) $job['result']['summary']) ?></p>
          <?php endif; ?>
        </div>
        <div class="approve-card__actions">
          <form method="post" action="/queue/job/<?= (int) $job['id'] ?>/approve">
            <?= $csrfField ?>
            <?php if ($job['type'] === 'render_review'): ?>
            <div class="approve-card__schedule">
              <span class="muted"><?= View::t('queue.schedule_label', ['zone' => $timezone]) ?></span>
              <?php if ($slots !== []): ?>
              <?php /* the weekly plan first: picking a planned time is the common
                       case, and each option states the real instant it lands on */ ?>
              <label class="field field--inline">
                <select name="slot_id">
                  <option value=""><?= View::t('slots.publish_now') ?></option>
                  <?php foreach ($slots as $slot): ?>
                  <option value="<?= (int) $slot['id'] ?>">
                    <?= View::t('day.' . (int) $slot['weekday']) ?> <?= View::e((string) $slot['time_hhmm']) ?>
                    · <?= View::e(Messages::until((string) $slot['next_at'])) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <?php endif; ?>
              <label class="field field--inline">
                <?php /* always the "exact time" hint: the zone-bearing question is
                         already rendered above, and repeating it here without its
                         {zone} param printed the placeholder literally — the state
                         every brand-new workspace (no slots yet) lands on. */ ?>
                <span class="field__hint"><?= View::t('slots.pick_time') ?></span>
                <input type="datetime-local" name="scheduled_for">
              </label>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn--primary btn--sm"><?= $job['type'] === 'render_review' ? View::t('queue.approve_publish') : View::t('queue.approve') ?></button>
          </form>
          <form method="post" action="/queue/job/<?= (int) $job['id'] ?>/reject"
                data-confirm="<?= View::t('queue.reject_confirm') ?>">
            <?= $csrfField ?>
            <button type="submit" class="btn btn--danger-ghost btn--sm"><?= View::t('queue.reject') ?></button>
          </form>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <p class="note"><?= View::t('queue.approval_note') ?></p>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('queue.jobs') ?></h2>
    <span class="card__action"><span class="chip chip--faint num"><?= View::t('queue.n_shown', ['n' => count($jobs)]) ?></span></span>
  </div>
  <div class="card__body">
    <?php if ($jobs === []): ?>
    <div class="ui-state">
      <span class="ui-state__icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M8 1.5l6 3-6 3-6-3z"/><path d="M2 8l6 3 6-3M2 11l6 3 6-3"/></svg></span>
      <h3><?= View::t('queue.empty') ?></h3>
      <p><?= View::t('queue.empty_hint_1') ?> <a href="/workflows"><?= View::t('dash.a_workflow') ?></a> <?= View::t('queue.empty_hint_2') ?></p>
    </div>
    <?php else: ?>
    <ul class="job-list">
      <?php foreach ($jobs as $job): ?>
      <li class="job-row">
        <div class="job-row__main">
          <span class="job-row__type"><?= View::e(Messages::jobType((string) $job['type'])) ?> <span class="muted">#<?= (int) $job['id'] ?></span></span>
          <span class="job-row__entity"><?= View::t('common.run_n', ['n' => (int) $job['run_id']]) ?><?php if (isset($job['result']['cost_usd']) && (float) $job['result']['cost_usd'] > 0): ?> · ~$<?= View::e(number_format((float) $job['result']['cost_usd'], 4)) ?><?php endif; ?></span>
          <?php if ($job['status'] === 'failed' && $job['error_message'] !== null): ?>
          <span class="job-row__error"><?= View::e((string) $job['error_message']) ?><?php if (str_starts_with((string) $job['error_message'], 'non-retryable:')): ?> <?= View::t('queue.no_auto_retry') ?><?php else: ?> <?= View::t('queue.retry_xy', ['x' => $job['retry_count'], 'y' => $job['max_retries']]) ?><?php endif; ?></span>
          <?php elseif ($job['status'] === 'queued' && str_starts_with((string) $job['error_message'], 'deferred:')): ?>
          <span class="job-row__entity"><?= View::t('queue.held_by_guardrail') ?> <?= View::e((string) $job['error_message']) ?> · <?= View::t('queue.retries_at') ?> <?= View::e(Format::utcTime((string) $job['run_after'])) ?> UTC</span>
          <?php endif; ?>
        </div>
        <span class="chip chip--<?= Format::statusTone((string) $job['status']) ?>"><span class="dot dot--<?= Format::statusTone((string) $job['status']) ?>"></span><?= View::e(Messages::status((string) $job['status'])) ?></span>
        <?php if ($job['status'] === 'failed'): ?>
        <form method="post" action="/queue/job/<?= (int) $job['id'] ?>/retry">
          <?= $csrfField ?>
          <button type="submit" class="btn btn--ghost btn--sm"><?= View::t('queue.retry') ?></button>
        </form>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('queue.runs') ?></h2></div>
  <div class="card__body">
    <?php if ($runs === []): ?>
    <p class="muted"><?= View::t('queue.no_runs') ?></p>
    <?php else: ?>
    <ul class="job-list">
      <?php foreach ($runs as $run): ?>
      <li class="job-row">
        <div class="job-row__main">
          <span class="job-row__type"><?= View::t('common.run_n', ['n' => (int) $run['id']]) ?> — <?= View::e($run['workflow_name']) ?></span>
          <span class="job-row__entity"><?= $run['current_node'] !== null ? View::t('dash.at') . ' ' . View::e(Messages::node((string) $run['current_node'])) : '' ?></span>
        </div>
        <span class="chip chip--<?= Format::statusTone((string) $run['status']) ?>"><span class="dot dot--<?= Format::statusTone((string) $run['status']) ?>"></span><?= View::e(Messages::status((string) $run['status'])) ?></span>
        <a class="btn btn--ghost btn--sm" href="/runs/<?= (int) $run['id'] ?>"><?= View::t('dash.timeline') ?></a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
