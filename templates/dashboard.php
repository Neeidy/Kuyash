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
/** @var string $csrfField */
/**
 * @var array{
 *   kpis: array<string,int>,
 *   business: array{balance_cents:int, spent_mtd_cents:int, charges_mtd:int, granted_week_cents:int, budget_cap_cents:int|null, remaining_budget_cents:int|null, cost_per_content_cents:int|null, awaiting:int},
 *   activeRuns: list<array<string,mixed>>,
 *   awaiting: list<array<string,mixed>>,
 *   accounts: list<array<string,mixed>>|null
 * } $cockpit
 */

$biz = $cockpit['business'];
?>
<?php if (($workerAlive ?? true) === false): ?>
<div class="callout callout--warn callout--banner" role="alert">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2L1.5 13.5h13z"/><path d="M8 6.5V10M8 12h.01"/></svg></span>
  <div><strong><?= View::t('dash.worker_down_title') ?></strong> <?= View::t('dash.worker_down_body') ?></div>
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

<div class="kpi-strip">
  <div class="kpi">
    <span class="kpi__label"><?= View::t('dash.kpi_budget') ?></span>
    <?php if ($biz['remaining_budget_cents'] !== null): ?>
    <span class="kpi__num num mono" data-count="<?= number_format($biz['remaining_budget_cents'] / 100, 2, '.', '') ?>" data-count-prefix="$" data-count-decimals="2"><?= View::e(Format::cents($biz['remaining_budget_cents'])) ?></span>
    <span class="kpi__delta"><?= View::t('dash.budget_of', ['amount' => Format::cents((int) $biz['budget_cap_cents'])]) ?></span>
    <?php else: ?>
    <span class="kpi__num num mono">—</span>
    <span class="kpi__delta"><?= View::t('dash.no_budget_cap') ?></span>
    <?php endif; ?>
  </div>
  <div class="kpi">
    <span class="kpi__label"><?= View::t('dash.kpi_spent') ?></span>
    <span class="kpi__num num mono" data-count="<?= number_format($biz['spent_mtd_cents'] / 100, 2, '.', '') ?>" data-count-prefix="$" data-count-decimals="2"><?= View::e(Format::cents($biz['spent_mtd_cents'])) ?></span>
    <span class="kpi__delta"><?= View::t('dash.charges_mtd', ['n' => $biz['charges_mtd']]) ?></span>
  </div>
  <div class="kpi">
    <span class="kpi__label"><?= View::t('dash.kpi_cost_per') ?></span>
    <span class="kpi__num num mono"><?= $biz['cost_per_content_cents'] === null ? '—' : View::e(Format::cents($biz['cost_per_content_cents'])) ?></span>
    <span class="kpi__delta"><?= $biz['cost_per_content_cents'] === null ? View::t('dash.no_data_yet') : View::t('dash.per_render') ?></span>
  </div>
  <div class="kpi">
    <span class="kpi__label"><?= View::t('dash.kpi_awaiting') ?></span>
    <span class="kpi__num num mono" data-live-awaiting><?= (int) $biz['awaiting'] ?></span>
    <span class="kpi__delta"><?= View::t('dash.needs_review') ?></span>
  </div>
</div>

<?php /* Phase 23 — the soonest publish actually waiting in the queue. Read from
         the real job gate: a slot plan alone never appears here, only something
         genuinely scheduled. The countdown ticks client-side from the exact
         instant in datetime, and degrades to the server-rendered phrase with
         JS off. */ ?>
