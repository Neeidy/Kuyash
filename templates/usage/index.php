<?php

declare(strict_types=1);

use Kuyash\Core\Format;
use Kuyash\Core\View;

/** @var string $monthLabel        UTC YYYY-MM */
/** @var int|null $capCents         monthly budget cap, null = no cap */
/** @var int $spentCents            month-to-date spend */
/** @var int|null $remainingCents   max(0, cap - spent), null = no cap */
/** @var int|null $pct              spent/cap %, null = no cap (may exceed 100) */
/** @var list<array{key:string,label:string,cents:int}> $breakdown */
/** @var array{key:string,label:string,cents:int}|null $biggest */
/** @var int $eventCount            recorded usage_events this month */
/** @var list<array<string,mixed>> $charges */
/** @var array<string,string> $categoryLabels */
/** @var int $balanceCents */
/** @var array{granted:int,spent:int,adjusted:int} $creditTotals */
/** @var list<array<string,mixed>> $ledger */

// banner tone: at/over cap → err ("runs blocked"); ≥90 → err; ≥75 → warn
$bannerTone = null;
if ($capCents !== null && $pct !== null) {
    if ($pct >= 100) {
        $bannerTone = 'over';
    } elseif ($pct >= 90) {
        $bannerTone = 'err';
    } elseif ($pct >= 75) {
        $bannerTone = 'warn';
    }
}
$fillPct = $pct === null ? 0 : max(0, min(100, $pct));
$fillTone = $pct === null ? 'ok' : ($pct >= 90 ? 'err' : ($pct >= 75 ? 'warn' : 'ok'));
// plain labels for the credit-ledger transaction type enum (grant/spend/adjust)
$ledgerLabels = [
    'grant' => View::t('ledger.type_grant'),
    'spend' => View::t('ledger.type_spend'),
    'adjust' => View::t('ledger.type_adjust'),
];
$catMax = 0;
foreach ($breakdown as $b) {
    $catMax = max($catMax, $b['cents']);
}
?>
<div class="screen-head">
  <div>
    <h1><?= View::t('usage.title') ?></h1>
    <p class="screen-sub"><?= View::t('usage.subtitle_1') ?> <span class="mono"><?= View::e($monthLabel) ?></span> <?= View::t('usage.subtitle_2') ?> <a href="/settings"><?= View::t('nav.settings') ?></a><?= View::t('usage.subtitle_3') ?></p>
  </div>
</div>

<?php if ($bannerTone === 'over'): ?>
<div class="callout callout--err callout--banner" role="alert">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6.5"/><path d="M8 5v3.5M8 11h.01"/></svg></span>
  <div><strong><?= View::t('usage.over_title') ?></strong> <?= View::t('usage.over_body', ['spent' => Format::cents($spentCents), 'cap' => Format::cents($capCents), 'pct' => (int) $pct]) ?> <a href="/settings"><?= View::t('nav.settings') ?></a><?= View::t('usage.over_continue') ?></div>
</div>
<?php elseif ($bannerTone === 'err' || $bannerTone === 'warn'): ?>
<div class="callout callout--<?= $bannerTone ?> callout--banner" role="<?= $bannerTone === 'err' ? 'alert' : 'status' ?>">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2L1.5 13.5h13z"/><path d="M8 6.5V10M8 12h.01"/></svg></span>
  <div><strong><?= View::t('usage.pct_used_title', ['pct' => (int) $pct]) ?></strong>
    <?= View::t('usage.pct_used_body', ['remaining' => Format::cents($remainingCents), 'cap' => Format::cents($capCents)]) ?></div>
</div>
<?php endif; ?>

