<?php

declare(strict_types=1);

use Kuyash\Core\Format;
use Kuyash\Core\Messages;
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
$catMax = 0;
foreach ($breakdown as $b) {
    $catMax = max($catMax, $b['cents']);
}
?>
<div class="screen-head">
  <div>
    <h1>Usage &amp; costs</h1>
    <p class="screen-sub">Month-to-date spend for <span class="mono"><?= View::e($monthLabel) ?></span> (UTC),
      against this workspace's budget cap. Set the cap in <a href="/settings">Settings</a>;
      add budget with <span class="mono">bin/grant-credits.php</span>.</p>
  </div>
</div>

<?php if ($bannerTone === 'over'): ?>
<div class="callout callout--err callout--banner" role="alert">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6.5"/><path d="M8 5v3.5M8 11h.01"/></svg></span>
  <div><strong>Budget cap reached</strong> — <?= View::e(Format::cents($spentCents)) ?> of <?= View::e(Format::cents($capCents)) ?> used (<?= (int) $pct ?>%).
    New runs whose estimate exceeds the remaining budget are blocked before they start.
    Raise the cap in <a href="/settings">Settings</a> to continue.</div>
</div>
<?php elseif ($bannerTone === 'err' || $bannerTone === 'warn'): ?>
<div class="callout callout--<?= $bannerTone ?> callout--banner" role="<?= $bannerTone === 'err' ? 'alert' : 'status' ?>">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2L1.5 13.5h13z"/><path d="M8 6.5V10M8 12h.01"/></svg></span>
  <div><strong><?= (int) $pct ?>% of the monthly budget used.</strong>
    <?= Format::cents($remainingCents) ?> remaining of <?= Format::cents($capCents) ?>.
    Runs are blocked once an estimate would exceed what's left.</div>
</div>
<?php endif; ?>

<div class="kpi-strip">
  <div class="kpi"><span class="kpi__num num"><?= View::e(Format::cents($spentCents)) ?></span><span class="kpi__label">spent this month</span></div>
  <div class="kpi"><span class="kpi__num num"><?= $capCents === null ? 'no cap' : View::e(Format::cents($capCents)) ?></span><span class="kpi__label">budget cap</span></div>
  <div class="kpi"><span class="kpi__num num"><?= $remainingCents === null ? '—' : View::e(Format::cents($remainingCents)) ?></span><span class="kpi__label">remaining</span></div>
  <div class="kpi"><span class="kpi__num num"><?= $biggest === null ? '—' : View::e(Format::cents($biggest['cents'])) ?></span><span class="kpi__label"><?= $biggest === null ? 'biggest category' : View::e($biggest['label']) . ' (biggest)' ?></span></div>
</div>

