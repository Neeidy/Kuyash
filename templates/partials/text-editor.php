<?php

declare(strict_types=1);

use Kuyash\Core\Messages;
use Kuyash\Core\View;

/**
 * Editing a post's text before it goes out (Phase 25).
 *
 * TWO THINGS THAT LOOK ALIKE BUT ARE NOT: the BODY is yours to write; the AI
 * notice underneath is not. It is shown so nobody is surprised by it, and it is
 * rendered as plain text — never as a field — because it is added at publish
 * time around whatever you wrote. There is nothing here to delete it with.
 *
 * Works with scripting off: the counters are progressive enhancement, the form
 * is an ordinary POST.
 *
 * @var array<string, mixed> $text     ContentRevision::forRun() output
 * @var array<string, mixed> $limits   platform → PlatformLimits::measure()
 * @var string               $disclosureLine '' when none applies
 * @var int                  $runId
 * @var string               $backTo   'queue' | 'run'
 * @var bool                 $withHeading false when the surrounding card already has the title
 * @var bool                 $showBadge   true where no surrounding card carries the compliance chip
 * @var array|null           $generatedCompliance ['status'=>…, 'slop'=>…] — used only when there is no edit
 * @var string               $csrfField trusted generated HTML
 */
$editable = (bool) $text['editable'];
$edit = $text['edit'] ?? null;
$heading = ($withHeading ?? true) === true;
// The run screen has no approval card to hang the compliance chip on, so it is
// shown here, beside the words it is actually about. The queue card renders its
// own in the meta row and passes false, so nothing is said twice.
//
// It is shown for EVERY run, not only edited ones. An edit changes which verdict
// applies, never whether the post was checked — so a chip that appeared only
// after someone edited would make "was this checked?" read as a consequence of
// editing. With no edit, the generated verdict is still exactly the right answer.
$badge = null;
if (($showBadge ?? false) === true) {
    $badge = $text['badge'] ?? null;
    if ($badge === null && is_array($generatedCompliance ?? null)) {
        $genStatus = (string) ($generatedCompliance['status'] ?? '');
        $genSlop = $generatedCompliance['slop'] ?? null;
        $badge = $genStatus === '' ? null : [
            'status' => $genStatus,
            'slop' => is_numeric($genSlop) ? (float) $genSlop : null,
            // an unedited `warn` can only come from slop (ComplianceCheckExecutor),
            // but a missing score still must not print a meaningless 0.00
            'similar' => is_numeric($genSlop),
            'edited' => false,
        ];
    } elseif ($badge !== null) {
        $badge['edited'] = true;
    }
}
$showHead = $heading || $text['edited'] || $badge !== null
    || (($text['edited_after_approval'] ?? false) === true && $edit !== null);

// The tag field is shared across platforms, so the number that matters is the
// tightest limit any of them imposes — a count against the most generous one
// would say "fine" while a real platform refuses it.
$tagsLimit = 0;
foreach ($limits as $m) {
    if (($m['known'] ?? false) && (int) ($m['tags_limit'] ?? 0) > 0) {
        $tagsLimit = $tagsLimit === 0 ? (int) $m['tags_limit'] : min($tagsLimit, (int) $m['tags_limit']);
    }
}
$tagsOver = $tagsLimit > 0 && count($text['hashtags']) > $tagsLimit;
$tagsNearAt = 0;
foreach ($limits as $m) {
    if ((int) ($m['tags_limit'] ?? 0) === $tagsLimit && (int) ($m['near_tags_at'] ?? 0) > 0) {
        $tagsNearAt = (int) $m['near_tags_at'];
        break;
    }
}
$tagsNear = !$tagsOver && $tagsNearAt > 0 && count($text['hashtags']) >= $tagsNearAt;

// Platforms that carry the notice as a NATIVE flag — worth saying once, since
// the screen otherwise looks as though only Instagram is labelled. The list is
// the effective one (requirement AND that platform's own toggle), never every
// platform on screen: naming one whose switch is off would be a false assurance.
$otherPlatforms = (array) ($text['native_disclosure'] ?? []);
?>
<?php /* A page rendered WITH a held draft starts out already differing from the
         database, so the dirty flag is armed from the server rather than from a
         keystroke that has not happened. Without it, the operator who was just
         refused — looking at their own words in the boxes — could click Approve
         with no confirm, or navigate away with no warning. */ ?>
