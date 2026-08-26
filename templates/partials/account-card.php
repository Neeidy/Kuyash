<?php

declare(strict_types=1);

use Kuyash\Core\Messages;
use Kuyash\Core\View;

/**
 * Account live-stream card (Phase 21 §1). The signature connected-account widget,
 * used on the dashboard ("Connected accounts") and /accounts.
 *
 * REAL data: handle, platform, health, status, the follower count once the
 * provider has reported one (accounts.followers_count, filled by sync + the daily
 * snapshot chore), and in manage mode published-today + the default-reference
 * control.
 *
 * SAMPLE data: the video tile, the engagement counts, and the follower/growth
 * figures ONLY while no real audience number exists. The provider exposes no
 * per-post engagement yet (its analytics post list comes back empty), so those
 * counts stay deterministic stand-ins derived from the account — stable across
 * reloads, and marked "sample" wherever they appear.
 *
 * THE RULE THIS FILE ENFORCES: every fabricated number carries the sample chip;
 * a number without the chip came from the provider. A real follower count is
 * therefore shown bare, and the sample growth line is hidden next to it rather
 * than sitting unlabelled beside a real figure (real growth needs two days of
 * snapshots — a later phase). The visual tile is a styled gradient preview
 * (ken-burns, transform-only) — never a claim of a real, playable video, and it
 * points at no file, so the media-free visual gate stays 404-free.
 *
 * @var array<string, mixed> $account  id, platform, handle, health, status, …
 * @var bool                  $manage   true on /accounts → render the management footer
 * @var string                $csrfField (manage only) trusted generated HTML
 * @var list<array<string,mixed>> $references (manage only) ready reference assets
 */

$manage = $manage ?? false;
$csrfField = $csrfField ?? '';
$references = $references ?? [];

$platform = (string) $account['platform'];
$handle = (string) $account['handle'];
$health = (string) ($account['health'] ?? 'unknown');
$status = (string) ($account['status'] ?? 'connected');

// Deterministic per-account seed → stable sample figures (no time/random; safe to
// re-render). Same account always shows the same numbers across reloads.
$seed = crc32($platform . '|' . $handle . '|' . (string) ($account['id'] ?? 0));
$pick = static fn (int $salt, int $min, int $max): int
    => $min + (int) ((($seed >> ($salt % 16)) ^ ($salt * 2_654_435_761)) % max(1, $max - $min + 1));

// Is this account BACKED BY THE PROVIDER? A follower count only exists here
// after sync/the snapshot chore read it from the live account, so it is the
// signal that this row describes a genuine connected channel rather than demo
// seed data. It governs the whole card: a provider-backed account NEVER shows a
// fabricated figure — not even a chipped one — because a stand-in sitting on a
// real channel misrepresents that channel. Missing data reads as "no data yet".
// Demo/seed accounts keep the deterministic stand-ins, clearly chipped, so the
// screens stay populated without pretending.
$metric = static fn (string $key): ?int => ($account[$key] ?? null) === null ? null : (int) $account[$key];

$realFollowers = $metric('followers_count');

// A mock provider fabricates its own figures, so a snapshot it produced is NOT a
// measurement — treat such a card as demo data, or the stand-ins would render
// unmarked (worse than chipping them). Real providers tag their own name.
$mockSourced = ($account['metric_provider'] ?? null) === 'mock';

// Any real signal counts, not just followers: the provider fills followersCount
// asynchronously, so engagement can land first. Keying only on followers would
// drop such an account into the demo branch and paint fabricated engagement OVER
// real measured data — the exact inversion of this rule.
$providerBacked = !$mockSourced && (
    $realFollowers !== null
    || ($account['metric_date'] ?? null) !== null
    || $metric('metric_likes') !== null
    || $metric('metric_comments') !== null
    || $metric('metric_shares') !== null
    || $metric('metric_views') !== null
);
$followersAreReal = $providerBacked && $realFollowers !== null;

