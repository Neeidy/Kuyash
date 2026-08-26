<?php

declare(strict_types=1);

use Kuyash\Core\Format;
use Kuyash\Core\I18n;
use Kuyash\Core\Messages;
use Kuyash\Core\View;
use Kuyash\Publish\PlanBoard;

/**
 * The weekly plan as a CALENDAR (Phase 24).
 *
 * THREE DIFFERENT THINGS, deliberately kept apart:
 *   • the TIMES you normally publish (a weekly template),
 *   • the DAYS those times land on, and what is on each of them,
 *   • what is actually QUEUED — read from the job gate, not from the plan.
 *
 * A day never claims more than the system is doing: "Ready to go out" means a
 * publish is really queued, "Waiting for you" means a real approval is pending,
 * and a day that produced nothing says WHY.
 *
 * @var string $timezone
 * @var list<string> $timezones
 * @var list<array<string, mixed>> $slots each with next_at resolved
 * @var list<array{date: string, weekday: int, is_today: bool, cells: list<array<string, mixed>>}> $days
 * @var list<array<string, mixed>> $library ready videos
 * @var array{run_id: int, run_after: string}|null $nextScheduled
 * @var bool $planPaused
 * @var int $leadMinutes
 * @var bool $hasAutoSlot
 * @var array{per_video: int, per_week: int, count: int}|null $autoCost
 * @var bool $hasAccounts
 * @var int|null $perVideoCost cents, null when it cannot be worked out
 * @var bool $autoApproves workspace runs the compliance agent on finished renders
 * @var string $csrfField trusted generated HTML
 */

/** Cell state → visible label. Never leaks a run/job word. */
$cellLabel = static fn (string $state): string => View::t(match ($state) {
    PlanBoard::AUTO_WAITING => 'plan.cell_auto',
    PlanBoard::PREPARING => 'plan.cell_preparing',
    PlanBoard::AWAITING => 'plan.cell_awaiting',
    PlanBoard::SCHEDULED => 'plan.cell_scheduled',
    PlanBoard::PUBLISHED => 'plan.cell_published',
    PlanBoard::STOPPED => 'plan.cell_stopped',
    PlanBoard::MISSED => 'plan.cell_missed',
    PlanBoard::PAUSED => 'plan.cell_paused',
    PlanBoard::BLOCKED => 'plan.cell_blocked',
    default => 'plan.cell_open',
});
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
    <span class="muted"><?= View::t($slots === [] ? 'cockpit.next_publish_none' : 'cockpit.next_publish_planned') ?></span>
    <?php endif; ?>
  </div>
</div>

<?php if ($planPaused): ?>
<div class="callout callout--warn"><?= View::t('plan.paused_banner') ?></div>
<?php endif; ?>

<?php if (!$hasAccounts): ?>
<?php /* a plan with nowhere to publish: better said here than discovered later
         as a red "Missed — no connected channel" */ ?>
<div class="callout callout--warn">
  <?= View::t('plan.no_accounts') ?> <a href="/accounts"><?= View::t('plan.no_accounts_link') ?></a>
</div>
<?php endif; ?>

<?php if ($slots === []): ?>
<?php /* EMPTY STATE = the mode question. This is where the operator decides how
         the plan works, so it is asked once, plainly, with the first time. */ ?>
<div class="card card--primary">
  <div class="card__head"><h2><?= View::t('plan.choose_mode_title') ?></h2></div>
  <div class="card__body">
    <p class="muted"><?= View::t('plan.choose_mode_body') ?></p>
    <form method="post" action="/plan/slots" class="slot-add">
      <?= $csrfField ?>
      <label class="field field--inline">
        <span class="field__label"><?= View::t('plan.weekday_aria') ?></span>
        <select name="weekday">
          <?php for ($d = 1; $d <= 7; $d++): ?>
          <option value="<?= $d ?>"><?= View::t('day.' . $d) ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <label class="field field--inline">
        <span class="field__label"><?= View::t('plan.time_aria') ?></span>
        <input type="time" name="time_hhmm" value="09:00" required>
      </label>
      <?php /* Each choice carries its own guidance, revealed by the radio next
               to it (a plain sibling selector — no JS, and it still works with
               scripting off). Putting the "you fill it" path's instructions HERE
               is the difference between choosing it and knowing what to do next. */ ?>
      <fieldset class="mode-choice">
        <legend class="field__label"><?= View::t('plan.mode_label') ?></legend>
        <div class="mode-opt">
          <input type="radio" id="mode-manual" name="mode" value="manual" checked>
          <label for="mode-manual"><?= View::t('plan.mode_manual') ?></label>
          <p class="mode-opt__help">
            <?= View::t('plan.mode_manual_help') ?>
            <a href="/library"><?= View::t('plan.mode_manual_help_link') ?></a>
          </p>
        </div>
        <div class="mode-opt">
          <input type="radio" id="mode-auto" name="mode" value="auto">
          <label for="mode-auto"><?= View::t('plan.mode_auto') ?></label>
          <p class="mode-opt__help">
            <?= ($perVideoCost ?? null) !== null
                ? View::t('plan.mode_auto_help_cost', ['amount' => Format::cents($perVideoCost)])
                : View::t('plan.mode_auto_help') ?>
          </p>
        </div>
      </fieldset>
      <button type="submit" class="btn btn--primary btn--sm"><?= View::t('slots.add') ?></button>
    </form>
    <p class="note"><?= View::t($autoApproves ? 'plan.approval_promise_auto' : 'plan.approval_promise') ?></p>
  </div>