<div class="kpi-strip">
  <div class="kpi"><span class="kpi__num num"><?= View::e(Format::cents($spentCents)) ?></span><span class="kpi__label"><?= View::t('usage.kpi_spent') ?></span></div>
  <div class="kpi"><span class="kpi__num num"><?= $capCents === null ? View::t('settings.no_cap') : View::e(Format::cents($capCents)) ?></span><span class="kpi__label"><?= View::t('usage.kpi_cap') ?></span></div>
  <div class="kpi"><span class="kpi__num num"><?= $remainingCents === null ? '—' : View::e(Format::cents($remainingCents)) ?></span><span class="kpi__label"><?= View::t('usage.kpi_remaining') ?></span></div>
  <div class="kpi"><span class="kpi__num num"><?= $biggest === null ? '—' : View::e(Format::cents($biggest['cents'])) ?></span><span class="kpi__label"><?= $biggest === null ? View::t('usage.kpi_biggest_empty') : View::e($biggest['label']) . ' ' . View::t('usage.biggest_suffix') ?></span></div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('usage.budget') ?></h2>
    <span class="card__action">
      <?php if ($capCents === null): ?>
      <span class="chip chip--faint"><?= View::t('usage.no_cap_set') ?></span>
      <?php else: ?>
      <span class="chip chip--<?= $fillTone ?> num"><?= (int) $pct ?>%</span>
      <?php endif; ?>
    </span>
  </div>
  <div class="card__body">
    <?php if ($capCents === null): ?>
    <p class="muted"><?= View::t('usage.no_cap_body_1') ?> <a href="/settings"><?= View::t('nav.settings') ?></a><?= View::t('usage.no_cap_body_2') ?></p>
    <?php else: ?>
    <div class="budget-bar" role="progressbar"
         aria-valuenow="<?= $fillPct ?>" aria-valuemin="0" aria-valuemax="100"
         aria-label="<?= View::t('usage.bar_aria', ['spent' => Format::cents($spentCents), 'cap' => Format::cents($capCents)]) ?>">
      <div class="budget-bar__track">
        <div class="budget-bar__fill budget-bar__fill--<?= $fillTone ?>" style="width: <?= $fillPct ?>%"></div>
      </div>
      <div class="budget-bar__legend mono">
        <span><?= View::e(Format::cents($spentCents)) ?> <?= View::t('usage.spent') ?></span>
        <span><?= View::e(Format::cents($capCents)) ?> <?= View::t('usage.cap_word') ?></span>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('usage.by_category') ?></h2>
    <span class="card__action"><span class="chip chip--faint num"><?= View::e(Format::cents($spentCents)) ?></span></span>
  </div>
  <div class="card__body">
    <?php if ($spentCents === 0): ?>
    <p class="muted"><?= View::t('usage.no_spend') ?></p>
    <?php else: ?>
    <div class="breakdown">
      <?php foreach ($breakdown as $row): ?>
      <div class="breakdown__row">
        <span class="breakdown__label"><?= View::e($row['label']) ?></span>
        <span class="breakdown__bar">
          <span class="breakdown__fill" style="width: <?= $catMax > 0 ? (int) round(($row['cents'] / $catMax) * 100) : 0 ?>%"></span>
        </span>
        <span class="breakdown__val mono num"><?= View::e(Format::cents($row['cents'])) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('usage.recent_charges') ?></h2>
    <span class="card__action"><span class="chip chip--faint num"><?= count($charges) ?></span></span>
  </div>
  <div class="card__body">
    <?php if ($charges === []): ?>
    <div class="ui-state">
      <span class="ui-state__icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M8 1.5v13M11.2 4.2H6.4a1.9 1.9 0 000 3.8h3.2a1.9 1.9 0 010 3.8H4.8"/></svg></span>
      <h3><?= View::t('usage.no_charges') ?></h3>
      <p><?= View::t('usage.no_charges_hint') ?></p>
    </div>
    <?php else: ?>
    <ul class="job-list">
      <?php foreach ($charges as $c): ?>
      <li class="job-row">
        <div class="job-row__main">
          <span class="job-row__type"><?= View::e($categoryLabels[(string) $c['category']] ?? (string) $c['category']) ?>
            <?php if (($c['run_id'] ?? null) !== null): ?><span class="muted">· <?= View::t('common.run_n', ['n' => (int) $c['run_id']]) ?></span><?php endif; ?></span>
          <?php /* the DATE matters: these rows sat under a "spent this month"
                   heading showing $0.00, and a bare time reads as today */ ?>
          <span class="job-row__entity mono"><?= View::e((string) $c['provider']) ?><?php if (($c['unit_type'] ?? null) !== null): ?> · <?= View::e((string) $c['unit_type']) ?><?php endif; ?> · <?= View::e(substr((string) $c['created_at'], 0, 10) . ' ' . Format::utcTime((string) $c['created_at'])) ?></span>
        </div>
        <span class="chip chip--neutral mono num"><?= View::e(Format::cents((int) $c['cost_cents'])) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <p class="note">
      <?php if ($eventCount > count($charges)): ?><?= View::t('usage.showing_latest', ['n' => count($charges), 'total' => (int) $eventCount]) ?> <?php endif; ?>
      <?= View::t('usage.charges_note') ?></p>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('usage.credit_balance') ?></h2>
    <span class="card__action"><span class="chip chip--<?= $balanceCents < 0 ? 'warn' : ($balanceCents === 0 ? 'neutral' : 'ok') ?> num"><?= View::e(Format::cents($balanceCents)) ?></span></span>
  </div>
  <div class="card__body">
    <p class="muted"><?= View::t('usage.credit_body_1') ?></p>
    <div class="chip-row">
      <span class="chip chip--faint"><?= View::t('usage.granted') ?> <span class="mono num"><?= View::e(Format::cents($creditTotals['granted'])) ?></span></span>
      <span class="chip chip--faint"><?= View::t('usage.spent') ?> <span class="mono num"><?= View::e(Format::cents($creditTotals['spent'])) ?></span></span>
      <?php if ($creditTotals['adjusted'] !== 0): ?>
      <span class="chip chip--faint"><?= View::t('usage.adjusted') ?> <span class="mono num"><?= View::e(Format::cents($creditTotals['adjusted'])) ?></span></span>
      <?php endif; ?>
    </div>
    <?php if ($ledger !== []): ?>
    <ul class="job-list" style="margin-top: var(--s3)">
      <?php foreach ($ledger as $tx): ?>
      <li class="job-row">
        <div class="job-row__main">
          <span class="job-row__type"><?= $ledgerLabels[(string) $tx['type']] ?? View::e((string) $tx['type']) ?><?php if (($tx['reason'] ?? null) !== null && (string) $tx['reason'] !== ''): ?> <span class="muted">· <?= View::e((string) $tx['reason']) ?></span><?php endif; ?></span>
          <span class="job-row__entity mono"><?= View::e(substr((string) $tx['created_at'], 0, 10) . ' ' . Format::utcTime((string) $tx['created_at'])) ?></span>
        </div>
        <span class="chip chip--<?= (int) $tx['amount_cents'] < 0 ? 'neutral' : 'ok' ?> mono num"><?= View::e(Format::cents((int) $tx['amount_cents'])) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
