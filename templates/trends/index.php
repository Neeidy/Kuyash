<?php

declare(strict_types=1);

use Kuyash\Core\View;
use Kuyash\Trend\TrendFeed;

/** @var TrendFeed $feed */
/** @var string $niche */
/** @var string $region */
/** @var list<string> $niches */
/** @var list<array{provider: string, units: int}> $quota */
/** @var string $csrfField trusted generated HTML */
?>
<div class="screen-head">
  <div>
    <h1><?= View::t('trends.title') ?></h1>
    <p class="screen-sub"><?= View::t('trends.subtitle') ?></p>
  </div>
  <div class="screen-head__actions">
    <span class="chip chip--faint mono"><?= View::t('trends.source_label') ?> <?= View::e($feed->source) ?></span>
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
      <?php if ($feed->fetchedAt !== null): ?>
      <span class="chip chip--<?= $feed->freshness === TrendFeed::FRESH ? 'ok' : 'warn' ?>">
        <span class="dot"></span><?= $feed->freshness === TrendFeed::FRESH ? View::t('trends.fresh') : View::t('trends.stale') ?>
        · <?= View::e((string) $feed->fetchedAt) ?>
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
  <?php foreach ($feed->items as $trend): ?>
  <article class="trend-card">
    <div class="trend-card__top">
      <span class="trend-card__score num"><?= (int) $trend['score'] ?></span>
      <span class="chip chip--<?= $trend['format'] === 'face' ? 'info' : 'neutral' ?>">
        <?= $trend['format'] === 'face' ? View::t('trends.format_face') : View::t('trends.format_faceless') ?>
      </span>
    </div>
    <h3 class="trend-card__topic"><?= View::e((string) $trend['topic']) ?></h3>
    <div class="trend-card__meta">
      <span class="chip chip--faint mono"><?= View::e((string) $trend['niche']) ?></span>
      <span class="chip chip--faint mono"><?= View::e((string) $trend['region']) ?></span>
      <span class="chip chip--faint mono"><?= View::e((string) $trend['source']) ?></span>
    </div>
    <form method="post" action="/trends/create" class="trend-card__action">
      <?= $csrfField ?>
      <input type="hidden" name="trend_id" value="<?= (int) $trend['id'] ?>">
      <button type="submit" class="btn btn--primary btn--sm"><?= View::t('trends.create_from_trend') ?></button>
    </form>
  </article>
  <?php endforeach; ?>
</div>
<p class="note"><?= View::t('trends.create_note') ?></p>
<?php endif; ?>
