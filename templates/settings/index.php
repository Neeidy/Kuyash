<?php

declare(strict_types=1);

use Kuyash\Core\View;

/** @var array{approval_mode: string, kill_switch: bool, daily_post_cap: int, budget_cap_cents: ?int} $settings */
/** @var array{score: int, sample: int, slop_avg: float, block_rate: float, reject_fail_rate: float, breach: bool} $quality */
/** @var string $policyVersion */
/** @var int $autoUsedToday */
/** @var int $spentThisMonthCents */
/** @var string $csrfField trusted generated HTML */

$isAuto = $settings['approval_mode'] === 'auto';
$killOn = $settings['kill_switch'];
?>
<div class="screen-head">
  <div>
    <h1>Settings</h1>
    <p class="screen-sub">Approval mode and autonomy guardrails. Manual is the default —
      Auto lets the compliance agent approve clean renders, within the caps below.</p>
  </div>
  <div class="screen-head__actions">
    <span class="chip chip--<?= $isAuto ? 'warn' : 'ok' ?>"><span class="dot"></span>mode: <?= View::e($settings['approval_mode']) ?></span>
    <span class="chip chip--faint mono">policy <?= View::e($policyVersion) ?></span>
  </div>
</div>

<?php if ($killOn): ?>
<div class="callout callout--err callout--banner" role="alert">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2L1.5 13.5h13z"/><path d="M8 6.5V10M8 12h.01"/></svg></span>
  <div><strong>Kill switch is ON.</strong> Auto-approvals are stopped and queued auto-approved
    publishes are held. Manual approvals are unaffected.</div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><h2>Kill switch</h2>
    <span class="card__action"><span class="chip chip--<?= $killOn ? 'err' : 'ok' ?>"><span class="dot"></span><?= $killOn ? 'ON — autonomy stopped' : 'off' ?></span></span>
  </div>
  <div class="card__body">
    <div class="killswitch-row">
      <p class="muted">Instantly stops all auto-approvals and holds queued auto-approved publishes.
        Never touches manual decisions. Flips are audited.</p>
      <?php /* data-confirm goes on the FORM — the global handler matches form[data-confirm] (app.js) */ ?>
      <form method="post" action="/settings/kill-switch"<?= $killOn ? '' : ' data-confirm="Stop all autonomy now? Queued auto-approved publishes will hold until you turn it back off."' ?>>
        <?= $csrfField ?>
        <input type="hidden" name="state" value="<?= $killOn ? 'off' : 'on' ?>">
        <?php if ($killOn): ?>
        <button type="submit" class="btn btn--primary">Turn kill switch OFF</button>
        <?php else: ?>
        <button type="submit" class="btn btn--danger">Turn kill switch ON</button>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Approval mode &amp; guardrails</h2></div>
  <div class="card__body">
    <form method="post" action="/settings" class="settings-form">
      <?= $csrfField ?>

      <fieldset class="mode-pick">
        <legend class="field__label">Approval mode</legend>
        <label class="mode-option<?= $isAuto ? '' : ' is-picked' ?>">
          <input type="radio" name="approval_mode" value="manual"<?= $isAuto ? '' : ' checked' ?>>
          <span><strong>Manual</strong> (default) — every render waits for your decision.
            Records say “Approved by you”.</span>
        </label>
        <label class="mode-option<?= $isAuto ? ' is-picked' : '' ?>">
          <input type="radio" name="approval_mode" value="auto"<?= $isAuto ? ' checked' : '' ?>>
          <span><strong>Auto</strong> — the compliance agent approves renders whose checks are
            clean (pass, or pass with the AI label set automatically). Anything warned or blocked
            still comes to you. Records say “Auto-approved by compliance agent (policy <?= View::e($policyVersion) ?>)” —
            never “approved by you”.</span>
        </label>
      </fieldset>

      <div class="settings-grid">
        <label class="field">
          <span class="field__label">Daily post cap (auto)</span>
          <input type="text" inputmode="numeric" name="daily_post_cap" value="<?= (int) $settings['daily_post_cap'] ?>">
          <span class="field__hint num">auto slots used today: <?= (int) $autoUsedToday ?>/<?= (int) $settings['daily_post_cap'] ?></span>
        </label>
        <label class="field">
          <span class="field__label">Monthly budget cap (USD, empty = none)</span>
          <input type="text" inputmode="numeric" name="budget_cap_usd"
                 value="<?= $settings['budget_cap_cents'] !== null ? (int) ($settings['budget_cap_cents'] / 100) : '' ?>" placeholder="no cap">
          <span class="field__hint num">observed spend this month: $<?= View::e(number_format($spentThisMonthCents / 100, 2)) ?></span>
        </label>
      </div>

      <button type="submit" class="btn btn--primary">Save settings</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Quality score</h2>
    <span class="card__action"><span class="chip chip--faint mono">policy <?= View::e($policyVersion) ?></span></span>
  </div>
  <div class="card__body">
    <?php $hasSample = (int) $quality['sample'] >= 5; ?>
    <div class="quality-row">
      <span class="quality-score num<?= $quality['breach'] ? ' quality-score--bad' : '' ?>"><?= $hasSample ? (int) $quality['score'] : '—' ?></span>
      <div class="quality-meta">
        <?php if (!$hasSample): ?>
        <p class="muted">Not enough checks yet — the score appears once at least 5 compliance checks have run
          (so far: <?= (int) $quality['sample'] ?>). Until then it can't trigger an auto-fallback.</p>
        <?php else: ?>
        <p class="muted">Derived from the last checks: slop average <?= View::e(number_format($quality['slop_avg'], 2)) ?> ·
          block rate <?= View::e(number_format($quality['block_rate'] * 100, 0)) ?>% ·
          reject/fail rate (7d) <?= View::e(number_format($quality['reject_fail_rate'] * 100, 0)) ?>% ·
          sample <?= (int) $quality['sample'] ?>.</p>
        <?php endif; ?>
        <?php if ($quality['breach']): ?>
        <p class="quality-warn">Below 60 with enough sample — Auto mode falls back to Manual automatically.
          Re-enabling Auto is your call, above.</p>
        <?php elseif ($hasSample): ?>
        <p class="muted">Falls back to Manual automatically if the score drops below 60 (needs ≥ 5 checks of sample).</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
