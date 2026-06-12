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
    <h1>Trend Radar</h1>
    <p class="screen-sub">Niche trends feeding idea generation. Mock-first — real YouTube / Google
      providers sit behind a flag (default off) and cache for hours.</p>
  </div>
  <div class="screen-head__actions">
    <span class="chip chip--faint mono">source: <?= View::e($feed->source) ?></span>
    <form method="post" action="/trends/refresh">
      <?= $csrfField ?>
      <button type="submit" class="btn btn--ghost btn--sm">Refresh</button>
    </form>
  </div>
</div>

<?php if ($feed->freshness === TrendFeed::STALE): ?>
<div class="callout callout--warn callout--banner" role="alert">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6.5"/><path d="M8 4.5V8l2.5 1.5"/></svg></span>
  <div><strong>Showing the last cached trends.</strong> The provider could not be reached
    just now<?= $feed->error !== null ? ' — ' . View::e($feed->error) : '' ?>. This data is stale, not live.</div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><h2>Niche</h2>
    <span class="card__action">
      <?php if ($feed->fetchedAt !== null): ?>
      <span class="chip chip--<?= $feed->freshness === TrendFeed::FRESH ? 'ok' : 'warn' ?>">
        <span class="dot"></span><?= $feed->freshness === TrendFeed::FRESH ? 'fresh' : 'stale' ?>
        · <?= View::e((string) $feed->fetchedAt) ?>
      </span>
      <?php endif; ?>
    </span>
  </div>
  <div class="card__body">
    <form method="post" action="/trends/niche" class="trend-niche">
      <?= $csrfField ?>
      <label class="field">
        <span class="field__label">Niche</span>
        <select name="niche">
          <?php foreach ($niches as $option): ?>
          <option value="<?= View::e($option) ?>"<?= $option === $niche ? ' selected' : '' ?>><?= View::e(ucfirst($option)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field">
        <span class="field__label">Region</span>
        <input type="text" name="region" value="<?= View::e($region) ?>" maxlength="2"
               pattern="[A-Za-z]{2}" placeholder="US">
      </label>
      <button type="submit" class="btn btn--primary btn--sm">Apply</button>
    </form>
    <?php if ($quota !== []): ?>
    <p class="note">Today's API usage:
      <?php foreach ($quota as $q): ?>
      <span class="chip chip--faint mono"><?= View::e($q['provider']) ?>: <?= (int) $q['units'] ?> units</span>
      <?php endforeach; ?>
    </p>
    <?php endif; ?>
  </div>
</div>

<?php if ($feed->isEmpty()): ?>
<div class="ui-state">
  <span class="ui-state__icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.2"><path d="M1.5 11l3.5-4 2.5 2.5 4-5"/><path d="M11.5 4.5h2v2"/></svg></span>
  <h3>No trends to show</h3>
  <p><?= $feed->error !== null
      ? 'The provider could not be reached and there is no cache yet — ' . View::e($feed->error)
      : 'Pick a niche and apply, or refresh to fetch the latest.' ?></p>
</div>
<?php else: ?>
<div class="trend-grid">
  <?php foreach ($feed->items as $trend): ?>
  <article class="trend-card">
    <div class="trend-card__top">
      <span class="trend-card__score num"><?= (int) $trend['score'] ?></span>
      <span class="chip chip--<?= $trend['format'] === 'face' ? 'info' : 'neutral' ?>">
        <?= $trend['format'] === 'face' ? 'face · shoot' : 'faceless · stock' ?>
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
      <button type="submit" class="btn btn--primary btn--sm">Create from trend</button>
    </form>
  </article>
  <?php endforeach; ?>
</div>
<p class="note">“Create from trend” starts the full pipeline (TREND → … → COMPLIANCE → PUBLISH)
  pinned to that topic. Trends are a research signal — Creator Watch (person-based signals) is a
  later pass.</p>
<?php endif; ?>
