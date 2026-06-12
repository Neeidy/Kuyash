<?php

declare(strict_types=1);

use Kuyash\Core\View;

/** @var list<array<string, mixed>> $workflows */
?>
<div class="screen-head">
  <div>
    <h1>Workflows</h1>
    <p class="screen-sub">The two canonical pipelines. The node track is read-only in Phase 4 —
      settings editing arrives with the real engines.</p>
  </div>
</div>

<?php if ($workflows === []): ?>
<div class="ui-state">
  <span class="ui-state__icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="1.5" y="5" width="4.5" height="6" rx="1"/><rect x="10" y="5" width="4.5" height="6" rx="1"/><path d="M6 8h4"/></svg></span>
  <h3>No workflows</h3>
  <p>The two default workflows are created automatically on first visit.</p>
</div>
<?php else: ?>
<div class="wf-grid">
  <?php foreach ($workflows as $wf): ?>
  <a class="wf-card" href="/workflows/<?= (int) $wf['id'] ?>">
    <div class="wf-card__top">
      <h3><?= View::e($wf['name']) ?></h3>
      <span class="chip chip--neutral mono"><?= View::e($wf['template']) ?></span>
    </div>
    <p class="wf-card__desc">
      <?= $wf['template'] === 'distribution'
          ? 'Distribute an existing library video: captions, hashtags, compliance, publish.'
          : 'Full pipeline from trend research to publish.' ?>
    </p>
    <div class="wf-card__meta">
      <span class="chip chip--faint num"><?= count($wf['nodes']) ?> nodes</span>
      <span class="chip chip--info">COMPLIANCE locked</span>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
