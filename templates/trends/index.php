<?php

declare(strict_types=1);

use Kuyash\Core\Messages;
use Kuyash\Core\View;
use Kuyash\Trend\TrendFeed;

/** @var TrendFeed $feed */
/** @var string $niche */
/** @var string $region */
/** @var list<string> $niches */
/** @var list<array{provider: string, units: int}> $quota */
/** @var string $csrfField trusted generated HTML */

/* Did a real provider produce these rows?
 * Read from the BATCH's own source tag, not from the TREND_MOCK env flag: cached
 * rows keep the provider that fetched them, so a workspace holding a genuine
 * batch keeps showing it unmarked even after the flag is flipped, and a mock
 * batch stays marked after it is flipped back. Provenance travels with the data.
 *
 * WHY THIS EXISTS: with TREND_MOCK=true this screen printed scores of 98/96/95
 * and a "fresh · 31 min ago" badge over topics invented locally — the only
 * surface in the product where a fabricated figure carried no marker at all.
 * The score is the number an operator picks a video by.
 *
 * FAILS CLOSED: the test is "is this one of the providers that reads the real
 * world?", not "is it the mock?". Keying on the mock's name would leave any
 * future stub — a second mock, a fixture provider, a half-finished adapter —
 * rendering as a measurement by default, and the default has to be the safe
 * answer. */
$simulated = !in_array($feed->source, ['youtube', 'google_trends'], true);
?>
<div class="screen-head">
  <div>
    <h1><?= View::t('trends.title') ?></h1>
    <p class="screen-sub"><?= View::t('trends.subtitle') ?></p>
  </div>
  <div class="screen-head__actions">
    <form method="post" action="/trends/refresh">
      <?= $csrfField ?>
      <button type="submit" class="btn btn--ghost btn--sm"><?= View::t('trends.refresh') ?></button>
    </form>
  </div>
</div>

<?php if ($feed->freshness === TrendFeed::STALE): ?>
<div class="callout callout--warn callout--banner" role="alert">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6.5"/><path d="M8 4.5V8l2.5 1.5"/></svg></span>
  <div><strong><?= View::t('trends.stale_title') ?></strong> <?= View::t('trends.stale_body_1') ?><?= $feed->error !== null ? ' — ' . View::e($feed->error) : '' ?>. <?= View::t('trends.stale_body_2') ?></div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><h2><?= View::t('trends.niche_word') ?></h2>
    <span class="card__action">
      <?php if ($simulated): ?>
      <?php /* before the freshness chip, so "fresh · just now" is read as "these
               sample rows were generated just now" and not as a claim about the
               world */ ?>
      <span class="chip chip--warn"><span class="dot dot--warn"></span><?= View::t('trends.sample') ?></span>
      <?php endif; ?>
      <?php if ($feed->fetchedAt !== null): ?>
      <span class="chip chip--<?= $feed->freshness === TrendFeed::FRESH ? 'ok' : 'warn' ?>"
            title="<?= View::e((string) $feed->fetchedAt) ?>">
        <span class="dot"></span><?= $feed->freshness === TrendFeed::FRESH ? View::t('trends.fresh') : View::t('trends.stale') ?>
        · <?= View::e(Messages::since((string) $feed->fetchedAt)) ?>
      </span>
      <?php endif; ?>
    </span>
  </div>
  <div class="card__body">
    <form method="post" action="/trends/niche" class="trend-niche">
      <?= $csrfField ?>
      <label class="field">
        <span class="field__label"><?= View::t('trends.niche_word') ?></span>
        <select name="niche">
          <?php foreach ($niches as $option): ?>
          <option value="<?= View::e($option) ?>"<?= $option === $niche ? ' selected' : '' ?>><?= View::e(ucfirst($option)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field__label"><?= View::t('trends.region') ?></span>
        <input type="text" name="region" value="<?= View::e($region) ?>" maxlength="2"
               pattern="[A-Za-z]{2}" placeholder="US">
      </label>
      <button type="submit" class="btn btn--primary btn--sm"><?= View::t('trends.apply') ?></button>
    </form>
    <?php if ($quota !== []): ?>
    <p class="note"><?= View::t('trends.api_usage_today') ?>
      <?php foreach ($quota as $q): ?>
      <span class="chip chip--faint mono"><?= View::e($q['provider']) ?>: <?= (int) $q['units'] ?> <?= View::t('trends.units') ?></span>
      <?php endforeach; ?>
    </p>
    <?php endif; ?>
  </div>
</div>

<?php if ($feed->isEmpty()): ?>
<div class="ui-state">
  <span class="ui-state__icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M1.5 11l3.5-4 2.5 2.5 4-5"/><path d="M11.5 4.5h2v2"/></svg></span>
  <h3><?= View::t('trends.empty') ?></h3>
  <p><?= $feed->error !== null
      ? View::t('trends.empty_error', ['error' => (string) $feed->error])
      : View::t('trends.empty_hint') ?></p>
</div>
<?php else: ?>
<div class="trend-grid">
  <?php foreach ($feed->items as $i => $trend): ?>
  <article class="trend-card" style="--i:<?= min((int) $i, 7) ?>">
    <div class="trend-card__top">
      <span class="trend-card__score num"><?= (int) $trend['score'] ?></span>
      <?php if ($simulated): ?>
      <?php /* on the score itself: it is the figure the card is chosen by, and
               the only one here that looks like a measurement */ ?>
      <span class="chip chip--warn trend-card__sample"><?= View::t('trends.sample') ?></span>
      <?php endif; ?>
      <span class="chip chip--<?= $trend['format'] === 'face' ? 'info' : 'neutral' ?>">
        <?= $trend['format'] === 'face' ? View::t('trends.format_face') : View::t('trends.format_faceless') ?>
      </span>
    </div>
    <h3 class="trend-card__topic"><?= View::e((string) $trend['topic']) ?></h3>
    <div class="trend-card__meta">
      <span class="chip chip--faint mono"><?= View::e((string) $trend['niche']) ?></span>
      <span class="chip chip--faint mono"><?= View::e((string) $trend['region']) ?></span>
    </div>
    <form method="post" action="/trends/create" class="trend-card__action">
      <?= $csrfField ?>
      <input type="hidden" name="trend_id" value="<?= (int) $trend['id'] ?>">
      <button type="submit" class="btn btn--primary btn--sm"><?= View::t('trends.create_from_trend') ?></button>
    </form>
  </article>
  <?php endforeach; ?>
</div>
<?php if ($simulated): ?>
<p class="note"><?= View::t('trends.sample_note') ?></p>
<?php endif; ?>
<p class="note"><?= View::t('trends.create_note') ?></p>
<?php endif; ?>