<div class="textedit<?= $heading ? '' : ' textedit--bare' ?>"<?= ($text['unsaved'] ?? false) === true ? ' data-dirty="1"' : '' ?>>
  <?php /* the run screen already titles the card it puts this in; repeating the
           heading there just says the same words twice — and an empty head bar
           left a dead band above the fields */ ?>
  <?php if ($showHead): ?>
  <div class="textedit__head">
    <?php if ($heading): ?>
    <h3><?= View::t('content.card') ?></h3>
    <?php endif; ?>
    <?php if ($text['edited']): ?>
    <span class="chip chip--neutral"><?= View::t('content.edited_badge') ?></span>
    <?php endif; ?>
    <?php if (($text['edited_after_approval'] ?? false) === true && $edit !== null): ?>
    <?php /* the approval was a real decision about a real moment — it is not
             rewritten. The fact that the text moved afterwards is shown as its
             own, separate fact. */ ?>
    <span class="chip chip--warn"><?= View::t('content.edited_after_approval', ['user' => (string) ($edit['by_email'] ?? '')]) ?></span>
    <?php endif; ?>
    <?php if ($badge !== null && $badge['status'] === 'warn' && $badge['similar']): ?>
    <span class="chip chip--warn chip--wrap"><span class="dot"></span><?= View::t($badge['edited'] ? 'queue.similarity_edited' : 'queue.similarity', ['score' => number_format((float) $badge['slop'], 2)]) ?></span>
    <?php elseif ($badge !== null && ($badge['status'] === 'pass' || $badge['status'] === 'pass_with_ai_label')): ?>
    <span class="chip chip--ok"><span class="dot"></span><?= View::t($badge['edited'] ? 'queue.compliance_pass_edited' : 'queue.compliance_pass') ?></span>
    <?php elseif ($badge !== null && $badge['status'] === 'block'): ?>
    <?php /* a block is not "one thing to check" — it stopped the run, and the
             record screen must not soften that into a note */ ?>
    <span class="chip chip--err"><span class="dot"></span><?= View::t('queue.checks_blocked') ?></span>
    <?php elseif ($badge !== null): ?>
    <?php /* warned about something other than similarity, or a status this
             screen does not know — never silently drop the chip, and never
             attribute it to an edit that did not happen */ ?>
    <span class="chip chip--warn chip--wrap"><span class="dot"></span><?= View::t($badge['edited'] ? 'queue.checks_note_edited' : 'queue.checks_note') ?></span>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php /* ONE sentence for why it is locked. Two of them contradicted each
           other, and the second claimed a publication that a cancelled run
           never made. */ ?>
  <?php if (!$editable): ?>
  <p class="field__hint"><?= View::t('content.locked_' . ($text['locked_reason'] ?? 'publishing')) ?></p>
  <?php endif; ?>

  <?php /* the boxes below hold text that was refused and never stored — say so
           where the text is, because everything else on the screen (the chip,
           the counts, the "what goes out" wording) describes what IS stored */ ?>
  <?php if (($text['unsaved'] ?? false) === true): ?>
  <p class="textedit__unsaved"><?= View::t('content.unsaved_flash') ?></p>
  <?php endif; ?>

  <?php /* a warning the gate raised the last time this was saved, still true */ ?>
  <?php if ($edit !== null && ($edit['verdict']['warnings'] ?? []) !== []): ?>
  <div class="callout callout--warn textedit__warn">
    <?php foreach ((array) $edit['verdict']['warnings'] as $w): ?>
    <?php
      // the slug the code uses is not what a person should read
      $wp = (array) ($w['params'] ?? []);
      if (isset($wp['platform']) && is_string($wp['platform'])) {
          $wp['platform'] = Messages::platform($wp['platform']);
      }
    ?>
    <p><?= View::t((string) $w['key'], $wp) ?></p>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="post" action="/runs/<?= (int) $runId ?>/text" class="textedit__form">
    <?= $csrfField ?>
    <input type="hidden" name="content_hash" value="<?= View::e((string) $text['hash']) ?>">
    <input type="hidden" name="back" value="<?= View::e($backTo) ?>">

    <?php foreach ($text['captions'] as $platform => $body): ?>
    <?php
      $m = $limits[$platform] ?? null;
      $countId = 'tx' . (int) $runId . '-' . preg_replace('/[^a-z0-9]/', '', strtolower((string) $platform));
      $showsNotice = $platform === 'instagram' && $disclosureLine !== '';
      $noticeOff = $platform === 'instagram' && ($text['disclosure_suppressed'] ?? false) === true;
    ?>
    <label class="field textedit__field">
      <span class="field__label"><?= View::t('content.body_for', ['platform' => Messages::platform((string) $platform)]) ?></span>
      <?php /* data-* carry the SAME composition rule the server measured with —
               body, plus the notice where one applies, plus the tag block — so a
               live count can never disagree with the saved one. */ ?>
      <textarea name="caption[<?= View::e((string) $platform) ?>]" rows="4"
                data-count-for="<?= View::e((string) $platform) ?>"
                data-limit="<?= (int) ($m['chars_limit'] ?? 0) ?>"
                data-disclosure="<?= View::e($showsNotice ? $disclosureLine : '') ?>"
                aria-describedby="<?= $countId ?>"
                <?= $editable ? '' : 'readonly' ?>><?= View::e((string) $body) ?></textarea>
    </label>
    <?php if ($platform === 'youtube' && $editable): ?>
    <?php /* the adapter derives the video title from this field's first line —
             an operator rewriting the caption is also renaming the video, and
             nothing else on the screen says so. Advice, so it belongs only where
             there is still something to decide: on a finished post the title was
             set long ago. */ ?>
    <p class="field__hint"><?= View::t('content.youtube_title') ?></p>
    <?php endif; ?>
    <?php if ($showsNotice): ?>
    <?php /* NOT an input, and placed with the field it belongs to — it is
             composed at publish time around the body above, so an edit cannot
             carry it away. */ ?>
    <p class="textedit__locked">
      <span class="icon" aria-hidden="true"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3.5" y="7" width="9" height="6" rx="1"/><path d="M5.5 7V5a2.5 2.5 0 0 1 5 0v2"/></svg></span>
      <strong><?= View::e($disclosureLine) ?></strong>
      <span class="muted"><?= View::t('content.disclosure_locked', ['platform' => Messages::platform((string) $platform)]) ?></span>
    </p>
    <?php elseif ($noticeOff): ?>
    <?php /* required, but switched off in Settings — said plainly rather than
             leaving the screen looking as though nothing was ever needed */ ?>
    <p class="textedit__locked textedit__locked--off"><span class="muted"><?= View::t('content.disclosure_off') ?></span></p>
    <?php endif; ?>
    <p class="textedit__count<?= ($m['over_chars'] ?? false) ? ' textedit__count--over' : (($m['near_chars'] ?? false) ? ' textedit__count--near' : '') ?>"
       id="<?= $countId ?>"
       data-count-of="<?= View::e((string) $platform) ?>"
       data-near="<?= (int) ($m['near_chars_at'] ?? 0) ?>"
       data-t-known="<?= View::t('content.chars', ['n' => '{n}', 'limit' => (int) ($m['chars_limit'] ?? 0)]) ?>"
       data-t-unknown="<?= View::t('content.chars_unknown', ['n' => '{n}']) ?>">
      <?= ($m !== null && $m['known'])
          ? View::t('content.chars', ['n' => (int) $m['chars'], 'limit' => (int) $m['chars_limit']])
          : View::t('content.chars_unknown', ['n' => (int) ($m['chars'] ?? 0)]) ?>
    </p>
    <?php endforeach; ?>

    <?php if ($otherPlatforms !== []): ?>
    <?php
      // "TikTok, YouTube" reads like a truncated list; a sentence names them
      // the way a person would.
      $names = array_map(static fn ($p): string => Messages::platform((string) $p), $otherPlatforms);
      $last = array_pop($names);
      $platformList = $names === [] ? $last : implode(', ', $names) . ' ' . View::t('common.list_and') . ' ' . $last;
    ?>
    <p class="field__hint"><?= View::t('content.disclosure_other', ['platforms' => $platformList]) ?></p>
    <?php endif; ?>

    <label class="field textedit__field textedit__tags">
      <span class="field__label"><?= View::t('content.tags_label') ?></span>
      <?php /* a textarea, not a single-line input: at 375px an input cut tags
               off mid-word with no way to see the rest */ ?>
      <textarea name="hashtags" rows="2" data-count-tags
                aria-describedby="tx<?= (int) $runId ?>-tags"
                <?= $editable ? '' : 'readonly' ?>><?= View::e(implode(' ', $text['hashtags'])) ?></textarea>
    </label>
    <p class="textedit__count<?= $tagsOver ? ' textedit__count--over' : ($tagsNear ? ' textedit__count--near' : '') ?>"
       id="tx<?= (int) $runId ?>-tags"
       data-count-tags-of data-limit="<?= $tagsLimit ?>" data-near="<?= $tagsNearAt ?>"
       data-t-known="<?= View::t('content.tags_count', ['n' => '{n}', 'limit' => $tagsLimit]) ?>"
       data-t-unknown="<?= View::t('content.tags_count_unknown', ['n' => '{n}']) ?>">
      <?= $tagsLimit > 0
          ? View::t('content.tags_count', ['n' => count($text['hashtags']), 'limit' => $tagsLimit])
          : View::t('content.tags_count_unknown', ['n' => count($text['hashtags'])]) ?>
    </p>
    <?php /* how to type tags is only worth saying while they can still be typed */ ?>
    <?php if ($editable): ?>
    <p class="field__hint"><?= View::t('content.tags_hint') ?></p>
    <?php endif; ?>
    <?php /* the numbers above count the assembled post — body, notice, tags —
             not just what is in the box you are looking at. A post with no
             notice must not be told its count includes one. */ ?>
    <p class="field__hint"><?= View::t($disclosureLine === '' ? 'content.count_note_plain' : 'content.count_note') ?></p>

    <?php if ($editable): ?>
    <div class="textedit__actions">
      <button type="submit" class="btn btn--primary btn--sm"><?= View::t('content.save') ?></button>
    </div>
    <?php endif; ?>
  </form>

  <?php if ($editable && $text['edited'] && $text['captions_ai'] !== []): ?>
  <form method="post" action="/runs/<?= (int) $runId ?>/text/restore"
        data-confirm="<?= View::t('content.restore_confirm') ?>">
    <?= $csrfField ?>
    <input type="hidden" name="back" value="<?= View::e($backTo) ?>">
    <button type="submit" class="btn btn--ghost btn--sm"><?= View::t('content.restore') ?></button>
  </form>
  <?php endif; ?>
</div>
