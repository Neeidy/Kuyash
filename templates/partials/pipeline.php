<?php

declare(strict_types=1);

use Kuyash\Core\Messages;
use Kuyash\Core\View;

/**
 * Production-line node-graph (Phase 18). Pure VISUALIZE: each node's state comes
 * from REAL job status (Cockpit::pipeline). node-graph.js draws the connectors
 * (done channel / active fill-flow / waiting dashes); clicking a node opens the
 * Phase-16 drawer via data-drawer-open → a server-rendered, escaped <template>
 * (no jargon, no innerHTML-from-untrusted). Mobile = stacked cards (SVG hidden).
 *
 * Phase 21 §4: the drawer now shows the stage's REAL output — read from the run's
 * job result_json (Cockpit attaches a per-node `results` map: job-type → result).
 * done/active → real, per-node-distinct content; wait → "not started yet" + a
 * short plain description; no data → an honest "no output yet". Every value is
 * server-escaped before it reaches the <template> (XSS-safe innerHTML invariant).
 *
 * @var array{run_id:int, template:string, nodes:list<array{name:string,state:string,results:array<string,array<string,mixed>>}>} $pipeline
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

/* duration helper: round to 1dp, "?" when unknown */
$fmtDur = static fn ($d): string => ($d === null || !is_numeric($d)) ? '?' : (string) round((float) $d, 1);

/**
 * The REAL output block for a node, built from its job result_json. Returns ''
 * when there is no usable data (caller then shows an honest "no output yet").
 * EVERY dynamic value is run through View::e — this HTML lands in an escaped
 * <template> and is the only thing drawer.js injects as innerHTML.
 *
 * @param array<string, array<string, mixed>> $results job-type → result
 */