// On a provider-backed card a missing follower count is a dash, NOT a stand-in:
// once an account is known to be real, nothing on it may be invented.
$followers = $providerBacked ? $realFollowers : $pick(1, 1_200, 96_000);
$likes = $providerBacked ? $metric('metric_likes') : $pick(3, 350, 12_000);
$comments = $providerBacked ? $metric('metric_comments') : $pick(5, 6, 480);
$shares = $providerBacked ? $metric('metric_shares') : $pick(7, 18, 3_200);
$growth = $pick(9, 4, 90);
// engagement is "reported" only when the provider actually returned a number
$engagementReported = $providerBacked && ($likes !== null || $comments !== null || $shares !== null);

// Compact 1.5K / 1.2M humanizer.
$fmt = static function (int $n): string {
    if ($n >= 1_000_000) {
        return rtrim(rtrim(number_format($n / 1_000_000, 1), '0'), '.') . 'M';
    }
    if ($n >= 1_000) {
        return rtrim(rtrim(number_format($n / 1_000, 1), '0'), '.') . 'K';
    }
    return (string) $n;
};

// An unreported metric is a dash — never a 0, never a stand-in.
$show = static fn (?int $n): string => $n === null ? '—' : $fmt($n);

// Curated dark, cinematic gradients (match the v3 look) — chosen by seed, never muddy.
$gradients = [
    'linear-gradient(135deg,#1e3a5f,#0f5132,#3a1e5f)',
    'linear-gradient(135deg,#2a1a3a,#1a2a3a,#3a2a1a)',
    'linear-gradient(135deg,#5f1e3a,#32510f,#1e2a5f)',
    'linear-gradient(135deg,#1a2e44,#3a1e4f,#0f3d3a)',
    'linear-gradient(135deg,#3d1f2f,#1f2e3d,#22402e)',
];
$avatars = [
    'linear-gradient(135deg,#2ff0d2,#60a5fa)',
    'linear-gradient(135deg,#b794ff,#ff6b6b)',
    'linear-gradient(135deg,#4ade80,#2ff0d2)',
    'linear-gradient(135deg,#f59e0b,#b794ff)',
];
$grad = $gradients[$seed % count($gradients)];
$avatar = $avatars[($seed >> 4) % count($avatars)];

