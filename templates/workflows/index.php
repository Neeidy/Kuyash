<?php

declare(strict_types=1);

use Kuyash\Core\View;

/** @var list<array<string, mixed>> $workflows */
?>
<div class="screen-head">
  <div>
    <h1><?= View::t('wf.title') ?></h1>
    <p class="screen-sub"><?= View::t('wf.subtitle') ?></p>
  </div>
</div>

<?php if ($workflows === []): ?>
<div class="ui-state">
  <span class="ui-state__icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="1.5" y="5" width="4.5" height="6" rx="1"/><rect x="10" y="5" width="4.5" height="6" rx="1"/><path d="M6 8h4"/></svg></span>
  <h3><?= View::t('wf.empty') ?></h3>
  <p><?= View::t('wf.empty_hint') ?></p>
</div>
<?php else: ?>
<div class="wf-grid">
  <?php foreach ($workflows as $wf): ?>
  <a class="wf-card" href="/workflows/<?= (int) $wf['id'] ?>">
    <div class="wf-card__top">
      <h3><?= View::e($wf['name']) ?></h3>
    </div>
    <p class="wf-card__desc">
      <?= $wf['template'] === 'distribution' ? View::t('wf.desc_distribution') : View::t('wf.desc_full') ?>
    </p>
    <div class="wf-card__meta">
      <span class="chip chip--faint num"><?= View::t('wf.n_nodes', ['n' => count($wf['nodes'])]) ?></span>
      <span class="chip chip--info"><?= View::t('wf.compliance_locked') ?></span>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