$outputHtml = static function (string $name, array $results) use ($fmtDur): string {
    $line = static fn (string $text): string => '<p class="drawer-out__line">' . View::e($text) . '</p>';

    switch ($name) {
        case 'TREND':
            $r = $results['trend_fetch'] ?? null;
            if ($r === null || !isset($r['trend'])) {
                return '';
            }
            /* The SCORE is the number the operator picked this video by, and with
               no trend provider switched on it was invented locally. The trend
               wall marks it; this drawer prints the same figure one click away
               and used to strip the marking off. TrendExecutor already writes
               the provenance into this very array, so the answer was in hand. */
            $realTrend = in_array((string) ($r['source'] ?? ''), ['youtube', 'google_trends'], true);

            return '<p class="drawer-out__lead">' . View::e((string) $r['trend']) . '</p>'
                . $line(View::t('node.out.trend_meta', [
                    'score' => (string) ($r['score'] ?? '?'),
                    'niche' => (string) ($r['niche'] ?? '—'),
                    'region' => (string) ($r['region'] ?? '—'),
                ]))
                . ($realTrend ? '' : '<p class="drawer-out__line"><span class="chip chip--warn">'
                    . View::t('trends.sample') . '</span> ' . View::e(View::t('trends.sample_score')) . '</p>');

        case 'IDEA':
            $r = $results['idea_generation'] ?? null;
            if ($r === null || !isset($r['idea'])) {
                return '';
            }
            $h = '';
            if (isset($r['hook']) && (string) $r['hook'] !== '') {
                $h .= '<p class="drawer-out__hook">“' . View::e((string) $r['hook']) . '”</p>';
            }
            return $h . '<p class="drawer-out__body">' . View::e((string) $r['idea']) . '</p>';

        case 'SCRIPT':
            $r = $results['script_draft'] ?? null;
            if ($r === null || !isset($r['script'])) {
                return '';
            }
            $h = '<blockquote class="drawer-out__script">' . nl2br(View::e((string) $r['script'])) . '</blockquote>';
            if (isset($r['word_count'])) {
                $h .= $line(View::t('node.out.script_meta', [
                    'words' => (string) (int) $r['word_count'],
                    'dur' => $fmtDur($r['estimated_duration_s'] ?? null),
                ]));
            }
            return $h;

        case 'VOICE':
            $r = $results['tts'] ?? null;
            if ($r === null) {
                return '';
            }
            return $line(View::t('node.out.voice_meta', [
                'voice' => (string) ($r['voice'] ?? '—'),
                'dur' => $fmtDur($r['duration_s'] ?? null),
            ]));

        case 'VISUALS':
        case 'LIBRARY':
            $r = $results['asset_fetch'] ?? null;
            if ($r === null) {
                return '';
            }
            $src = (string) ($r['source'] ?? 'stock');
            $srcKey = in_array($src, ['library', 'stock', 'reference', 'avatar'], true) ? 'node.out.src_' . $src : null;
            $srcLabel = $srcKey !== null ? View::t($srcKey) : $src;
            $lead = isset($r['title']) && (string) $r['title'] !== ''
                ? (string) $r['title'] : View::t('node.out.visual_generic');
            return '<p class="drawer-out__lead">' . View::e($lead) . '</p>'
                . $line(View::t('node.out.visual_meta', ['source' => $srcLabel]));

        case 'ASSEMBLE':
            $r = $results['assembly'] ?? null;
            if ($r === null) {
                return '';
            }
            return $line(View::t('node.out.assemble_meta', ['dur' => $fmtDur($r['duration_s'] ?? null)]));

        case 'CAPTION':
            $caps = $results['caption_generation']['captions'] ?? [];
            if (!is_array($caps) || $caps === []) {
                return '';
            }
            $h = '<dl class="drawer-out__caps">';
            foreach ($caps as $platform => $caption) {
                $h .= '<dt>' . View::e(Messages::platform((string) $platform)) . '</dt>'
                    . '<dd>' . View::e((string) $caption) . '</dd>';
            }
            return $h . '</dl>';

        case 'HASHTAGS':
            $tags = $results['hashtag_generation']['hashtags'] ?? [];
            if (!is_array($tags) || $tags === []) {
                return '';
            }
            $h = '<div class="drawer-out__tags">';
            foreach ($tags as $tag) {
                $h .= '<span class="tag">' . View::e((string) $tag) . '</span>';
            }
            return $h . '</div>';

        case 'MUSIC NOTE / STYLE':
            $r = $results['music_note'] ?? null;
            if ($r === null || !isset($r['mood'])) {
                return '';
            }
            $mood = (string) $r['mood'];
            $moodKey = in_array($mood, ['upbeat', 'calm', 'cinematic'], true) ? 'node.out.mood_' . $mood : null;
            $moodLabel = $moodKey !== null ? View::t($moodKey) : $mood;
            return $line(View::t('node.out.music_meta', ['mood' => $moodLabel]))
                . '<p class="note">' . View::e(View::t('node.out.music_hint')) . '</p>';

        case 'PREVIEW':
            if (($results['preview'] ?? null) === null) {
                return '';
            }
            return $line(View::t('node.out.preview_line'));

        case 'COMPLIANCE':
            $r = $results['compliance_check'] ?? null;
            if ($r === null || !isset($r['status'])) {
                return '';
            }
            $statusKey = match ((string) $r['status']) {
                'pass_with_ai_label' => 'node.out.comp_pass_ai',
                'warn' => 'node.out.comp_warn',
                'block' => 'node.out.comp_block',
                default => 'node.out.comp_pass',
            };
            $h = '<p class="drawer-out__lead">' . View::e(View::t($statusKey)) . '</p>';
            $slop = $r['checks']['slop']['score'] ?? null;
            if (is_numeric($slop)) {
                $h .= $line(View::t('node.out.similarity', ['pct' => (string) (int) round((float) $slop * 100)]));
            }
            $h .= $line(View::t(($r['ai_label_required'] ?? false) ? 'node.out.ai_label_yes' : 'node.out.ai_label_no'));
            return $h;

        case 'PUBLISH':
            $r = $results['publish'] ?? null;
            if ($r === null) {
                return '';
            }
            $h = '';
            $platforms = $r['platforms'] ?? [];
            if (is_array($platforms) && $platforms !== []) {
                $names = array_map(static fn ($p): string => Messages::platform((string) $p), $platforms);
                $h .= $line(View::t('node.out.publish_targets', ['platforms' => implode(', ', $names)]));
            }
            return $h . '<p class="note">' . View::e(View::t('node.out.publish_note')) . '</p>';

        default:
            return '';
    }
};
?>
<div class="pipeline-flow" data-pipeline>
  <svg class="pipeline-conns" id="pipeline-conns" preserveAspectRatio="none" aria-hidden="true"></svg>
  <div class="pipeline-nodes">
    <?php foreach ($pipeline['nodes'] as $i => $n): ?>
    <?php $st = (string) $n['state']; ?>
    <button type="button" class="pl-node pl-node--<?= View::e($st) ?>" data-drawer-open="node-tpl-<?= (int) $i ?>" data-pl-state="<?= View::e($st) ?>"
            title="<?= View::e((string) $n['name']) ?>"
            aria-label="<?= View::e((string) $n['name']) ?> — <?= View::t($stateKey[$st] ?? 'runs.state_pending') ?>">
      <span class="pl-node__chip"><?= $chipIcon($st) ?></span>
      <span class="pl-node__name"><?= View::e((string) $n['name']) ?></span>
      <span class="pl-node__stt"><?= $sttIcon($st) ?></span>
    </button>
    <?php endforeach; ?>
  </div>
</div>
<?php foreach ($pipeline['nodes'] as $i => $n): ?>
<?php $st = (string) $n['state']; $name = (string) $n['name']; ?>
<template id="node-tpl-<?= (int) $i ?>" data-title="<?= View::e($name) ?>">
  <span class="nodestat nodestat--<?= View::e($st) ?>"><?= View::t($stateKey[$st] ?? 'runs.state_pending') ?></span>
  <?php if ($st === 'wait'): ?>
  <p class="drawer-desc"><?= View::t('node.not_started') ?></p>
  <p class="note"><?= View::t($descKey[$name] ?? 'node.desc.generic') ?></p>
  <?php else: ?>
  <?php $out = $outputHtml($name, $n['results'] ?? []); ?>
  <?php if ($out !== ''): ?>
  <div class="drawer-out"><?= $out ?></div>
  <?php else: ?>
  <p class="muted"><?= View::t('node.no_data') ?></p>
  <?php endif; ?>
  <?php endif; ?>
</template>
<?php endforeach; ?>
