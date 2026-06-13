<?php

declare(strict_types=1);

use Kuyash\Core\Format;
use Kuyash\Core\View;
use Kuyash\Workflow\Nodes;

/** @var array<string, mixed> $workflow */
/** @var list<array<string, mixed>> $readyVideos ready library videos (distribution only) */
/** @var list<array<string, mixed>> $references ready reference assets (full only) */
/** @var string $csrfField trusted generated HTML */

$isDistribution = $workflow['template'] === Nodes::TEMPLATE_DISTRIBUTION;
?>
<div class="screen-head">
  <div>
    <h1><?= View::e($workflow['name']) ?></h1>
    <p class="screen-sub"><?= View::t('wf.show_subtitle') ?>
      <?= $isDistribution ? View::t('wf.show_sub_distribution') : View::t('wf.show_sub_full') ?></p>
  </div>
  <div class="screen-head__actions">
    <span class="chip chip--neutral mono"><?= View::e($workflow['template']) ?></span>
    <a class="btn btn--ghost btn--sm" href="/workflows"><?= View::t('wf.all_workflows') ?></a>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('wf.start_run') ?></h2></div>
  <div class="card__body">
    <?php if ($isDistribution && $readyVideos === []): ?>
    <div class="callout callout--warn">
      <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2L1.5 13.5h13z"/><path d="M8 6.5V10M8 12h.01"/></svg></span>
      <div><strong><?= View::t('wf.no_videos_title') ?></strong> <?= View::t('wf.no_videos_body_1') ?> <a href="/library"><?= View::t('wf.library_word') ?></a><?= View::t('wf.no_videos_body_2') ?></div>
    </div>
    <?php else: ?>
    <form method="post" action="/workflows/<?= (int) $workflow['id'] ?>/run">
      <?= $csrfField ?>
      <div class="field-row">
        <?php if ($isDistribution): ?>
        <label class="field"><span><?= View::t('wf.library_video') ?></span>
          <select name="asset_id" required>
            <?php foreach ($readyVideos as $video): ?>
            <option value="<?= (int) $video['id'] ?>">
              <?= View::e($video['title']) ?> · <?= View::e(Format::duration($video['duration_s'])) ?><?= $video['aspect'] !== null ? ' · ' . View::e($video['aspect']) : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php elseif ($references !== []): ?>
        <label class="field"><span><?= View::t('wf.reference_subject') ?></span>
          <select name="reference_asset_id">
            <option value=""><?= View::t('wf.auto_reference') ?></option>
            <?php foreach ($references as $ref): ?>
            <option value="<?= (int) $ref['id'] ?>">
              <?= View::e($ref['title']) ?> · <?= View::e($ref['kind']) ?><?= $ref['type'] === 'ai' ? ' · AI' : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php endif; ?>
        <button type="submit" class="btn btn--primary"><?= View::t('wf.start_run_btn') ?></button>
      </div>
      <p class="note"><?= $isDistribution ? View::t('wf.note_distribution') : View::t('wf.note_full') ?>
        <?= View::t('wf.note_publish') ?></p>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('runs.node_track') ?></h2></div>
  <div class="card__body">
    <div class="wf-canvas">
      <div class="node-track">
        <?php foreach ($workflow['nodes'] as $node): ?>
        <div class="node-wrap">
          <div class="node<?= ($node['locked'] ?? false) ? ' node--locked' : '' ?>">
            <span class="node__name mono"><?= View::e($node['node']) ?><?php if ($node['locked'] ?? false): ?><span class="icon node__lock" role="img" aria-label="<?= View::t('wf.locked_compliance') ?>" title="<?= View::t('wf.locked_compliance') ?>"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3.5" y="7" width="9" height="6" rx="1"/><path d="M5.5 7V5a2.5 2.5 0 015 0v2"/></svg></span><?php endif; ?></span>
            <span class="node__desc mono"><?= View::e(implode(' + ', Nodes::NODE_JOBS[$node['node']] ?? [])) ?></span>
          </div>
          <span class="node-connector" aria-hidden="true"></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('wf.node_settings') ?></h2></div>
  <div class="card__body">
    <dl class="kv-list">
      <?php foreach ($workflow['nodes'] as $node): ?>
        <?php if (($node['locked'] ?? false) || ($node['settings'] ?? []) !== []): ?>
        <div class="kv">
          <dt class="mono"><?= View::e($node['node']) ?></dt>
          <dd>
            <?php if ($node['locked'] ?? false): ?><?= View::t('wf.locked_mandatory') ?><?php endif; ?>
            <?php foreach (($node['settings'] ?? []) as $k => $v): ?>
              <?= View::e($k) ?>=<?= View::e(is_bool($v) ? ($v ? 'true' : 'false') : (string) $v) ?>
            <?php endforeach; ?>
          </dd>
        </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </dl>
    <p class="note"><?= View::t('wf.settings_note') ?></p>
  </div>
</div>
