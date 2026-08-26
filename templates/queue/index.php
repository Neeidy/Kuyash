<?php

declare(strict_types=1);

use Kuyash\Core\Format;
use Kuyash\Core\Messages;
use Kuyash\Core\View;

/** @var list<array<string, mixed>> $awaiting jobs paused for approval */
/** @var list<array<string, mixed>> $jobs newest first */
/** @var list<array<string, mixed>> $runs newest first */
/** @var string $csrfField trusted generated HTML */
/** @var bool $workerAlive */
/** @var list<array<string, mixed>> $slots weekly plan slots, each with next_at resolved (Phase 23) */
/** @var string $timezone workspace zone the slot times are written in */
?>
<?php if (($workerAlive ?? true) === false): ?>
<div class="callout callout--warn callout--banner" role="alert">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2L1.5 13.5h13z"/><path d="M8 6.5V10M8 12h.01"/></svg></span>
  <div><strong><?= View::t('dash.worker_down_title') ?></strong> <?= View::t('queue.worker_down_body') ?></div>
</div>
<?php endif; ?>
<div class="screen-head">
  <div>
    <h1><?= View::t('queue.title') ?></h1>
    <p class="screen-sub"><?= View::t('queue.subtitle') ?></p>
  </div>
  <div class="screen-head__actions">
    <span class="chip chip--<?= $awaiting === [] ? 'ok' : 'warn' ?> num"><?= View::t('queue.waiting_for_you', ['n' => count($awaiting)]) ?></span>
  </div>
</div>