<div class="card">
  <div class="card__head"><h2>Budget</h2>
    <span class="card__action">
      <?php if ($capCents === null): ?>
      <span class="chip chip--faint">no cap set</span>
      <?php else: ?>
      <span class="chip chip--<?= $fillTone ?> num"><?= (int) $pct ?>%</span>
      <?php endif; ?>
    </span>
  </div>
  <div class="card__body">
    <?php if ($capCents === null): ?>
    <p class="muted">No monthly budget cap is set, so runs are never blocked on cost.
      Set a cap in <a href="/settings">Settings</a> to enable the pre-flight budget gate.</p>
    <?php else: ?>
    <div class="budget-bar" role="progressbar"
         aria-valuenow="<?= $fillPct ?>" aria-valuemin="0" aria-valuemax="100"
         aria-label="<?= View::e(Format::cents($spentCents)) ?> of <?= View::e(Format::cents($capCents)) ?> used">
      <div class="budget-bar__track">
        <div class="budget-bar__fill budget-bar__fill--<?= $fillTone ?>" style="width: <?= $fillPct ?>%"></div>
      </div>
      <div class="budget-bar__legend mono">
        <span><?= View::e(Format::cents($spentCents)) ?> spent</span>
        <span><?= View::e(Format::cents($capCents)) ?> cap</span>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>By category</h2>
    <span class="card__action"><span class="chip chip--faint num"><?= View::e(Format::cents($spentCents)) ?></span></span>
  </div>
  <div class="card__body">
    <?php if ($spentCents === 0): ?>
    <p class="muted">No spend recorded this month. Mock providers and cache hits are free —
      only real provider calls (e.g. live OpenAI text or TTS) record a charge.</p>
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
  <div class="card__head"><h2>Recent charges</h2>
    <span class="card__action"><span class="chip chip--faint num"><?= count($charges) ?></span></span>
  </div>
  <div class="card__body">
    <?php if ($charges === []): ?>
    <div class="ui-state">
      <span class="ui-state__icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M8 1.5v13M11.2 4.2H6.4a1.9 1.9 0 000 3.8h3.2a1.9 1.9 0 010 3.8H4.8"/></svg></span>
      <h3>No charges yet</h3>
      <p>Every real provider call records one truthful charge here. Mocks and cache reuses never do.</p>
    </div>
    <?php else: ?>
    <ul class="job-list">
      <?php foreach ($charges as $c): ?>
      <li class="job-row">
        <div class="job-row__main">
          <span class="job-row__type"><?= View::e($categoryLabels[(string) $c['category']] ?? (string) $c['category']) ?>
            <?php if (($c['run_id'] ?? null) !== null): ?><span class="muted">· run #<?= (int) $c['run_id'] ?></span><?php endif; ?></span>
          <span class="job-row__entity mono"><?= View::e((string) $c['provider']) ?><?php if (($c['unit_type'] ?? null) !== null): ?> · <?= View::e((string) $c['unit_type']) ?><?php endif; ?> · <?= View::e(Format::utcTime((string) $c['created_at'])) ?></span>
        </div>
        <span class="chip chip--neutral mono num"><?= View::e(Format::cents((int) $c['cost_cents'])) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <p class="note">
      <?php if ($eventCount > count($charges)): ?>Showing the latest <?= count($charges) ?> of <?= (int) $eventCount ?> charges this month. <?php endif; ?>
      Charges are recorded only for real spend — mock providers and cache hits report no cost.</p>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Credit balance</h2>
    <span class="card__action"><span class="chip chip--<?= $balanceCents < 0 ? 'warn' : ($balanceCents === 0 ? 'neutral' : 'ok') ?> num"><?= View::e(Format::cents($balanceCents)) ?></span></span>
  </div>
  <div class="card__body">
    <p class="muted">Credits are a friendly view over real cents: granted minus spent.
      There is no prepaid pool — grants are manual (<span class="mono">bin/grant-credits.php</span>),
      and the enforced control is the monthly budget cap above.</p>
    <div class="chip-row">
      <span class="chip chip--faint">granted <span class="mono num"><?= View::e(Format::cents($creditTotals['granted'])) ?></span></span>
      <span class="chip chip--faint">spent <span class="mono num"><?= View::e(Format::cents($creditTotals['spent'])) ?></span></span>
      <?php if ($creditTotals['adjusted'] !== 0): ?>
      <span class="chip chip--faint">adjusted <span class="mono num"><?= View::e(Format::cents($creditTotals['adjusted'])) ?></span></span>
      <?php endif; ?>
    </div>
    <?php if ($ledger !== []): ?>
    <ul class="job-list" style="margin-top: var(--s3)">
      <?php foreach ($ledger as $tx): ?>
      <li class="job-row">
        <div class="job-row__main">
          <span class="job-row__type"><?= View::e((string) $tx['type']) ?><?php if (($tx['reason'] ?? null) !== null && (string) $tx['reason'] !== ''): ?> <span class="muted">· <?= View::e((string) $tx['reason']) ?></span><?php endif; ?></span>
          <span class="job-row__entity mono"><?= View::e(Format::utcTime((string) $tx['created_at'])) ?></span>
        </div>
        <span class="chip chip--<?= (int) $tx['amount_cents'] < 0 ? 'neutral' : 'ok' ?> mono num"><?= View::e(Format::cents((int) $tx['amount_cents'])) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