$healthKey = ['ok' => 'acct.health_ok', 'degraded' => 'acct.health_degraded'][$health] ?? 'acct.health_unknown';
$healthTone = ['ok' => 'ok', 'degraded' => 'warn'][$health] ?? 'neutral';
$statusKey = ['connected' => 'acct.status_connected', 'reauth_needed' => 'acct.status_reauth_needed'][$status] ?? 'acct.status_disconnected';
$statusTone = ['connected' => 'ok', 'reauth_needed' => 'err'][$status] ?? 'neutral';
?>
<article class="acc-card">
  <?php
  /* A SAMPLE frame, and only ever on a sample card.
     The tile was a bare gradient, which on a screen that now shows real frames
     everywhere else reads as an image that failed to load. A demo card may show
     demo imagery — every figure on it already carries the sample chip and the
     handle says what it is. A PROVIDER-BACKED card may not: putting a frame the
     account never published under a real handle would be a claim about that
     channel, which is the one thing this file exists to prevent. */
  $samplePosters = $samplePosters ?? [];
  $sampleFrame = (!$providerBacked && $samplePosters !== [])
      ? '/media/' . (int) $samplePosters[$seed % count($samplePosters)] . '/poster'
      : null;
  ?>
  <div class="acc-card__media" role="img" aria-label="<?= View::t('acct.video_aria', ['handle' => $handle]) ?>">
    <?php if ($sampleFrame !== null): ?>
    <img class="acc-card__frame" src="<?= View::e($sampleFrame) ?>" alt="" loading="lazy">
    <?php else: ?>
    <span class="acc-card__kenburns" style="background-image: <?= $grad ?>"></span>
    <?php endif; ?>
    <span class="acc-card__overlay"></span>
    <div class="acc-card__head">
      <span class="acc-card__avatar" style="background-image: <?= $avatar ?>" aria-hidden="true"></span>
      <span class="acc-card__handle"><?= View::e($handle) ?></span>
      <span class="acc-card__plat"><?= View::e(Messages::platform($platform)) ?></span>
    </div>
    <div class="acc-card__eng">
      <span class="acc-eng acc-eng--heart" aria-label="<?= View::e($show($likes)) ?> <?= View::t('acct.likes_aria') ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 8c0-2.5-2-4-4-4-1.5 0-3 1-4 2.5C11 5 9.5 4 8 4 6 4 4 5.5 4 8c0 4 8 9 8 9s8-5 8-9z"/></svg>
        <span class="num"><?= View::e($show($likes)) ?></span>
      </span>
      <span class="acc-eng" aria-label="<?= View::e($show($comments)) ?> <?= View::t('acct.comments_aria') ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 11.5a8 8 0 01-12 7L3 20l1.5-6A8 8 0 1121 11.5z"/></svg>
        <span class="num"><?= View::e($show($comments)) ?></span>
      </span>
      <span class="acc-eng" aria-label="<?= View::e($show($shares)) ?> <?= View::t('acct.shares_aria') ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 12l16-8-6 16-3-7-7-1z"/></svg>
        <span class="num"><?= View::e($show($shares)) ?></span>
      </span>
      <?php /* Exactly one of these can appear. A demo account is chipped
               "sample"; a provider-backed account with nothing reported yet says
               so plainly. A provider-backed account WITH real numbers gets no
               badge at all — an unmarked number means a measured number. */ ?>
      <?php if (!$providerBacked): ?>
      <span class="acc-card__sample chip"><?= View::t('acct.sample') ?></span>
      <?php elseif (!$engagementReported): ?>
      <span class="acc-card__sample acc-card__sample--empty chip"><?= View::t('acct.no_metrics') ?></span>
      <?php endif; ?>
    </div>
  </div>
  <div class="acc-card__foot">
    <?php /* the handle already headlines the tile above; repeating it here ate the
             row and truncated the number itself ("· 3...." — 3.6K or 3M?). The
             figure is the point of this line, so it gets the space. */ ?>
    <span class="acc-card__who"><span class="num"><?= View::e($show($followers)) ?></span> <?= View::t('acct.followers') ?></span>
    <?php if (!$providerBacked): ?>
    <?php /* the chip MUST live outside __who: that span truncates with an
             ellipsis, which would silently swallow the honesty marker and leave
             a fabricated number looking measured */ ?>
    <span class="acc-card__sample acc-card__sample--foot chip"><?= View::t('acct.sample') ?></span>
    <span class="acc-card__grow num"><svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M5 14l7-7 7 7"/></svg> <?= View::t('acct.growth_today', ['n' => (int) $growth]) ?></span>
    <?php endif; ?>
  </div>
  <div class="acc-card__status">
    <span class="chip chip--<?= $healthTone ?>"><span class="dot dot--<?= $healthTone ?>"></span><?= View::t($healthKey) ?></span>
    <?php if ($manage): ?>
    <span class="chip chip--<?= $statusTone ?>"><span class="dot dot--<?= $statusTone ?>"></span><?= View::t($statusKey) ?></span>
    <?php if (isset($account['published_today'], $account['daily_cap'])): ?>
    <span class="chip chip--neutral num"><?= View::t('accounts.published_today', ['n' => (int) $account['published_today'], 'cap' => (int) $account['daily_cap']]) ?></span>
    <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php if ($manage): ?>
  <div class="acc-card__manage">
    <form method="post" action="/accounts/<?= (int) $account['id'] ?>/reference" class="account-ref-form">
      <?= $csrfField ?>
      <label class="field field--inline">
        <span class="field__label"><?= View::t('accounts.default_reference') ?></span>
        <select name="asset_id">
          <option value=""><?= View::t('accounts.none_option') ?></option>
          <?php foreach ($references as $ref): ?>
          <option value="<?= (int) $ref['id'] ?>"<?= (int) ($account['default_reference_asset_id'] ?? 0) === (int) $ref['id'] ? ' selected' : '' ?>><?= View::e((string) $ref['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn btn--ghost btn--sm"><?= View::t('accounts.save') ?></button>
    </form>
    <?php if ($status !== 'disconnected'): ?>
    <form method="post" action="/accounts/<?= (int) $account['id'] ?>/disconnect"
          data-confirm="<?= View::t('accounts.disconnect_confirm', ['handle' => $handle]) ?>">
      <?= $csrfField ?>
      <button type="submit" class="btn btn--danger-ghost btn--sm"><?= View::t('accounts.disconnect') ?></button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</article>
