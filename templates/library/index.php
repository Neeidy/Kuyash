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

// labels already escaped by View::t — echoed raw below
$typeChips = [
    '' => View::t('library.type_all'),
    'own' => View::t('library.type_own'),
    'face' => View::t('library.type_face'),
    'stock' => View::t('library.type_stock'),
    'ai' => View::t('library.type_ai'),
];
// color is reserved for STATUS — every non-AI type chip stays neutral
$chipClassFor = static fn (string $t): string => $t === 'ai' ? 'chip chip--ai' : 'chip chip--neutral';
?>
<div class="screen-head">
  <div>
    <h1><?= View::t('library.title') ?></h1>
    <p class="screen-sub"><?= View::t('library.subtitle') ?></p>
  </div>
  <div class="screen-head__actions">
    <span class="chip chip--neutral num"><?= count($items) ?> <?= count($items) === 1 ? View::t('library.asset_one') : View::t('library.asset_many') ?></span>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('library.upload') ?></h2></div>
  <div class="card__body">
    <form method="post" action="/library/upload" enctype="multipart/form-data"
          data-max-video="<?= $maxVideoBytes ?>" data-max-photo="<?= $maxPhotoBytes ?>">
      <?= $csrfField ?>
      <label class="upload-box">
        <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 11V3M4.5 6.5L8 3l3.5 3.5M2.5 13h11"/></svg></span>
        <span data-file-label><?= View::t('library.choose_file', [
            'video' => $videoLabel, 'maxv' => $maxVideoLabel,
            'photo' => $photoLabel, 'maxp' => $maxPhotoLabel,
        ]) ?></span>
        <input type="file" name="file" accept="<?= View::e($acceptAttr) ?>" required>
      </label>
      <p class="note text-warn" data-size-warning hidden role="alert"><?= View::t('library.size_warning') ?></p>
      <div class="field-row">
        <label class="field"><span><?= View::t('library.title_field') ?></span>
          <input type="text" name="title" maxlength="120" placeholder="<?= View::t('library.title_placeholder') ?>">
        </label>
        <label class="field"><span><?= View::t('library.type_field') ?></span>
          <select name="type">
            <option value="own"><?= View::t('library.opt_own') ?></option>
            <option value="face"><?= View::t('library.opt_face') ?></option>
          </select>
        </label>
        <label class="field"><span><?= View::t('library.tags_field') ?></span>
          <input type="text" name="tags" placeholder="<?= View::t('library.tags_placeholder') ?>">
        </label>
        <button type="submit" class="btn btn--primary"><?= View::t('library.upload_btn') ?></button>
      </div>
      <p class="note"><?= View::t('library.upload_note') ?></p>
    </form>
  </div>
</div>

<div class="filter-row">
  <form method="get" action="/library" class="search">
    <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="7" cy="7" r="4.5"/><path d="M13.5 13.5l-3.2-3.2"/></svg></span>
    <input type="search" name="q" value="<?= View::e($q) ?>" placeholder="<?= View::t('library.search_placeholder') ?>"
           aria-label="<?= View::t('library.search_aria') ?>">
    <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?= View::e($type) ?>"><?php endif; ?>
  </form>
  <div class="chip-row">
    <?php foreach ($typeChips as $t => $label): ?>
    <a class="fchip<?= $type === $t ? ' is-active' : '' ?>"
       href="/library<?= ($t !== '' || $q !== '') ? '?' . http_build_query(array_filter(['type' => $t, 'q' => $q], static fn ($v) => $v !== '')) : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($items === []): ?>
<div class="ui-state">
  <span class="ui-state__icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="1.5" y="2.5" width="13" height="11" rx="1.5"/><path d="M4.5 2.5v11M11.5 2.5v11M1.5 6h3M1.5 10h3M11.5 6h3M11.5 10h3"/></svg></span>
  <h3><?= ($q !== '' || $type !== '') ? View::t('library.empty_filtered') : View::t('library.empty') ?></h3>
  <p><?= ($q !== '' || $type !== '') ? View::t('library.empty_filtered_hint') : View::t('library.empty_hint') ?></p>
  <?php if ($q !== '' || $type !== ''): ?><a class="btn btn--ghost btn--sm" href="/library"><?= View::t('library.clear_filters') ?></a><?php endif; ?>
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
        <span class="chip chip--faint"><?= View::t('library.aspect_unknown') ?></span>
        <?php endif; ?>
        <?php if ($item['ai_label_required']): ?>
        <span class="chip chip--ai"><?= View::t('library.ai_label') ?></span>
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
