<?php

declare(strict_types=1);

use Kuyash\Core\View;

/** @var array{approval_mode: string, kill_switch: bool, daily_post_cap: int, budget_cap_cents: ?int} $settings */
/** @var array{score: int, sample: int, slop_avg: float, block_rate: float, reject_fail_rate: float, breach: bool} $quality */
/** @var string $policyVersion */
/** @var int $autoUsedToday */
/** @var int $spentThisMonthCents */
/** @var string $workspaceName current workspace display name (topbar chip) */
/** @var array{instagram: bool, youtube: bool, tiktok: bool} $aiDisclosure per-platform AI-disclosure toggles */
/** @var string $csrfField trusted generated HTML */

$isAuto = $settings['approval_mode'] === 'auto';
$killOn = $settings['kill_switch'];
?>
<div class="screen-head">
  <div>
    <h1><?= View::t('settings.title') ?></h1>
    <p class="screen-sub"><?= View::t('settings.subtitle') ?></p>
  </div>
  <div class="screen-head__actions">
    <span class="chip chip--<?= $isAuto ? 'warn' : 'ok' ?>"><span class="dot"></span><?= View::t('digest.mode_label') ?> <?= View::e($settings['approval_mode']) ?></span>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('settings.workspace_card') ?></h2></div>
  <div class="card__body">
    <form method="post" action="/settings/name" class="settings-form">
      <?= $csrfField ?>
      <label class="field">
        <span class="field__label"><?= View::t('settings.workspace_label') ?></span>
        <input type="text" name="workspace_name" value="<?= View::e($workspaceName ?? '') ?>"
               maxlength="60" autocomplete="off" required>
        <span class="field__hint"><?= View::t('settings.workspace_hint') ?></span>
      </label>
      <button type="submit" class="btn btn--primary"><?= View::t('settings.workspace_save') ?></button>
    </form>
  </div>
</div>

<?php if ($killOn): ?>
<div class="callout callout--err callout--banner" role="alert">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2L1.5 13.5h13z"/><path d="M8 6.5V10M8 12h.01"/></svg></span>
  <div><strong><?= View::t('settings.kill_banner_title') ?></strong> <?= View::t('settings.kill_banner_body') ?></div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><h2><?= View::t('settings.kill_switch_card') ?></h2>
    <span class="card__action"><span class="chip chip--<?= $killOn ? 'err' : 'ok' ?>"><span class="dot"></span><?= $killOn ? View::t('settings.kill_on_chip') : View::t('digest.off') ?></span></span>
  </div>
  <div class="card__body">
    <div class="killswitch-row">
      <p class="muted"><?= View::t('settings.kill_desc') ?></p>
      <?php /* data-confirm goes on the FORM — the global handler matches form[data-confirm] (app.js) */ ?>
      <form method="post" action="/settings/kill-switch"<?= $killOn ? '' : ' data-confirm="' . View::t('settings.kill_confirm') . '"' ?>>
        <?= $csrfField ?>
        <input type="hidden" name="state" value="<?= $killOn ? 'off' : 'on' ?>">
        <?php if ($killOn): ?>
        <button type="submit" class="btn btn--primary"><?= View::t('settings.kill_turn_off') ?></button>
        <?php else: ?>
        <button type="submit" class="btn btn--danger"><?= View::t('settings.kill_turn_on') ?></button>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>

<div class="card card--primary">
  <div class="card__head"><h2><?= View::t('settings.mode_card') ?></h2></div>
  <div class="card__body">
    <form method="post" action="/settings" class="settings-form">
      <?= $csrfField ?>

      <fieldset class="mode-pick">
        <legend class="field__label"><?= View::t('settings.approval_mode') ?></legend>
        <label class="mode-option<?= $isAuto ? '' : ' is-picked' ?>">
          <input type="radio" name="approval_mode" value="manual"<?= $isAuto ? '' : ' checked' ?>>
          <span><strong><?= View::t('settings.manual_strong') ?></strong> <?= View::t('settings.manual_desc') ?></span>
        </label>
        <label class="mode-option<?= $isAuto ? ' is-picked' : '' ?>">
          <input type="radio" name="approval_mode" value="auto"<?= $isAuto ? ' checked' : '' ?>>
          <span><strong><?= View::t('settings.auto_strong') ?></strong> <?= View::t('settings.auto_desc', ['policy' => $policyVersion]) ?></span>
        </label>
      </fieldset>

      <div class="settings-grid">
        <label class="field">
          <span class="field__label"><?= View::t('settings.daily_cap_label') ?></span>
          <input type="text" inputmode="numeric" name="daily_post_cap" value="<?= (int) $settings['daily_post_cap'] ?>">
          <span class="field__hint num"><?= View::t('settings.auto_slots') ?> <?= (int) $autoUsedToday ?>/<?= (int) $settings['daily_post_cap'] ?></span>
        </label>
        <label class="field">
          <span class="field__label"><?= View::t('settings.budget_label') ?></span>
          <input type="text" inputmode="numeric" name="budget_cap_usd"
                 value="<?= $settings['budget_cap_cents'] !== null ? (int) ($settings['budget_cap_cents'] / 100) : '' ?>" placeholder="<?= View::t('settings.no_cap') ?>">
          <span class="field__hint num"><?= View::t('settings.observed_spend') ?> $<?= View::e(number_format($spentThisMonthCents / 100, 2)) ?></span>
        </label>
      </div>

      <button type="submit" class="btn btn--primary"><?= View::t('settings.save') ?></button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('settings.ai_card') ?></h2></div>
  <div class="card__body">
    <form method="post" action="/settings/ai-disclosure" class="settings-form">
      <?= $csrfField ?>
      <p class="muted"><?= View::t('settings.ai_desc') ?></p>
      <?php foreach (['instagram' => 'settings.ai_instagram', 'youtube' => 'settings.ai_youtube', 'tiktok' => 'settings.ai_tiktok'] as $p => $labelKey): ?>
      <label class="ai-toggle">
        <input type="checkbox" name="ai_<?= $p ?>"<?= ($aiDisclosure[$p] ?? true) ? ' checked' : '' ?>>
        <span><?= View::t($labelKey) ?></span>
      </label>
      <?php endforeach; ?>
      <p class="quality-warn"><?= View::t('settings.ai_warn') ?></p>
      <button type="submit" class="btn btn--primary"><?= View::t('settings.ai_save') ?></button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('settings.quality_score') ?></h2></div>
  <div class="card__body">
    <?php $hasSample = (int) $quality['sample'] >= 5; ?>
    <div class="quality-row">
      <span class="quality-score num<?= $quality['breach'] ? ' quality-score--bad' : '' ?>"><?= $hasSample ? (int) $quality['score'] : '—' ?></span>
      <div class="quality-meta">
        <?php if (!$hasSample): ?>
        <p class="muted"><?= View::t('settings.quality_insufficient', ['n' => (int) $quality['sample']]) ?></p>
        <?php else: ?>
        <p class="muted"><?= View::t('settings.quality_derived', [
            'slop' => number_format($quality['slop_avg'], 2),
            'block' => number_format($quality['block_rate'] * 100, 0),
            'reject' => number_format($quality['reject_fail_rate'] * 100, 0),
            'sample' => (int) $quality['sample'],
        ]) ?></p>
        <?php endif; ?>
        <?php if ($quality['breach']): ?>
        <p class="quality-warn"><?= View::t('settings.quality_breach') ?></p>
        <?php elseif ($hasSample): ?>
        <p class="muted"><?= View::t('settings.quality_ok') ?></p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