<div class="card card--primary">
  <div class="card__head"><h2><?= View::t('queue.approvals') ?></h2></div>
  <div class="card__body">
    <?php if ($awaiting === []): ?>
    <p class="muted"><?= View::t('dash.nothing_waiting') ?></p>
    <?php else: ?>
    <div class="approve-list">
      <?php foreach ($awaiting as $job): ?>
      <article class="approve-card" data-approve-card id="run-<?= (int) $job['run_id'] ?>">
        <div class="approve-card__main">
          <h3><?= View::e(Messages::jobType((string) $job['type'])) ?> · <?= View::t('common.run_n', ['n' => (int) $job['run_id']]) ?></h3>
          <div class="approve-card__meta">
            <span class="chip chip--warn"><span class="dot"></span><?= View::t('status.awaiting_approval') ?></span>
            <?php if (isset($job['result']['word_count'], $job['result']['estimated_duration_s'])): ?>
            <span class="chip chip--neutral num"><?= (int) $job['result']['word_count'] ?> <?= View::t('queue.words') ?> · ~<?= View::e((string) $job['result']['estimated_duration_s']) ?>s</span>
            <?php endif; ?>
            <?php
              // Phase 25 — this chip sits next to the button that publishes, so
              // it has to describe the text that WILL publish. Once a person has
              // edited the words, the compliance_check score belongs to the
              // generated draft and is a number about the wrong thing; the edit
              // was judged by ContentGate at save time, with the same scorer and
              // the same thresholds. No edit → the generated verdict still is
              // the right answer and nothing changes.
              $cGen = (string) ($job['result']['compliance']['status'] ?? '');
              $cBadge = $job['text']['text']['badge'] ?? null;
              $cEdited = is_array($cBadge);
              $cs = $cEdited ? (string) $cBadge['status'] : $cGen;
              $cScore = $cEdited ? $cBadge['slop'] : ($job['result']['compliance']['slop_score'] ?? null);
            ?>
            <?php if ($job['type'] === 'render_review' && $cs !== ''): ?>
            <?php if ($job['result']['ai_label_required'] ?? false): ?>
            <?php /* The label follows the MEDIA, so an edit to the words leaves
                     it exactly where it was — and it is keyed on the requirement
                     itself, not on a status a slop warning can outrank. */ ?>
            <span class="chip chip--ai"><?= View::t('queue.ai_label_will_set') ?></span>
            <?php endif; ?>
            <?php
              // A similarity chip needs a similarity number. Without one — an
              // edit warned about something else, or a generated verdict with
              // no score — naming that check and printing 0.00 would be the
              // wrong check and a meaningless figure, beside the publish button.
              $cSimilar = $cScore !== null && ($cEdited ? $cBadge['similar'] : true);
            ?>
            <?php if ($cs === 'warn' && $cSimilar): ?>
            <span class="chip chip--warn chip--wrap"><span class="dot"></span><?= View::t($cEdited ? 'queue.similarity_edited' : 'queue.similarity', ['score' => number_format((float) $cScore, 2)]) ?></span>
            <?php elseif ($cs === 'pass' || $cs === 'pass_with_ai_label'): ?>
            <span class="chip chip--ok"><span class="dot"></span><?= View::t($cEdited ? 'queue.compliance_pass_edited' : 'queue.compliance_pass') ?></span>
            <?php elseif ($cs === 'block'): ?>
            <span class="chip chip--err"><span class="dot"></span><?= View::t('queue.checks_blocked') ?></span>
            <?php else: ?>
            <?php /* warned about something else, or a status this screen does
                     not know — say the honest, unspecific thing rather than
                     leaving the card with no compliance chip at all */ ?>
            <span class="chip chip--warn chip--wrap"><span class="dot"></span><?= View::t($cEdited ? 'queue.checks_note_edited' : 'queue.checks_note') ?></span>
            <?php endif; ?>
            <?php endif; ?>
            <a class="btn btn--ghost btn--sm" href="/runs/<?= (int) $job['run_id'] ?>"><?= View::t('common.view_run') ?></a>
          </div>
          <?php if ($job['type'] === 'script_draft' && isset($job['result']['script'])): ?>
          <blockquote class="approve-card__quote"><?= nl2br(View::e(mb_substr((string) $job['result']['script'], 0, 400))) ?></blockquote>
          <?php elseif ($job['type'] === 'render_review'): ?>
            <?php $draftId = $job['result']['draft_render_id'] ?? null; $libId = $job['result']['library_asset_id'] ?? null; ?>
            <?php if ($draftId !== null || $libId !== null): ?>
            <?php $src = $draftId !== null ? '/render/' . (int) $draftId : '/media/' . (int) $libId; ?>
            <div class="inline-player approve-card__player" data-inline-player>
              <?php /* A distribution run has no ASSEMBLE node and therefore no draft
                       render — its preview IS the library clip that will go out.
                       The poster goes on the VIDEO, not in a sibling <img>: both
                       were absolutely positioned at inset:0 and the video stacks
                       last, so a <video preload="metadata"> painted BLACK over the
                       image. That only looked right while the fixture's render
                       files 404'd and the video element stayed empty. */ ?>
              <?php $poster = $draftId !== null ? '/render/' . (int) $draftId . '/poster'
                    : (($job['has_poster'] ?? false) ? '/media/' . (int) $libId . '/poster' : null); ?>
              <video class="inline-player__video" src="<?= View::e($src) ?>"<?= $poster !== null ? ' poster="' . View::e($poster) . '"' : '' ?> preload="metadata" playsinline></video>
              <button type="button" class="inline-player__play" aria-label="<?= View::t('player.play') ?>">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
              </button>
              <span class="inline-player__badge"><span class="inline-player__badge-dot"></span><?= View::t('player.playing') ?></span>
              <span class="inline-player__progress"></span>
            </div>
            <?php else: ?>
            <div class="inline-player inline-player--pending approve-card__player">
              <span class="inline-player__ph-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" width="24" height="24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M10 9.5l5 2.5-5 2.5z"/></svg></span>
              <span class="inline-player__ph-type"><?= View::e(Messages::jobType('render_review')) ?></span>
              <span class="inline-player__ph-note"><?= View::t('player.preview_pending') ?></span>
            </div>
            <?php endif; ?>
            <?php /* Phase 21: show a clean, truthful compliance line — never the raw
                     internal summary ("Render review (mock): … policy mock-v0").
                     Phase 25: and only while it is still about the outgoing text.
                     Once a person has edited the words, this line would be the
                     DRAFT's verdict — a bare "Compliance: passed" a few pixels
                     above the publish button, contradicting the chip above that
                     already speaks for the edited text. The meta chip says it;
                     this does not say it twice from the wrong source. A separate
                     variable, too: reusing $cs shadowed the badge-aware one. */ ?>
            <?php $cNote = ($cEdited ?? false) ? null : ($job['result']['compliance']['status'] ?? null); ?>
            <?php if ($cNote === 'pass' || $cNote === 'pass_with_ai_label'): ?>
            <p class="approve-card__note"><?= View::t('queue.compliance_passed') ?><?= ($job['result']['ai_label_required'] ?? false) ? ' · ' . View::t('queue.ai_label_required') : '' ?></p>
            <?php elseif (($job['result']['ai_label_required'] ?? false)): ?>
            <?php /* the label follows the MEDIA, so it survives an edit */ ?>
            <p class="approve-card__note"><?= View::t('queue.ai_label_required') ?></p>
            <?php endif; ?>
          <?php elseif (isset($job['result']['summary'])): ?>
          <p class="approve-card__note"><?= View::e((string) $job['result']['summary']) ?></p>
          <?php endif; ?>
        </div>
        <?php if (($job['text'] ?? null) !== null): ?>
        <?php /* Phase 25 — the AI wrote a first draft; this is where a person
                 makes it theirs. The edit is what publishes, it re-passes the
                 same compliance checks on the way in, and the AI notice sits
                 outside it where no edit can reach. */ ?>
        <?php
            $text = $job['text']['text'];
            $limits = $job['text']['limits'];
            $disclosureLine = $job['text']['disclosure'];
            $runId = (int) $job['run_id'];
            $backTo = 'queue';
            $withHeading = true;
            // the card's meta row already carries the compliance chip
            $showBadge = false;
            require __DIR__ . '/../partials/text-editor.php';
        ?>
        <?php endif; ?>
        <div class="approve-card__actions">
          <?php /* Phase 25 — "Save the text" and "Approve & publish" are two
                   sibling forms, and approving without saving would quietly send
                   the AI's words instead of yours. Said plainly here whether or
                   not scripting is on; the confirm below is the belt to this
                   line's braces. */ ?>
          <?php
            $editorOpen = ($job['text'] ?? null) !== null && ($job['text']['text']['editable'] ?? false) === true;
            // Once a save has landed, "what Kuyash wrote is what goes out" is
            // false — the saved edit is. Same sentence, two different truths.
            $alreadyEdited = $editorOpen && (($job['text']['text']['edited'] ?? false) === true);
            $unsavedKey = $alreadyEdited ? 'content.unsaved_edited' : 'content.unsaved';
            $unsavedConfirm = $alreadyEdited ? 'content.unsaved_confirm_edited' : 'content.unsaved_confirm';
          ?>
          <?php if ($editorOpen): ?>
          <p class="field__hint approve-card__unsaved"><?= View::t($unsavedKey) ?></p>
          <?php endif; ?>
          <form method="post" action="/queue/job/<?= (int) $job['id'] ?>/approve"
                <?php if ($editorOpen): ?>data-needs-saved-text="<?= View::t($unsavedConfirm) ?>"<?php endif; ?>>
            <?= $csrfField ?>
            <?php if ($job['type'] === 'render_review' && ($job['planned_at'] ?? null) !== null): ?>
            <?php /* PLANNED: the day already answered "when", so this states it
                     instead of asking again. Going out sooner is still possible,
                     but only as a deliberate, separate choice — never a silent
                     fall-through to "publish now". */ ?>
            <div class="approve-card__schedule approve-card__planned">
              <span class="muted"><?= View::t('queue.planned_for', ['when' => Messages::until((string) $job['planned_at'])]) ?></span>
              <span class="field__hint"><?= View::t('queue.planned_keep') ?></span>
              <label class="mode-choice__opt">
                <input type="checkbox" name="publish_now" value="1"> <?= View::t('queue.planned_now') ?>
              </label>
              <?php /* ticking the box abandons the planned day — say so here, at
                       the point of commitment, not only in the label above */ ?>
              <span class="field__hint"><?= View::t('queue.planned_now_hint') ?></span>
            </div>
            <?php elseif ($job['type'] === 'render_review'): ?>
            <div class="approve-card__schedule">
              <?php if (($job['planned_missed'] ?? false) === true): ?>
              <?php /* its planned time went by while it waited — say so, and give
                       back the full picker rather than a dead time */ ?>
              <span class="field__hint"><?= View::t('queue.planned_missed') ?></span>
              <?php endif; ?>
              <span class="muted"><?= View::t('queue.schedule_label', ['zone' => $timezone]) ?></span>
              <?php if ($slots !== []): ?>
              <?php /* the weekly plan first: picking a planned time is the common
                       case, and each option states the real instant it lands on */ ?>
              <label class="field field--inline">
                <select name="slot_id">
                  <option value=""><?= View::t('slots.publish_now') ?></option>
                  <?php foreach ($slots as $slot): ?>
                  <option value="<?= (int) $slot['id'] ?>">
                    <?= View::t('day.' . (int) $slot['weekday']) ?> <?= View::e((string) $slot['time_hhmm']) ?>
                    · <?= View::e(Messages::until((string) $slot['next_at'])) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <?php endif; ?>
              <label class="field field--inline">
                <?php /* always the "exact time" hint: the zone-bearing question is
                         already rendered above, and repeating it here without its
                         {zone} param printed the placeholder literally — the state
                         every brand-new workspace (no slots yet) lands on. */ ?>
                <span class="field__hint"><?= View::t('slots.pick_time') ?></span>
                <input type="datetime-local" name="scheduled_for">
              </label>
              <?php if ($slots === []): ?>
              <?php /* no plan yet: say the feature exists, right where someone is
                       already deciding when to publish */ ?>
              <span class="field__hint"><a href="/plan"><?= View::t('slots.create_plan') ?></a></span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn--primary btn--sm"><?= $job['type'] === 'render_review' ? View::t('queue.approve_publish') : View::t('queue.approve') ?></button>
          </form>
          <form method="post" action="/queue/job/<?= (int) $job['id'] ?>/reject"
                data-confirm="<?= View::t('queue.reject_confirm') ?>">
            <?= $csrfField ?>
            <button type="submit" class="btn btn--danger-ghost btn--sm"><?= View::t('queue.reject') ?></button>
          </form>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <p class="note"><?= View::t('queue.approval_note') ?></p>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('queue.jobs') ?></h2>
    <span class="card__action"><span class="chip chip--faint num"><?= View::t('queue.n_shown', ['n' => count($jobs)]) ?></span></span>
  </div>
  <div class="card__body">
    <?php if ($jobs === []): ?>
    <div class="ui-state">
      <span class="ui-state__icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M8 1.5l6 3-6 3-6-3z"/><path d="M2 8l6 3 6-3M2 11l6 3 6-3"/></svg></span>
      <h3><?= View::t('queue.empty') ?></h3>
      <p><?= View::t('queue.empty_hint_1') ?> <a href="/workflows"><?= View::t('dash.a_workflow') ?></a> <?= View::t('queue.empty_hint_2') ?></p>
    </div>
    <?php else: ?>
    <ul class="job-list">
      <?php foreach ($jobs as $job): ?>
      <li class="job-row">
        <div class="job-row__main">
          <span class="job-row__type"><?= View::e(Messages::jobType((string) $job['type'])) ?> <span class="muted">#<?= (int) $job['id'] ?></span></span>
          <span class="job-row__entity"><?= View::t('common.run_n', ['n' => (int) $job['run_id']]) ?><?php if (isset($job['result']['cost_usd']) && (float) $job['result']['cost_usd'] > 0): ?> · ~$<?= View::e(number_format((float) $job['result']['cost_usd'], 4)) ?><?php endif; ?></span>
          <?php if ($job['status'] === 'failed' && $job['error_message'] !== null): ?>
          <span class="job-row__error"><?= View::e((string) $job['error_message']) ?><?php if (str_starts_with((string) $job['error_message'], 'non-retryable:')): ?> <?= View::t('queue.no_auto_retry') ?><?php else: ?> <?= View::t('queue.retry_xy', ['x' => $job['retry_count'], 'y' => $job['max_retries']]) ?><?php endif; ?></span>
          <?php elseif ($job['status'] === 'queued' && str_starts_with((string) $job['error_message'], 'deferred:')): ?>
          <span class="job-row__entity"><?= View::t('queue.held_by_guardrail') ?> <?= View::e((string) $job['error_message']) ?> · <?= View::t('queue.retries_at') ?> <?= View::e(Format::utcTime((string) $job['run_after'])) ?> UTC</span>
          <?php endif; ?>
        </div>
        <span class="chip chip--<?= Format::statusTone((string) $job['status']) ?>"><span class="dot dot--<?= Format::statusTone((string) $job['status']) ?>"></span><?= View::e(Messages::status((string) $job['status'])) ?></span>
        <?php if ($job['status'] === 'failed'): ?>
        <form method="post" action="/queue/job/<?= (int) $job['id'] ?>/retry">
          <?= $csrfField ?>
          <button type="submit" class="btn btn--ghost btn--sm"><?= View::t('queue.retry') ?></button>
        </form>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('queue.runs') ?></h2></div>
  <div class="card__body">
    <?php if ($runs === []): ?>
    <p class="muted"><?= View::t('queue.no_runs') ?></p>
    <?php else: ?>
    <ul class="job-list">
      <?php foreach ($runs as $run): ?>
      <li class="job-row">
        <div class="job-row__main">
          <span class="job-row__type"><?= View::t('common.run_n', ['n' => (int) $run['id']]) ?> — <?= View::e($run['workflow_name']) ?></span>
          <span class="job-row__entity"><?= $run['current_node'] !== null ? View::t('dash.at') . ' ' . View::e(Messages::node((string) $run['current_node'])) : '' ?></span>
        </div>
        <span class="chip chip--<?= Format::statusTone((string) $run['status']) ?>"><span class="dot dot--<?= Format::statusTone((string) $run['status']) ?>"></span><?= View::e(Messages::status((string) $run['status'])) ?></span>
        <a class="btn btn--ghost btn--sm" href="/runs/<?= (int) $run['id'] ?>"><?= View::t('dash.timeline') ?></a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
