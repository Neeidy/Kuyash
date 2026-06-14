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
 *   business: array{balance_cents:int, spent_mtd_cents:int, charges_mtd:int, granted_week_cents:int, cost_per_content_cents:int|null, awaiting:int},
 *   activeRuns: list<array<string,mixed>>,
 *   awaiting: list<array<string,mixed>>,
 *   accounts: list<array<string,mixed>>
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
    <span class="kpi__label"><?= View::t('dash.kpi_balance') ?></span>
    <span class="kpi__num num mono" data-count="<?= number_format($biz['balance_cents'] / 100, 2, '.', '') ?>" data-count-prefix="$" data-count-decimals="2"><?= View::e(Format::cents($biz['balance_cents'])) ?></span>
    <?php if ($biz['granted_week_cents'] > 0): ?>
    <span class="kpi__delta kpi__delta--up"><?= View::t('dash.added_week', ['amount' => Format::cents($biz['granted_week_cents'])]) ?></span>
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
      <span class="card__action"><span class="chip chip--<?= $cockpit['awaiting'] === [] ? 'ok' : 'warn' ?> num"><?= count($cockpit['awaiting']) ?></span></span>
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
            <?php if ($draftId !== null): ?>
            <img class="inline-player__poster" src="/render/<?= (int) $draftId ?>/poster" alt="" loading="lazy">
            <?php endif; ?>
            <video class="inline-player__video" src="<?= $src ?>" preload="none" playsinline></video>
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
              <?php if ($job['type'] === 'render_review' && isset($r['compliance']['status'])): ?>
              <?php $cs = (string) $r['compliance']['status']; ?>
              <?php if ($cs === 'pass'): ?>
              <span class="chip chip--ok"><span class="dot dot--ok"></span><?= View::t('queue.compliance_pass') ?></span>
              <?php elseif ($cs === 'warn'): ?>
              <span class="chip chip--warn chip--wrap"><span class="dot dot--warn"></span><?= View::t('queue.slop_label') ?> <?= View::e(number_format((float) ($r['compliance']['slop_score'] ?? 0), 2)) ?></span>
              <?php endif; ?>
              <?php endif; ?>
            </div>
            <div class="appr-card__actions">
              <form method="post" action="/queue/job/<?= (int) $job['id'] ?>/approve">
                <?= $csrfField ?>
                <button type="submit" class="btn btn--primary btn--sm"><?= $job['type'] === 'render_review' ? View::t('queue.approve_publish') : View::t('queue.approve') ?></button>
              </form>
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
      <?php if ($cockpit['accounts'] === []): ?>
      <div class="ui-state">
        <span class="ui-state__icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2"><circle cx="8" cy="5.2" r="2.6"/><path d="M3 13.5c0-2.6 2.2-4.3 5-4.3s5 1.7 5 4.3"/></svg></span>
        <h3><?= View::t('dash.accounts_none') ?></h3>
        <p><a href="/accounts"><?= View::t('dash.accounts_connect') ?></a></p>
      </div>
      <?php else: ?>
      <div class="acc-grid">
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
          <span class="job-row__entity mono"><?= $run['current_node'] !== null ? View::t('dash.at') . ' ' . View::e((string) $run['current_node']) : '' ?></span>
        </div>
        <span class="chip chip--<?= \Kuyash\Core\Format::statusTone((string) $run['status']) ?>"><span class="dot dot--<?= \Kuyash\Core\Format::statusTone((string) $run['status']) ?>"></span><?= View::e(\Kuyash\Core\Messages::status((string) $run['status'])) ?></span>
        <a class="btn btn--ghost btn--sm" href="/runs/<?= (int) $run['id'] ?>"><?= View::t('dash.timeline') ?></a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
