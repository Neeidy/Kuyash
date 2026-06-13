<?php

declare(strict_types=1);

use Kuyash\Core\Format;
use Kuyash\Core\View;

/** @var list<array<string, mixed>> $accounts each with published_today + daily_cap + reference_title */
/** @var list<string> $platforms */
/** @var list<array<string, mixed>> $references ready assets usable as a reference subject */
/** @var array{run_id: int, run_after: string}|null $nextScheduled */
/** @var string $csrfField trusted generated HTML */

$statusTone = static fn (string $s): string => match ($s) {
    'connected' => 'ok',
    'reauth_needed' => 'err',
    default => 'neutral',
};
$healthTone = static fn (string $h): string => match ($h) {
    'ok' => 'ok',
    'degraded' => 'warn',
    default => 'neutral',
};
?>
<div class="screen-head">
  <div>
    <h1>Accounts</h1>
    <p class="screen-sub">Connected publishing targets (via Zernio). Kuyash stores an account
      reference and health only — never your platform password or tokens.</p>
  </div>
  <div class="screen-head__actions">
    <span class="chip chip--faint mono">publishing: mock</span>
  </div>
</div>

<div class="callout callout--banner callout--ok" role="status">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6.5"/><path d="M8 5v3.5M8 11h.01"/></svg></span>
  <div><strong>Next scheduled publish:</strong>
    <?php if ($nextScheduled === null): ?>
    nothing scheduled — approved renders publish immediately.
    <?php else: ?>
    run #<?= (int) $nextScheduled['run_id'] ?> at
    <span class="mono"><?= View::e(substr((string) $nextScheduled['run_after'], 0, 10)) ?>
      <?= View::e(Format::utcTime((string) $nextScheduled['run_after'])) ?> UTC</span>.
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Connect an account</h2></div>
  <div class="card__body">
    <p class="muted">Opens a mock Zernio authorization screen. No real platform call is made
      (publishing is doc-gated) — this records a reference so the pipeline can target the account.</p>
    <div class="tag-row">
      <?php foreach ($platforms as $platform): ?>
      <a class="btn btn--ghost btn--sm" href="/accounts/connect/<?= View::e($platform) ?>">Connect <?= View::e($platform) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Your accounts</h2>
    <span class="card__action"><span class="chip chip--faint num"><?= count($accounts) ?></span></span>
  </div>
  <div class="card__body">
    <?php if ($accounts === []): ?>
    <div class="ui-state">
      <span class="ui-state__icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2"><circle cx="8" cy="5" r="2.5"/><path d="M3 13c0-2.5 2.2-4 5-4s5 1.5 5 4"/></svg></span>
      <h3>No accounts connected</h3>
      <p>Connect a platform above so approved renders have somewhere to publish.</p>
    </div>
    <?php else: ?>
    <ul class="job-list">
      <?php foreach ($accounts as $account): ?>
      <li class="job-row job-row--stack">
        <div class="job-row__main">
          <span class="job-row__type mono"><?= View::e((string) $account['handle']) ?>
            <span class="muted">· <?= View::e((string) $account['platform']) ?></span></span>
          <span class="job-row__entity">
            published today: <?= (int) $account['published_today'] ?>/<?= (int) $account['daily_cap'] ?>
            <?php if (($account['reference_title'] ?? null) !== null): ?>
            · reference: <?= View::e((string) $account['reference_title']) ?>
            <?php endif; ?>
          </span>
          <form method="post" action="/accounts/<?= (int) $account['id'] ?>/reference" class="account-ref-form">
            <?= $csrfField ?>
            <label class="field field--inline">
              <span class="field__label">Default reference</span>
              <select name="asset_id">
                <option value="">— none —</option>
                <?php foreach ($references as $ref): ?>
                <option value="<?= (int) $ref['id'] ?>"<?= (int) ($account['default_reference_asset_id'] ?? 0) === (int) $ref['id'] ? ' selected' : '' ?>><?= View::e((string) $ref['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <button type="submit" class="btn btn--ghost btn--sm">Save</button>
          </form>
        </div>
        <div class="job-row__side">
          <div class="job-row__chips">
            <span class="chip chip--<?= $statusTone((string) $account['status']) ?>"><span class="dot dot--<?= $statusTone((string) $account['status']) ?>"></span><?= View::e((string) $account['status']) ?></span>
            <span class="chip chip--<?= $healthTone((string) $account['health']) ?>"><span class="dot dot--<?= $healthTone((string) $account['health']) ?>"></span>health: <?= View::e((string) $account['health']) ?></span>
          </div>
          <?php if ((string) $account['status'] !== 'disconnected'): ?>
          <form method="post" action="/accounts/<?= (int) $account['id'] ?>/disconnect"
                data-confirm="Disconnect <?= View::e((string) $account['handle']) ?>? It will stop receiving published renders.">
            <?= $csrfField ?>
            <button type="submit" class="btn btn--danger-ghost btn--sm">Disconnect</button>
          </form>
          <?php endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <p class="note">Per-account daily caps apply to auto-approved publishes only; manual publishes
      are your call. AI labels are set automatically per platform when compliance requires them.</p>
    <?php endif; ?>
  </div>
</div>
