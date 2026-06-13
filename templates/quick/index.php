<?php

declare(strict_types=1);

use Kuyash\Core\Format;
use Kuyash\Core\View;

/** @var string $csrfField */
/** @var list<array<string,mixed>> $photos   ready photo assets */
/** @var int $estimateCents                   pre-flight estimate for a quick_create run */
/** @var int $maxPrompt                        prompt character cap */
/** @var string $photoLabel                    accepted photo extensions (JPG/PNG/…) */
/** @var string $maxPhotoLabel                  human max photo size */
/** @var string $acceptAttr                     <input accept> value */
?>
<div class="screen-head">
  <div>
    <h1><?= View::t('quick.title') ?></h1>
    <p class="screen-sub"><?= View::t('quick.subtitle_1') ?> <strong><?= View::t('quick.subtitle_strong') ?></strong> <?= View::t('quick.subtitle_2') ?></p>
  </div>
  <div class="screen-head__actions">
    <span class="chip chip--neutral num" title="<?= View::t('quick.est_title') ?>">~<?= View::e(Format::cents($estimateCents)) ?> <?= View::t('quick.est') ?></span>
  </div>
</div>

<div class="callout callout--ai" role="note">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6.5"/><path d="M8 5v3.5M8 11h.01"/></svg></span>
  <div><strong><?= View::t('quick.ai_callout_1') ?></strong> <?= View::t('quick.ai_callout_2') ?> <strong><?= View::t('quick.ai_callout_strong') ?></strong> <?= View::t('quick.ai_callout_3') ?> <a href="/usage"><?= View::t('nav.usage') ?></a><?= View::t('quick.ai_callout_4') ?></div>
</div>

<form class="card" method="post" action="/quick" enctype="multipart/form-data">
  <?= $csrfField ?>
  <div class="card__head"><h2><?= View::t('quick.photo_prompt') ?></h2>
    <span class="card__action"><span class="chip chip--ai"><?= View::t('quick.ai_always_on') ?></span></span>
  </div>
  <div class="card__body">

    <label class="field">
      <span class="field__label"><?= View::t('quick.motion_prompt') ?></span>
      <span class="field__hint"><?= View::t('quick.prompt_hint', ['n' => (int) $maxPrompt]) ?></span>
      <textarea name="prompt" rows="3" maxlength="<?= (int) $maxPrompt ?>" required
        placeholder="<?= View::t('quick.prompt_placeholder') ?>"></textarea>
    </label>

    <label class="field">
      <span class="field__label"><?= View::t('quick.upload_photo') ?></span>
      <span class="field__hint"><?= View::t('quick.upload_hint', ['label' => $photoLabel, 'max' => $maxPhotoLabel]) ?></span>
      <input type="file" name="photo" accept="<?= View::e($acceptAttr) ?>">
    </label>

    <?php if ($photos !== []): ?>
    <fieldset class="field" role="radiogroup" aria-label="<?= View::t('quick.pick_aria') ?>">
      <span class="field__label"><?= View::t('quick.or_pick') ?></span>
      <span class="field__hint"><?= View::t('quick.precedence_hint') ?></span>
      <div class="photo-pick">
        <?php foreach ($photos as $p): ?>
        <label class="photo-pick__item">
          <input type="radio" name="photo_id" value="<?= (int) $p['id'] ?>">
          <img class="photo-pick__thumb" src="/media/<?= (int) $p['id'] ?>" alt="<?= View::e((string) $p['title']) ?>" loading="lazy">
          <span class="photo-pick__title"><?= View::e((string) $p['title']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <?php else: ?>
    <p class="note"><?= View::t('quick.no_photos_1') ?> <a href="/library"><?= View::t('nav.library') ?></a><?= View::t('quick.no_photos_2') ?></p>
    <?php endif; ?>

  </div>
  <div class="card__foot">
    <span class="muted"><?= View::t('quick.foot_1') ?> <a href="/queue"><?= View::t('quick.queue_word') ?></a> <?= View::t('quick.foot_2') ?></span>
    <button type="submit" class="btn btn--primary"><?= View::t('quick.generate') ?></button>
  </div>
</form>
