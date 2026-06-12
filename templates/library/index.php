<?php

declare(strict_types=1);

use Kuyash\Core\Format;
use Kuyash\Core\View;

/** @var list<array<string, mixed>> $items */
/** @var string $q */
/** @var string $type */
/** @var string $csrfField trusted generated HTML */
/** @var list<array{type: string, text: string}> $flashes */
/** @var int $maxVideoBytes */
/** @var int $maxPhotoBytes */
/** @var string $acceptAttr  derived from config allowlist */
/** @var string $videoLabel */
/** @var string $photoLabel */
/** @var string $maxVideoLabel */
/** @var string $maxPhotoLabel */

$typeChips = ['' => 'All', 'own' => 'Own', 'face' => 'Face', 'stock' => 'Stock', 'ai' => 'AI'];
// color is reserved for STATUS — every non-AI type chip stays neutral
$chipClassFor = static fn (string $t): string => $t === 'ai' ? 'chip chip--ai' : 'chip chip--neutral';
?>
<div class="screen-head">
  <div>
    <h1>Content Library</h1>
    <p class="screen-sub">Own and face clips for the LIBRARY visuals source.
      Stock and AI assets arrive in later phases.</p>
  </div>
  <div class="screen-head__actions">
    <span class="chip chip--neutral num"><?= count($items) ?> asset<?= count($items) === 1 ? '' : 's' ?></span>
  </div>
</div>

<?php foreach ($flashes as $flash): ?>
<div class="callout callout--banner callout--<?= $flash['type'] === 'success' ? 'ok' : 'err' ?>"
     role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6.5"/><?= $flash['type'] === 'success' ? '<path d="M5.2 8.4l1.9 1.9 3.7-4.4"/>' : '<path d="M8 5v3.5M8 11h.01"/>' ?></svg></span>
  <div><?= View::e($flash['text']) ?></div>
</div>
<?php endforeach; ?>

<div class="card">
  <div class="card__head"><h2>Upload</h2></div>
  <div class="card__body">
    <form method="post" action="/library/upload" enctype="multipart/form-data"
          data-max-video="<?= $maxVideoBytes ?>" data-max-photo="<?= $maxPhotoBytes ?>">
      <?= $csrfField ?>
      <label class="upload-box">
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 11V3M4.5 6.5L8 3l3.5 3.5M2.5 13h11"/></svg></span>
        <span data-file-label>Choose a file — <?= View::e($videoLabel) ?> video (≤<?= View::e($maxVideoLabel) ?>)
          or <?= View::e($photoLabel) ?> photo (≤<?= View::e($maxPhotoLabel) ?>)</span>
        <input type="file" name="file" accept="<?= View::e($acceptAttr) ?>" required>
      </label>
      <p class="note text-warn" data-size-warning hidden role="alert">That file is over the size limit — it will be rejected.</p>
      <div class="field-row">
        <label class="field"><span>Title</span>
          <input type="text" name="title" maxlength="120" placeholder="Defaults to the file name">
        </label>
        <label class="field"><span>Type</span>
          <select name="type">
            <option value="own">Own footage</option>
            <option value="face">Face clip (shooting brief)</option>
          </select>
        </label>
        <label class="field"><span>Tags</span>
          <input type="text" name="tags" placeholder="comma, separated, tags">
        </label>
        <button type="submit" class="btn btn--primary">Upload</button>
      </div>
      <p class="note">Upload 9:16 vertical for best results — other aspect ratios get a format warning.</p>
    </form>
  </div>
</div>

<div class="filter-row">
  <form method="get" action="/library" class="search">
    <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="7" cy="7" r="4.5"/><path d="M13.5 13.5l-3.2-3.2"/></svg></span>
    <input type="search" name="q" value="<?= View::e($q) ?>" placeholder="Search title or tags…"
           aria-label="Search assets by title or tags">
    <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?= View::e($type) ?>"><?php endif; ?>
  </form>
  <div class="chip-row">
    <?php foreach ($typeChips as $t => $label): ?>
    <a class="fchip<?= $type === $t ? ' is-active' : '' ?>"
       href="/library<?= ($t !== '' || $q !== '') ? '?' . http_build_query(array_filter(['type' => $t, 'q' => $q], static fn ($v) => $v !== '')) : '' ?>"><?= View::e($label) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($items === []): ?>
<div class="ui-state">
  <span class="ui-state__icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="1.5" y="2.5" width="13" height="11" rx="1.5"/><path d="M4.5 2.5v11M11.5 2.5v11M1.5 6h3M1.5 10h3M11.5 6h3M11.5 10h3"/></svg></span>
  <h3><?= ($q !== '' || $type !== '') ? 'No assets match' : 'The library is empty' ?></h3>
  <p><?= ($q !== '' || $type !== '')
      ? 'Try a different search or clear the type filter.'
      : 'Upload your first clip or photo above — it becomes available to workflows as the LIBRARY visuals source.' ?></p>
  <?php if ($q !== '' || $type !== ''): ?><a class="btn btn--ghost btn--sm" href="/library">Clear filters</a><?php endif; ?>
</div>
<?php else: ?>
<div class="asset-grid">
  <?php foreach ($items as $i => $item): ?>
  <a class="asset-card" href="/library/asset/<?= (int) $item['id'] ?>" style="--i:<?= min($i, 7) ?>">
    <div class="asset-thumb">
      <?php if ($item['kind'] === 'photo'): ?>
      <img src="/media/<?= (int) $item['id'] ?>" alt="" loading="lazy">
      <?php else: ?>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" width="26" height="26"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M10 9.5l5 2.5-5 2.5z"/></svg>
      <?php endif; ?>
      <?php if ($item['duration_s'] !== null): ?>
      <span class="asset-thumb__dur"><?= View::e(Format::duration($item['duration_s'])) ?></span>
      <?php endif; ?>
    </div>
    <div class="asset-card__body">
      <h3><?= View::e($item['title']) ?></h3>
      <div class="asset-card__meta">
        <span class="<?= $chipClassFor((string) $item['type']) ?>"><?= View::e($item['type']) ?></span>
        <span class="chip chip--neutral"><?= View::e($item['kind']) ?></span>
        <?php if ($item['aspect'] === '9:16'): ?>
        <span class="chip chip--ok">9:16</span>
        <?php elseif ($item['aspect'] !== null): ?>
        <span class="chip chip--warn"><?= View::e($item['aspect']) ?></span>
        <?php else: ?>
        <span class="chip chip--faint">aspect unknown</span>
        <?php endif; ?>
        <?php if ($item['ai_label_required']): ?>
        <span class="chip chip--ai">AI label</span>
        <?php endif; ?>
      </div>
      <?php if ($item['tags'] !== []): ?>
      <div class="tag-row">
        <?php foreach (array_slice($item['tags'], 0, 4) as $tag): ?>
        <span class="tag"><?= View::e($tag) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<script src="/assets/js/library.js" defer></script>
