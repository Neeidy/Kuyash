<?php

declare(strict_types=1);

use Kuyash\Core\Format;
use Kuyash\Core\View;

/** @var array<string, mixed> $asset */
/** @var bool $isAvatar this asset is the workspace default avatar */
/** @var string $csrfField trusted generated HTML */
/** @var list<array{type: string, text: string}> $flashes */

$mediaUrl = '/media/' . (int) $asset['id'];
?>
<div class="screen-head">
  <div>
    <h1><?= View::e($asset['title']) ?></h1>
    <p class="screen-sub mono"><?= View::e($asset['original_filename']) ?></p>
  </div>
  <div class="screen-head__actions">
    <a class="btn btn--ghost btn--sm" href="/library">Back to library</a>
  </div>
</div>

<div class="show-grid">
  <div>
    <?php if ($asset['kind'] === 'video'): ?>
    <video class="media-preview" src="<?= View::e($mediaUrl) ?>" controls preload="metadata"></video>
    <?php else: ?>
    <img class="media-preview" src="<?= View::e($mediaUrl) ?>" alt="<?= View::e($asset['title']) ?>">
    <?php endif; ?>

    <?php if ($asset['aspect'] !== null && $asset['aspect'] !== '9:16'): ?>
    <div class="callout callout--warn">
      <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2L1.5 13.5h13z"/><path d="M8 6.5V10M8 12h.01"/></svg></span>
      <div><strong>Not 9:16.</strong> This asset is <?= View::e($asset['aspect']) ?> —
      vertical 9:16 performs best on Reels, TikTok and Shorts.</div>
    </div>
    <?php elseif ($asset['aspect'] === null): ?>
    <div class="callout">
      <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6.5"/><path d="M8 7.5V11M8 5h.01"/></svg></span>
      <div>Dimensions could not be read from this file. It is still usable;
      exact metadata arrives with the render pipeline (Phase 7).</div>
    </div>
    <?php endif; ?>

    <?php if ($asset['ai_label_required']): ?>
    <div class="callout callout--ai">
      <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 1.5l1.8 4.7L14.5 8l-4.7 1.8L8 14.5 6.2 9.8 1.5 8l4.7-1.8z"/></svg></span>
      <div><strong>AI label required.</strong> Realistic AI media must carry the
      platform AI label — set automatically at publish time.</div>
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card__head"><h2>Details</h2>
      <div class="card__action card__action--group">
        <?php if ($isAvatar): ?>
        <span class="chip chip--info">workspace avatar</span>
        <form method="post" action="/library/avatar/clear">
          <?= $csrfField ?>
          <button type="submit" class="btn btn--ghost btn--sm">Clear avatar</button>
        </form>
        <?php else: ?>
        <form method="post" action="/library/asset/<?= (int) $asset['id'] ?>/avatar">
          <?= $csrfField ?>
          <button type="submit" class="btn btn--ghost btn--sm" title="Use as the default reference subject for face-format runs">Set as avatar</button>
        </form>
        <?php endif; ?>
        <form method="post" action="/library/asset/<?= (int) $asset['id'] ?>/delete"
              data-confirm="Delete this asset permanently? The file is removed from disk.">
          <?= $csrfField ?>
          <button type="submit" class="btn btn--danger-ghost btn--sm">Delete</button>
        </form>
      </div>
    </div>
    <div class="card__body">
      <dl class="kv-list">
        <div class="kv"><dt>Type</dt><dd><?= View::e($asset['type']) ?></dd></div>
        <div class="kv"><dt>Kind</dt><dd><?= View::e($asset['kind']) ?></dd></div>
        <div class="kv"><dt>Status</dt><dd><?= View::e($asset['status']) ?></dd></div>
        <div class="kv"><dt>Duration</dt><dd><?= View::e(Format::duration($asset['duration_s'], precise: true)) ?></dd></div>
        <div class="kv"><dt>Dimensions</dt><dd><?= $asset['width'] !== null ? View::e($asset['width'] . ' × ' . $asset['height']) : 'unknown' ?></dd></div>
        <div class="kv"><dt>Aspect</dt><dd><?= View::e($asset['aspect'] ?? 'unknown') ?></dd></div>
        <div class="kv"><dt>Size</dt><dd><?= View::e(Format::bytes((int) $asset['size_bytes'])) ?></dd></div>
        <div class="kv"><dt>MIME</dt><dd><?= View::e($asset['mime']) ?></dd></div>
        <div class="kv"><dt>SHA-256</dt><dd title="<?= View::e($asset['sha256']) ?>"><?= View::e(substr((string) $asset['sha256'], 0, 16)) ?>…</dd></div>
        <div class="kv"><dt>Uploaded</dt><dd><?= View::e($asset['created_at']) ?></dd></div>
      </dl>
      <?php if ($asset['tags'] !== []): ?>
      <div class="tag-row">
        <?php foreach ($asset['tags'] as $tag): ?>
        <span class="tag"><?= View::e($tag) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <p class="note">Used-in tracking arrives with the workflow engine (Phase 4).</p>
    </div>
  </div>
</div>
<script src="/assets/js/library.js" defer></script>
