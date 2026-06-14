<?php

declare(strict_types=1);

use Kuyash\Core\View;

/**
 * Production-line node-graph (Phase 18). Pure VISUALIZE: each node's state comes
 * from REAL job status (Cockpit::pipeline). node-graph.js draws the connectors
 * (done channel / active fill-flow / waiting dashes); clicking a node opens the
 * Phase-16 drawer via data-drawer-open → a server-rendered, escaped <template>
 * (no jargon, no innerHTML-from-untrusted). Mobile = stacked cards (SVG hidden).
 *
 * @var array{run_id:int, template:string, nodes:list<array{name:string,state:string}>} $pipeline
 */

$stateKey = ['done' => 'runs.state_done', 'active' => 'runs.state_running', 'wait' => 'runs.state_pending', 'failed' => 'runs.state_failed'];
$descKey = [
    'TREND' => 'node.desc.trend', 'IDEA' => 'node.desc.idea', 'SCRIPT' => 'node.desc.script',
    'VOICE' => 'node.desc.voice', 'VISUALS' => 'node.desc.visuals', 'LIBRARY' => 'node.desc.library',
    'ASSEMBLE' => 'node.desc.assemble', 'CAPTION' => 'node.desc.caption', 'HASHTAGS' => 'node.desc.hashtags',
    'MUSIC NOTE / STYLE' => 'node.desc.music', 'PREVIEW' => 'node.desc.preview',
    'COMPLIANCE' => 'node.desc.compliance', 'PUBLISH' => 'node.desc.publish',
];
/* big chip glyph per state */
$chipIcon = static function (string $s): string {
    return match ($s) {
        'done' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10" opacity=".4"/><path d="M7.5 12.5l3 3 6-6.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'active' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10" opacity=".5"/><circle cx="12" cy="12" r="3" fill="currentColor" stroke="none"/></svg>',
        'failed' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10" opacity=".5"/><path d="M9 9l6 6M15 9l-6 6" stroke-linecap="round"/></svg>',
        default => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" opacity=".5"/><circle cx="12" cy="12" r="2.5" fill="currentColor" stroke="none" opacity=".5"/></svg>',
    };
};
/* small status glyph (✓ / ⚡ / dashed ring / ✕) */
$sttIcon = static function (string $s): string {
    return match ($s) {
        'done' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12l4 4 10-11" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'active' => '<svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M13 2L4 14h6l-1 8 9-12h-6z"/></svg>',
        'failed' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><path d="M6 6l12 12M18 6L6 18" stroke-linecap="round"/></svg>',
        default => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="8" stroke-dasharray="3 3.5"/></svg>',
    };
};
?>
<div class="pipeline-flow" data-pipeline>
  <svg class="pipeline-conns" id="pipeline-conns" preserveAspectRatio="none" aria-hidden="true"></svg>
  <div class="pipeline-nodes">
    <?php foreach ($pipeline['nodes'] as $i => $n): ?>
    <?php $st = (string) $n['state']; ?>
    <button type="button" class="pl-node pl-node--<?= View::e($st) ?>" data-drawer-open="node-tpl-<?= (int) $i ?>" data-pl-state="<?= View::e($st) ?>"
            aria-label="<?= View::e((string) $n['name']) ?> — <?= View::t($stateKey[$st] ?? 'runs.state_pending') ?>">
      <span class="pl-node__chip"><?= $chipIcon($st) ?></span>
      <span class="pl-node__name"><?= View::e((string) $n['name']) ?></span>
      <span class="pl-node__stt"><?= $sttIcon($st) ?></span>
    </button>
    <?php endforeach; ?>
  </div>
</div>
<?php foreach ($pipeline['nodes'] as $i => $n): ?>
<?php $st = (string) $n['state']; ?>
<template id="node-tpl-<?= (int) $i ?>" data-title="<?= View::e((string) $n['name']) ?>">
  <span class="nodestat nodestat--<?= View::e($st) ?>"><?= View::t($stateKey[$st] ?? 'runs.state_pending') ?></span>
  <p class="drawer-desc"><?= View::t($descKey[(string) $n['name']] ?? 'node.desc.generic') ?></p>
  <div class="drawer-flow">
    <span><?= View::t('pipeline.flow_in') ?></span>
    <span class="drawer-flow__arrow" aria-hidden="true">→</span>
    <span><?= View::t('pipeline.flow_process') ?></span>
    <span class="drawer-flow__arrow" aria-hidden="true">→</span>
    <span><?= View::t('pipeline.flow_out') ?></span>
  </div>
  <p class="note"><?= View::t('pipeline.auto_note') ?></p>
</template>
<?php endforeach; ?>