</div>
<?php else: ?>

<?php /* ── the calendar ─────────────────────────────────────────────────── */ ?>
<div class="card card--primary">
  <div class="card__head"><h2><?= View::t('plan.calendar_card') ?></h2></div>
  <div class="card__body">
    <ol class="cal" role="list" aria-label="<?= View::t('plan.calendar_card') ?>">
      <?php foreach ($days as $day): ?>
      <li class="cal__day<?= $day['is_today'] ? ' cal__day--today' : '' ?>">
        <div class="cal__head">
          <h3 class="cal__dow"><?= View::t('day.' . $day['weekday']) ?></h3>
          <span class="cal__date num"><?= View::e(substr($day['date'], 8, 2) . '.' . substr($day['date'], 5, 2)) ?></span>
          <?php if ($day['is_today']): ?><span class="chip chip--accent"><?= View::t('plan.today') ?></span><?php endif; ?>
        </div>
        <?php if ($day['cells'] === []): ?>
        <p class="cal__quiet muted"><?= View::t('plan.no_times_today') ?></p>
        <?php else: ?>
        <?php foreach ($day['cells'] as $cell): ?>
        <div class="cell cell--<?= View::e($cell['state']) ?>">
          <div class="cell__when">
            <strong class="num"><?= View::e($cell['time']) ?></strong>
            <span class="cell__state"><?= $cellLabel($cell['state']) ?></span>
          </div>

          <?php if ($cell['moved']): ?>
          <?php /* the queue is holding it at a different moment than planned
                   (a daily cap deferral, a retry backoff) — the calendar shows
                   the real one and says it moved */ ?>
          <p class="cell__note muted"><?= View::t('plan.time_moved', ['time' => $cell['time']]) ?></p>
          <?php elseif ($cell['planned_time'] !== '' && $cell['planned_time'] !== $cell['time']): ?>
          <?php /* daylight saving moved it: say so rather than quietly differing */ ?>
          <p class="cell__note muted"><?= View::t('plan.time_shifted', ['time' => $cell['time']]) ?></p>
          <?php endif; ?>

          <?php if ($cell['asset_title'] !== null): ?>
          <?php /* the tag renders as its own chip so it survives a 68px cell at
                   768px, and the title gets the rest of the width back */ ?>
          <?php [$cellTag, $cellTitle] = Format::splitTag((string) $cell['asset_title']); ?>
          <p class="cell__title">
            <?php if ($cellTag !== null): ?><span class="chip chip--faint cell__tag"><?= View::e($cellTag) ?></span><?php endif; ?>
            <span class="cell__title-text"><?= View::e($cellTitle) ?></span>
          </p>
          <?php endif; ?>

          <?php if (in_array($cell['state'], [PlanBoard::MISSED, PlanBoard::BLOCKED, PlanBoard::STOPPED], true) && $cell['reason'] !== null): ?>
          <p class="cell__note muted"><?= View::t('plan.reason_' . $cell['reason']) ?></p>
          <?php elseif ($cell['state'] === PlanBoard::MISSED): ?>
          <p class="cell__note muted"><?= View::t('plan.reason_missed') ?></p>
          <?php elseif ($cell['state'] === PlanBoard::STOPPED): ?>
          <?php /* A run cancelled mid-pipeline — by compliance, say — leaves the
                   day pointing at it with no skip_reason, and the cell showed a
                   bare red "Stopped" with nothing to explain it. The day says
                   what it knows and where to look, rather than nothing. */ ?>
          <p class="cell__note muted"><?= View::t('plan.reason_stopped_unknown') ?><?php if ($cell['run_id'] !== null): ?>
            <?php /* the sentence says "open the run", so the run is one click
                     away rather than something to go hunting for */ ?>
            <a href="/runs/<?= (int) $cell['run_id'] ?>"><?= View::t('common.view_run') ?></a><?php endif; ?></p>
          <?php elseif ($cell['state'] === PlanBoard::PUBLISHED && $cell['post_count'] > 0): ?>
          <p class="cell__note muted"><?= View::t('plan.published_targets', ['done' => $cell['published_count'], 'total' => $cell['post_count']]) ?></p>
          <?php elseif ($cell['state'] === PlanBoard::AUTO_WAITING): ?>
          <p class="cell__note muted"><?= View::t('plan.auto_needs_approval') ?></p>
          <?php endif; ?>

          <div class="cell__actions">
            <?php if ($cell['state'] === PlanBoard::OPEN && !$cell['is_past']): ?>
            <?php if ($library === []): ?>
            <span class="field__hint"><?= View::t('plan.library_empty') ?> <a href="/library"><?= View::t('plan.library_link') ?></a></span>
            <?php else: ?>
            <form method="post" action="/plan/day/<?= (int) $cell['id'] ?>/assign" class="cell__assign">
              <?= $csrfField ?>
              <?php /* raw on purpose: View::t() escapes the interpolated param, so
                       pre-escaping here would double-encode any locale that ever
                       introduces an apostrophe or an ampersand into a day name */ ?>
              <?php $cellWhen = I18n::t('day.' . $day['weekday']) . ' ' . $cell['time']; ?>
              <label class="field field--inline">
                <span class="field__label sr-only"><?= View::t('plan.assign_for', ['when' => $cellWhen]) ?></span>
                <select name="asset_id">
                  <?php foreach ($library as $video): ?>
                  <option value="<?= (int) $video['id'] ?>" title="<?= View::e((string) $video['title']) ?>"><?= View::e((string) $video['title']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <button type="submit" class="btn btn--primary btn--sm">
                <?= View::t('plan.assign_submit') ?>
                <span class="sr-only"><?= View::t('plan.assign_for', ['when' => $cellWhen]) ?></span>
              </button>
            </form>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($cell['state'] === PlanBoard::AWAITING): ?>
            <a class="btn btn--ghost btn--sm" href="/queue"><?= View::t('plan.review') ?></a>
            <?php endif; ?>

            <?php /* A STOPPED day — a run compliance cancelled, or one that failed
                     — is exactly the day an operator most needs to free, and it
                     was the one day the button was hidden for. The controller,
                     not this line, decides what may be cleared: it refuses a day
                     that actually published. PUBLISHED and MISSED stay out
                     because there is nothing to take back. */ ?>
            <?php if ($cell['run_id'] !== null && $cell['state'] !== PlanBoard::PUBLISHED && $cell['state'] !== PlanBoard::MISSED): ?>
            <form method="post" action="/plan/day/<?= (int) $cell['id'] ?>/clear"
                  data-confirm="<?= View::t('plan.clear_confirm') ?>">
              <?= $csrfField ?>
              <button type="submit" class="btn btn--danger-ghost btn--sm"><?= View::t('plan.clear') ?></button>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ol>
    <p class="note"><?= View::t($autoApproves ? 'plan.approval_promise_auto' : 'plan.approval_promise') ?></p>
  </div>
</div>

<?php /* ── the weekly times ─────────────────────────────────────────────── */ ?>
<div class="card">
  <div class="card__head"><h2><?= View::t('plan.times_card') ?></h2></div>
  <div class="card__body">
    <ul class="slot-list">
      <?php foreach ($slots as $slot): ?>
      <li class="slot<?= $slot['enabled'] ? '' : ' slot--off' ?>">
        <span class="slot__when">
          <strong><?= View::t('day.' . (int) $slot['weekday']) ?></strong>
          <span class="num"><?= View::e((string) $slot['time_hhmm']) ?></span>
        </span>
        <span class="chip <?= $slot['mode'] === 'auto' ? 'chip--accent' : 'chip--neutral' ?>">
          <?= View::t($slot['mode'] === 'auto' ? 'plan.mode_auto' : 'plan.mode_manual') ?>
        </span>
        <?php if ($slot['enabled'] && ($slot['next_at'] ?? null) !== null): ?>
        <span class="slot__next muted"><?= View::t('slots.next_at', ['when' => Messages::until((string) $slot['next_at'])]) ?></span>
        <?php elseif (!$slot['enabled']): ?>
        <span class="chip chip--neutral"><?= View::t('slots.paused_badge') ?></span>
        <?php endif; ?>
        <span class="slot__actions">
          <form method="post" action="/plan/slots/<?= (int) $slot['id'] ?>/mode">
            <?= $csrfField ?>
            <button type="submit" class="btn btn--ghost btn--sm"><?= View::t($slot['mode'] === 'auto' ? 'plan.mode_switch_manual' : 'plan.mode_switch_auto') ?></button>
          </form>
          <form method="post" action="/plan/slots/<?= (int) $slot['id'] ?>/toggle">
            <?= $csrfField ?>
            <button type="submit" class="btn btn--ghost btn--sm"><?= View::t($slot['enabled'] ? 'slots.pause' : 'slots.resume') ?></button>
          </form>
          <?php /* The label and the confirmation both scale to what is actually
                   at stake: removing a time that holds videos CANCELS them, and
                   saying "take this video off that day" there would describe a
                   smaller action than the one performed. With JS off the label
                   itself is still the warning. */ ?>
          <?php $held = (int) ($slot['committed'] ?? 0); ?>
          <form method="post" action="/plan/slots/<?= (int) $slot['id'] ?>/remove"
                data-confirm="<?= $held > 0
                    ? View::t('plan.remove_confirm_n', ['n' => $held])
                    : View::t('plan.remove_confirm') ?>">
            <?= $csrfField ?>
            <?php if ($held > 0): ?>
            <input type="hidden" name="cascade" value="1">
            <?php endif; ?>
            <button type="submit" class="btn btn--danger-ghost btn--sm">
              <?= $held > 0 ? View::t('plan.remove_cascade') : View::t('slots.remove') ?>
            </button>
          </form>
        </span>
      </li>
      <?php endforeach; ?>
    </ul>

    <form method="post" action="/plan/slots" class="slot-add">
      <?= $csrfField ?>
      <label class="field field--inline">
        <span class="field__label"><?= View::t('plan.weekday_aria') ?></span>
        <select name="weekday">
          <?php for ($d = 1; $d <= 7; $d++): ?>
          <option value="<?= $d ?>"><?= View::t('day.' . $d) ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <label class="field field--inline">
        <span class="field__label"><?= View::t('plan.time_aria') ?></span>
        <input type="time" name="time_hhmm" value="09:00" required>
      </label>
      <?php /* Each choice carries its own guidance, revealed by the radio next
               to it (a plain sibling selector — no JS, and it still works with
               scripting off). Putting the "you fill it" path's instructions HERE
               is the difference between choosing it and knowing what to do next. */ ?>
      <fieldset class="mode-choice">
        <legend class="field__label"><?= View::t('plan.mode_label') ?></legend>
        <div class="mode-opt">
          <input type="radio" id="mode-manual" name="mode" value="manual" checked>
          <label for="mode-manual"><?= View::t('plan.mode_manual') ?></label>
          <p class="mode-opt__help">
            <?= View::t('plan.mode_manual_help') ?>
            <a href="/library"><?= View::t('plan.mode_manual_help_link') ?></a>
          </p>
        </div>
        <div class="mode-opt">
          <input type="radio" id="mode-auto" name="mode" value="auto">
          <label for="mode-auto"><?= View::t('plan.mode_auto') ?></label>
          <p class="mode-opt__help">
            <?= ($perVideoCost ?? null) !== null
                ? View::t('plan.mode_auto_help_cost', ['amount' => Format::cents($perVideoCost)])
                : View::t('plan.mode_auto_help') ?>
          </p>
        </div>
      </fieldset>
      <button type="submit" class="btn btn--primary btn--sm"><?= View::t('slots.add') ?></button>
    </form>
    <p class="note"><?= View::t('plan.how_it_works') ?></p>
  </div>
</div>
<?php endif; ?>

<?php /* ── how the plan runs ────────────────────────────────────────────── */ ?>
<div class="card">
  <div class="card__head"><h2><?= View::t('plan.settings_card') ?></h2></div>
  <div class="card__body">
    <?php if ($hasAutoSlot): ?>
    <form method="post" action="/plan/settings" class="slot-zone">
      <?= $csrfField ?>
      <label class="field field--inline">
        <span class="field__label"><?= View::t('plan.lead_label') ?></span>
        <input type="number" name="lead_minutes" min="30" max="1440" step="30" value="<?= (int) $leadMinutes ?>">
      </label>
      <button type="submit" class="btn btn--ghost btn--sm"><?= View::t('plan.settings_save') ?></button>
    </form>
    <p class="note"><?= View::t('plan.lead_hint') ?></p>
    <?php if (($autoCost ?? null) !== null): ?>
    <?php /* the real estimate from the same source the budget gate uses — an
             operator turning on automatic publishing is told the price */ ?>
    <p class="note"><?= View::t('plan.cost_note', [
        'amount' => Format::cents($autoCost['per_video']),
        'count' => $autoCost['count'],
        'weekly' => Format::cents($autoCost['per_week']),
    ]) ?></p>
    <?php endif; ?>

    <form method="post" action="/plan/pause">
      <?= $csrfField ?>
      <button type="submit" class="btn btn--ghost btn--sm"><?= View::t($planPaused ? 'plan.resume' : 'plan.pause') ?></button>
    </form>
    <?php endif; ?>

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