<div class="callout callout--banner next-publish">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="8" cy="8" r="6.5"/><path d="M8 4.5V8l2.5 1.5"/></svg></span>
  <div>
    <strong><?= View::t('cockpit.next_publish') ?>:</strong>
    <?php if ($cockpit['nextPublish'] !== null): ?>
    <?php $nextAt = (string) $cockpit['nextPublish']['run_after']; ?>
    <a href="/runs/<?= (int) $cockpit['nextPublish']['run_id'] ?>"><?= View::t('pipeline.content_n', ['n' => (int) $cockpit['nextPublish']['run_id']]) ?></a>
    <time datetime="<?= View::e($nextAt) ?>" data-countdown="<?= View::e($nextAt) ?>"
          data-t-imminent="<?= View::t('time.imminent') ?>"
          data-t-minutes="<?= View::t('time.in_minutes', ['n' => '{n}']) ?>"
          data-t-hours="<?= View::t('time.in_hours', ['n' => '{n}']) ?>"
          data-t-days="<?= View::t('time.in_days', ['n' => '{n}']) ?>"><?= View::e(Messages::until($nextAt)) ?></time>
    <?php else: ?>
    <?php
      // THREE empty states, not two. "approved videos publish straight away" is
      // true of a workspace with NO plan; saying it to one that has a plan
      // contradicts the summary line right underneath. And when the plan could
      // not be READ we know neither, so we claim neither.
      $planWeek = $cockpit['planWeek'] ?? null;
      $planUnreadable = is_array($planWeek) && ($planWeek['unavailable'] ?? false) === true;
      $planHasDays = is_array($planWeek) && !$planUnreadable && ($planWeek['planned'] ?? 0) > 0;
    ?>
    <span class="muted"><?= View::t(match (true) {
        $planUnreadable => 'cockpit.next_publish_unknown',
        $planHasDays => 'cockpit.next_publish_planned',
        default => 'cockpit.next_publish_none',
    }) ?></span>
    <?php endif; ?>
    <?php /* the empty state is the natural place to discover the weekly plan —
             otherwise it is only reachable by scrolling through Settings */ ?>
    <a class="next-publish__plan" href="/plan"><?= View::t('cockpit.open_plan') ?></a>
    <?php
      // the branch above only runs when nothing is queued; recompute so the
      // band is right on the other path too
      $planWeek = $cockpit['planWeek'] ?? null;
      $planUnreadable = is_array($planWeek) && ($planWeek['unavailable'] ?? false) === true;
    ?>
    <?php /* Phase 24 — one honest line about the week's plan. Shown only when
             there IS a plan; every number is counted, never filled in. */ ?>
    <?php if ($planUnreadable): ?>
    <?php /* said out loud rather than left as an absence: a missing band already
             means "nothing planned", and a failed read must not borrow that */ ?>
    <p class="next-publish__week next-publish__week--unreadable"><?= View::t('cockpit.plan_unreadable') ?></p>
    <?php elseif (is_array($planWeek) && ($planWeek['planned'] ?? 0) > 0): ?>
    <p class="next-publish__week muted"><?= View::t(
        $cockpit['planWeek']['published'] > 0 ? 'plan.summary_published' : 'plan.summary',
        [
            'planned' => (int) $cockpit['planWeek']['planned'],
            'published' => (int) $cockpit['planWeek']['published'],
            'awaiting' => (int) $cockpit['planWeek']['awaiting'],
            'missed' => (int) $cockpit['planWeek']['missed'],
        ],
    ) ?></p>
    <?php endif; ?>
  </div>
</div>

<?php if ($cockpit['pipeline'] !== null): ?>
<div class="card pipeline-card">
  <div class="card__head">
    <h2><?= View::t('pipeline.title') ?></h2>
    <span class="card__action"><span class="chip chip--neutral mono"><?= View::t('pipeline.content_n', ['n' => (int) $cockpit['pipeline']['run_id']]) ?></span></span>
  </div>
  <div class="card__body">
    <?php $pipeline = $cockpit['pipeline']; require __DIR__ . '/partials/pipeline.php'; ?>
  </div>
</div>
<?php endif; ?>

