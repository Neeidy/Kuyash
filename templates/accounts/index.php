<?php

declare(strict_types=1);

use Kuyash\Core\Format;
use Kuyash\Core\Messages;
use Kuyash\Core\View;

/** @var list<array<string, mixed>> $accounts each with published_today + daily_cap + reference_title */
/** @var list<string> $platforms */
/** @var list<array<string, mixed>> $references ready assets usable as a reference subject */
/** @var array{run_id: int, run_after: string}|null $nextScheduled */
/** @var string $csrfField trusted generated HTML */
?>
<div class="screen-head">
  <div>
    <h1><?= View::t('accounts.title') ?></h1>
    <p class="screen-sub"><?= View::t('accounts.subtitle') ?></p>
  </div>
  <div class="screen-head__actions">
    <span class="chip chip--neutral"><?= View::t('accounts.publishing_mock') ?></span>
    <form method="post" action="/accounts/sync">
      <?= $csrfField ?>
      <button type="submit" class="btn btn--ghost btn--sm"><?= View::t('account.sync') ?></button>
    </form>
  </div>
</div>

<div class="callout callout--banner callout--ok" role="status">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6.5"/><path d="M8 5v3.5M8 11h.01"/></svg></span>
  <div><strong><?= View::t('accounts.next_scheduled') ?></strong>
    <?php if ($nextScheduled === null && ($hasPlan ?? false) === true): ?>
    <?php /* "publishes immediately" is false wherever a publishing time exists.
             Nothing is queued right now, but that is not the same claim. */ ?>
    <?= View::t('accounts.nothing_scheduled_planned') ?>
    <?php elseif ($nextScheduled === null): ?>
    <?= View::t('accounts.nothing_scheduled') ?>
    <?php else: ?>
    <?= View::t('accounts.run_at', ['run' => (int) $nextScheduled['run_id']]) ?>
    <span class="mono"><?= View::e(substr((string) $nextScheduled['run_after'], 0, 10)) ?>
      <?= View::e(Format::utcTime((string) $nextScheduled['run_after'])) ?> UTC</span>.
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('accounts.connect_account') ?></h2></div>
  <div class="card__body">
    <p class="muted"><?= View::t('accounts.connect_desc') ?></p>
    <div class="tag-row">
      <?php foreach ($platforms as $platform): ?>
      <a class="btn btn--ghost btn--sm" href="/accounts/connect/<?= View::e($platform) ?>"><?= View::t('accounts.connect', ['platform' => Messages::platform($platform)]) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('accounts.your_accounts') ?></h2>
    <span class="card__action"><span class="chip chip--faint num"><?= count($accounts) ?></span></span>
  </div>
  <div class="card__body">
    <?php if ($accounts === []): ?>
    <div class="ui-state">
      <span class="ui-state__icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2"><circle cx="8" cy="5" r="2.5"/><path d="M3 13c0-2.5 2.2-4 5-4s5 1.5 5 4"/></svg></span>
      <h3><?= View::t('accounts.none') ?></h3>
      <p><?= View::t('accounts.none_hint') ?></p>
    </div>
    <?php else: ?>
    <div class="acc-grid">
      <?php $samplePosters = $samplePosters ?? []; ?>
      <?php foreach ($accounts as $account): $manage = true; require __DIR__ . '/../partials/account-card.php'; ?>
      <?php endforeach; ?>
    </div>
    <p class="acct-note"><span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" width="12" height="12"><circle cx="8" cy="8" r="6.5"/><path d="M8 7.5v3M8 5h.01"/></svg></span><?= View::t('acct.sample_note') ?></p>
    <p class="note"><?= View::t('accounts.caps_note') ?></p>
    <?php endif; ?>
  </div>
</div>
