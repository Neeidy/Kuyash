<?php

declare(strict_types=1);

use Kuyash\Core\Messages;
use Kuyash\Core\View;

/**
 * Weekly publishing plan (Phase 23) — its own screen.
 *
 * TWO DIFFERENT THINGS, deliberately kept apart: the PLAN (times you normally
 * publish — a template that acts only when you approve something) and what is
 * actually QUEUED. A plan alone never means something is scheduled, so the page
 * never implies it does.
 *
 * @var string $timezone
 * @var list<string> $timezones
 * @var list<array<string, mixed>> $slots each with next_at resolved
 * @var array{run_id: int, run_after: string}|null $nextScheduled
 * @var string $csrfField trusted generated HTML
 */
?>
<div class="screen-head">
  <div>
    <h1><?= View::t('plan.title') ?></h1>
    <p class="screen-sub"><?= View::t('plan.subtitle') ?></p>
  </div>
  <div class="screen-head__actions">
    <span class="chip chip--neutral"><?= View::e($timezone) ?></span>
  </div>
</div>

<?php /* the scheduled FACT, separate from the plan below it */ ?>
<div class="callout callout--banner next-publish">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="8" cy="8" r="6.5"/><path d="M8 4.5V8l2.5 1.5"/></svg></span>
  <div>
    <strong><?= View::t('cockpit.next_publish') ?>:</strong>
    <?php if ($nextScheduled !== null): ?>
    <?php $nextAt = (string) $nextScheduled['run_after']; ?>
    <a href="/runs/<?= (int) $nextScheduled['run_id'] ?>"><?= View::t('pipeline.content_n', ['n' => (int) $nextScheduled['run_id']]) ?></a>
    <time datetime="<?= View::e($nextAt) ?>" data-countdown="<?= View::e($nextAt) ?>"
          data-t-imminent="<?= View::t('time.imminent') ?>"
          data-t-minutes="<?= View::t('time.in_minutes', ['n' => '{n}']) ?>"
          data-t-hours="<?= View::t('time.in_hours', ['n' => '{n}']) ?>"
          data-t-days="<?= View::t('time.in_days', ['n' => '{n}']) ?>"><?= View::e(Messages::until($nextAt)) ?></time>
    <?php else: ?>
    <span class="muted"><?= View::t('cockpit.next_publish_none') ?></span>
    <?php endif; ?>
  </div>
</div>

<div class="card card--primary">
  <div class="card__head"><h2><?= View::t('plan.times_card') ?></h2></div>
  <div class="card__body">
    <?php if ($slots === []): ?>
    <p class="muted slot-empty"><?= View::t('slots.empty') ?></p>
    <?php else: ?>
    <ul class="slot-list">
      <?php foreach ($slots as $slot): ?>
      <li class="slot<?= $slot['enabled'] ? '' : ' slot--off' ?>">
        <span class="slot__when">
          <strong><?= View::t('day.' . (int) $slot['weekday']) ?></strong>
          <span class="num"><?= View::e((string) $slot['time_hhmm']) ?></span>
        </span>
        <?php if ($slot['enabled'] && ($slot['next_at'] ?? null) !== null): ?>
        <span class="slot__next muted"><?= View::t('slots.next_at', ['when' => Messages::until((string) $slot['next_at'])]) ?></span>
        <?php elseif (!$slot['enabled']): ?>
        <span class="chip chip--neutral"><?= View::t('slots.paused_badge') ?></span>
        <?php endif; ?>
        <span class="slot__actions">
          <form method="post" action="/plan/slots/<?= (int) $slot['id'] ?>/toggle">
            <?= $csrfField ?>
            <button type="submit" class="btn btn--ghost btn--sm"><?= View::t($slot['enabled'] ? 'slots.pause' : 'slots.resume') ?></button>
          </form>
          <form method="post" action="/plan/slots/<?= (int) $slot['id'] ?>/remove">
            <?= $csrfField ?>
            <button type="submit" class="btn btn--danger-ghost btn--sm"><?= View::t('slots.remove') ?></button>
          </form>
        </span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <form method="post" action="/plan/slots" class="slot-add">
      <?= $csrfField ?>
      <label class="field field--inline">
        <span class="field__label"><?= View::t('slots.add') ?></span>
        <select name="weekday" aria-label="<?= View::t('plan.weekday_aria') ?>">
          <?php for ($d = 1; $d <= 7; $d++): ?>
          <option value="<?= $d ?>"><?= View::t('day.' . $d) ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <label class="field field--inline">
        <span class="field__label sr-only"><?= View::t('plan.time_aria') ?></span>
        <input type="time" name="time_hhmm" value="09:00" required aria-label="<?= View::t('plan.time_aria') ?>">
      </label>
      <button type="submit" class="btn btn--primary btn--sm"><?= View::t('slots.add') ?></button>
    </form>
    <p class="note"><?= View::t('plan.how_it_works') ?></p>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('plan.zone_card') ?></h2></div>
  <div class="card__body">
    <form method="post" action="/plan/timezone" class="slot-zone">
      <?= $csrfField ?>
      <label class="field field--inline">
        <span class="field__label"><?= View::t('slots.timezone_label') ?></span>
        <select name="timezone" aria-label="<?= View::t('slots.timezone_label') ?>">
          <?php foreach ($timezones as $tz): ?>
          <option value="<?= View::e($tz) ?>"<?= $tz === $timezone ? ' selected' : '' ?>><?= View::e($tz) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn btn--ghost btn--sm"><?= View::t('slots.timezone_save') ?></button>
    </form>
    <p class="note"><?= View::t('slots.timezone_hint') ?></p>
  </div>
</div>