<div class="cockpit-grid">
  <div class="card card--primary">
    <div class="card__head">
      <h2><?= View::t('dash.awaiting_approval') ?></h2>
      <?php /* ONE definition of "waiting on you" on this page. This badge used
               to count the four cards the card was sliced down to, so a
               workspace with eight open gates read "4" here for ever, next to a
               KPI saying 7 and an ACTIVE RUNS list printing seven awaiting rows.
               It now prints the KPI's own number — runs awaiting your approval —
               and carries the same live hook, so the two can never drift apart
               again while the page is open. */ ?>
      <span class="card__action"><span class="chip chip--<?= (int) $biz['awaiting'] === 0 ? 'ok' : 'warn' ?> num" data-live-awaiting><?= (int) $biz['awaiting'] ?></span></span>
    </div>
    <div class="card__body">
      <?php if ($cockpit['awaiting'] === []): ?>
      <p class="muted"><?= View::t('dash.nothing_waiting') ?></p>
      <?php else: ?>
      <div class="appr-list">
        <?php foreach ($cockpit['awaiting'] as $job): ?>
        <?php $r = $job['result'] ?? []; $draftId = $r['draft_render_id'] ?? null; $libId = $r['library_asset_id'] ?? null; ?>
        <article class="appr-card">
          <?php if ($draftId !== null || $libId !== null): ?>
          <?php $src = $draftId !== null ? '/render/' . (int) $draftId : '/media/' . (int) $libId; ?>
          <div class="inline-player" data-inline-player>
            <?php /* poster on the VIDEO, not a sibling <img> — see the queue card:
                     the image sat underneath an absolutely-positioned video that
                     paints black until it plays */ ?>
            <?php $poster = $draftId !== null ? '/render/' . (int) $draftId . '/poster'
                  : (($job['has_poster'] ?? false) ? '/media/' . (int) $libId . '/poster' : null); ?>
            <video class="inline-player__video" src="<?= View::e($src) ?>"<?= $poster !== null ? ' poster="' . View::e($poster) . '"' : '' ?> preload="metadata" playsinline></video>
            <button type="button" class="inline-player__play" aria-label="<?= View::t('player.play') ?>">
              <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
            </button>
            <span class="inline-player__badge"><span class="inline-player__badge-dot"></span><?= View::t('player.playing') ?></span>
            <?php if (isset($r['estimated_duration_s'])): ?>
            <span class="inline-player__dur mono"><?= View::e(Format::duration((float) $r['estimated_duration_s'])) ?></span>
            <?php endif; ?>
            <span class="inline-player__progress"></span>
          </div>
          <?php else: ?>
          <div class="inline-player inline-player--pending">
              <span class="inline-player__ph-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" width="24" height="24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M10 9.5l5 2.5-5 2.5z"/></svg></span>
            <span class="inline-player__ph-type"><?= View::e(Messages::jobType((string) $job['type'])) ?></span>
            <span class="inline-player__ph-note"><?= View::t('player.preview_pending') ?></span>
          </div>
          <?php endif; ?>

          <div class="appr-card__body">
            <h3 class="appr-card__title"><?= View::e(Messages::jobType((string) $job['type'])) ?> · <?= View::t('common.run_n', ['n' => (int) $job['run_id']]) ?></h3>
            <div class="appr-card__meta">
              <span class="chip chip--warn"><span class="dot dot--warn"></span><?= View::t('status.awaiting_approval') ?></span>
              <?php if (($r['ai_label_required'] ?? false)): ?>
              <span class="chip chip--ai"><?= View::t('queue.ai_label_will_set') ?></span>
              <?php endif; ?>
              <?php
                // same rule as the queue card: once a person edited the words,
                // the chip describes THAT text, because that is what publishes.
                // The two surfaces read the same derived value so they cannot
                // disagree about the same post.
                $cBadge = $job['badge'] ?? null;
                $cEdited = is_array($cBadge);
                $cs = $cEdited ? (string) $cBadge['status'] : (string) ($r['compliance']['status'] ?? '');
                $cScore = $cEdited ? $cBadge['slop'] : ($r['compliance']['slop_score'] ?? null);
              ?>
              <?php if ($job['type'] === 'render_review' && $cs !== ''): ?>
              <?php $cSimilar = $cScore !== null && ($cEdited ? $cBadge['similar'] : true); ?>
              <?php if ($cs === 'pass' || $cs === 'pass_with_ai_label'): ?>
              <span class="chip chip--ok"><span class="dot dot--ok"></span><?= View::t($cEdited ? 'queue.compliance_pass_edited' : 'queue.compliance_pass') ?></span>
              <?php elseif ($cs === 'warn' && $cSimilar): ?>
              <span class="chip chip--warn chip--wrap"><span class="dot dot--warn"></span><?= View::t($cEdited ? 'queue.similarity_edited' : 'queue.similarity', ['score' => number_format((float) $cScore, 2)]) ?></span>
              <?php elseif ($cs === 'block'): ?>
              <span class="chip chip--err"><span class="dot dot--err"></span><?= View::t('queue.checks_blocked') ?></span>
              <?php else: ?>
              <?php /* warned about something else, or a status this screen does
                       not know — never leave the card with no chip at all */ ?>
              <span class="chip chip--warn chip--wrap"><span class="dot dot--warn"></span><?= View::t($cEdited ? 'queue.checks_note_edited' : 'queue.checks_note') ?></span>
              <?php endif; ?>
              <?php endif; ?>
            </div>
            <div class="appr-card__actions">
              <?php /* same rule as /queue: a render gate with nothing to watch
                       does not offer "Approve & publish". The record this button
                       writes carries the operator's name and says they approved
                       a video; over a "Preview pending" placeholder that record
                       would be indistinguishable from one where they had seen
                       it. Reject stays — refusing what you cannot see is safe. */ ?>
              <?php $noPreview = (string) ($job['type'] ?? '') === 'render_review'
                    && ($job['result']['draft_render_id'] ?? $job['result']['library_asset_id'] ?? null) === null; ?>
              <?php if ($noPreview): ?>
              <p class="field__hint appr-card__blocked"><?= View::t('queue.approve_needs_preview') ?></p>
              <?php else: ?>
              <form method="post" action="/queue/job/<?= (int) $job['id'] ?>/approve">
                <?= $csrfField ?>
                <button type="submit" class="btn btn--primary btn--sm"><?= $job['type'] === 'render_review' ? View::t('queue.approve_publish') : View::t('queue.approve') ?></button>
              </form>
              <?php endif; ?>
              <form method="post" action="/queue/job/<?= (int) $job['id'] ?>/reject" data-confirm="<?= View::t('queue.reject_confirm') ?>">
                <?= $csrfField ?>
                <button type="submit" class="btn btn--danger-ghost btn--sm"><?= View::t('queue.reject') ?></button>
              </form>
              <a class="btn btn--ghost btn--sm" href="/runs/<?= (int) $job['run_id'] ?>"><?= View::t('common.view_run') ?></a>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
      <?php /* the card is a window, and it now says so out loud instead of
               letting the badge imply the queue is only this deep */ ?>
      <?php $moreRuns = max(0, (int) $biz['awaiting'] - (int) ($cockpit['awaitingShownRuns'] ?? 0)); ?>
      <?php if ($moreRuns > 0): ?>
      <p class="note"><a href="/queue"><?= View::t('dash.awaiting_more', ['n' => $moreRuns]) ?></a></p>
      <?php endif; ?>
      <p class="note"><?= View::t('queue.approval_note') ?></p>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head">
      <h2><?= View::t('dash.accounts_title') ?></h2>
      <span class="card__action"><a class="btn btn--ghost btn--sm" href="/accounts"><?= View::t('nav.accounts') ?></a></span>
    </div>
    <div class="card__body">
      <?php /* Three states, not two. "No accounts connected yet" is a claim; a
               read that FAILED has not established it, and this card is where an
               operator checks whether their channels are wired up. */ ?>
      <?php if ($cockpit['accounts'] === null): ?>
      <div class="ui-state ui-state--unreadable">
        <span class="ui-state__icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2"><circle cx="8" cy="8" r="6.5"/><path d="M8 7.5v3M8 5h.01"/></svg></span>
        <h3><?= View::t('dash.accounts_unreadable') ?></h3>
        <?php /* the one thing an operator wants after a failed read is to try
                 again — not a second link to the page they can already see */ ?>
        <p><a href="/dashboard"><?= View::t('common.try_again') ?></a></p>
      </div>
      <?php elseif ($cockpit['accounts'] === []): ?>
      <div class="ui-state">
        <span class="ui-state__icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2"><circle cx="8" cy="5.2" r="2.6"/><path d="M3 13.5c0-2.6 2.2-4.3 5-4.3s5 1.7 5 4.3"/></svg></span>
        <h3><?= View::t('dash.accounts_none') ?></h3>
        <p><a href="/accounts"><?= View::t('dash.accounts_connect') ?></a></p>
      </div>
      <?php else: ?>
      <div class="acc-grid">
        <?php $samplePosters = $cockpit['samplePosters'] ?? []; ?>
        <?php foreach ($cockpit['accounts'] as $account): $manage = false; require __DIR__ . '/partials/account-card.php'; ?>
        <?php endforeach; ?>
      </div>
      <p class="acct-note"><span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" width="12" height="12"><circle cx="8" cy="8" r="6.5"/><path d="M8 7.5v3M8 5h.01"/></svg></span><?= View::t('acct.sample_note') ?></p>
      <?php endif; ?>
    </div>
  </div>
</div>

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
          <span class="job-row__entity"><?= $run['current_node'] !== null ? View::t('dash.at') . ' ' . View::e(Messages::node((string) $run['current_node'])) : '' ?></span>
        </div>
        <span class="chip chip--<?= \Kuyash\Core\Format::statusTone((string) $run['status']) ?>"><span class="dot dot--<?= \Kuyash\Core\Format::statusTone((string) $run['status']) ?>"></span><?= View::e(\Kuyash\Core\Messages::status((string) $run['status'])) ?></span>
        <a class="btn btn--ghost btn--sm" href="/runs/<?= (int) $run['id'] ?>"><?= View::t('dash.timeline') ?></a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
