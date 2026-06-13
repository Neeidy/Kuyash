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
    <h1>Quick Create</h1>
    <p class="screen-sub">Animate a photo into a <strong>15–45&nbsp;s vertical (9:16)</strong> AI clip, then send it
      straight through compliance and publishing. No trend, script or voice — your prompt is the brief.</p>
  </div>
  <div class="screen-head__actions">
    <span class="chip chip--neutral num" title="Estimated credit cost before this run starts">~<?= View::e(Format::cents($estimateCents)) ?> est.</span>
  </div>
</div>

<div class="callout callout--ai" role="note">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6.5"/><path d="M8 5v3.5M8 11h.01"/></svg></span>
  <div><strong>This is realistic AI media.</strong> The platform <strong>AI label</strong> is set automatically on
    publish — it cannot be turned off (compliance rule). Generation is credit-gated: an over-budget run is blocked
    before it starts (see <a href="/usage">Usage</a>).</div>
</div>

<form class="card" method="post" action="/quick" enctype="multipart/form-data">
  <?= $csrfField ?>
  <div class="card__head"><h2>Photo &amp; prompt</h2>
    <span class="card__action"><span class="chip chip--ai">AI label always on</span></span>
  </div>
  <div class="card__body">

    <label class="field">
      <span class="field__label">Motion prompt</span>
      <span class="field__hint">Required · max <?= (int) $maxPrompt ?> characters.</span>
      <textarea name="prompt" rows="3" maxlength="<?= (int) $maxPrompt ?>" required
        placeholder="e.g. slow cinematic push-in, soft daylight, gentle parallax"></textarea>
    </label>

    <label class="field">
      <span class="field__label">Upload a photo</span>
      <span class="field__hint"><?= View::e($photoLabel) ?> · up to <?= View::e($maxPhotoLabel) ?>. Leave this empty to use a picked photo below.</span>
      <input type="file" name="photo" accept="<?= View::e($acceptAttr) ?>">
    </label>

    <?php if ($photos !== []): ?>
    <fieldset class="field" role="radiogroup" aria-label="Pick a photo from your library">
      <span class="field__label">…or pick a photo from your library</span>
      <span class="field__hint">An uploaded file (above) always takes precedence over a picked photo.</span>
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
    <p class="note">No photos in your library yet — upload one above, or add some in the
      <a href="/library">Library</a>.</p>
    <?php endif; ?>

  </div>
  <div class="card__foot">
    <span class="muted">The clip lands in the <a href="/queue">queue</a> for your approval before it publishes.</span>
    <button type="submit" class="btn btn--primary">Generate AI clip</button>
  </div>
</form>
