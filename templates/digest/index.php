<?php

declare(strict_types=1);

use Kuyash\Core\Format;
use Kuyash\Core\Messages;
use Kuyash\Core\View;

/** @var array<string, mixed> $digest see DigestReport::forDate() */
/** @var string $today UTC YYYY-MM-DD */
/** @var string $csrfField trusted generated HTML */

$date = (string) $digest['date'];
$prev = gmdate('Y-m-d', (int) strtotime($date . 'T00:00:00Z') - 86400);
$next = gmdate('Y-m-d', (int) strtotime($date . 'T00:00:00Z') + 86400);
?>
<div class="screen-head">
  <div>
    <h1>Daily digest</h1>
    <p class="screen-sub">What the compliance agent did autonomously on
      <span class="mono"><?= View::e($date) ?></span> (UTC). Manual decisions are on each run's page.</p>
  </div>
  <div class="screen-head__actions">
    <a class="btn btn--ghost btn--sm" href="/digest?date=<?= View::e($prev) ?>">← <?= View::e($prev) ?></a>
    <?php if ($date < $today): ?>
    <a class="btn btn--ghost btn--sm" href="/digest?date=<?= View::e($next) ?>"><?= View::e($next) ?> →</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($digest['fell_back_to_manual']): ?>
<div class="callout callout--warn callout--banner" role="alert">
  <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M8 2L1.5 13.5h13z"/><path d="M8 6.5V10M8 12h.01"/></svg></span>
  <div><strong>This day the workspace fell back to Manual mode</strong> — the quality score
    breached its threshold. Re-enabling Auto is a human act in <a href="/settings">Settings</a>.</div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><h2>Status</h2></div>
  <div class="card__body">
    <div class="digest-status">
      <span class="chip chip--<?= $digest['approval_mode'] === 'auto' ? 'warn' : 'ok' ?>"><span class="dot"></span>mode: <?= View::e((string) $digest['approval_mode']) ?></span>
      <span class="chip chip--<?= $digest['kill_switch'] ? 'err' : 'ok' ?>"><span class="dot"></span>kill switch: <?= $digest['kill_switch'] ? 'ON' : 'off' ?></span>
      <span class="chip chip--neutral num">quality score: <?= (int) $digest['quality']['sample'] >= 5 ? (int) $digest['quality']['score'] : '—' ?> (sample <?= (int) $digest['quality']['sample'] ?>)</span>
      <span class="chip chip--faint mono">policy <?= View::e((string) $digest['quality']['policy']) ?></span>
    </div>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Auto-approved</h2>
    <span class="card__action"><span class="chip chip--faint num"><?= count($digest['auto_approved']) ?></span></span>
  </div>
  <div class="card__body">
    <?php if ($digest['auto_approved'] === []): ?>
    <p class="muted">Nothing was auto-approved on this date.</p>
    <?php else: ?>
    <ul class="job-list">
      <?php foreach ($digest['auto_approved'] as $item): ?>
      <li class="job-row">
        <?php if (($item['render']['poster_name'] ?? null) !== null): ?>
        <img class="digest-thumb" src="/render/<?= (int) $item['render']['id'] ?>/poster" alt="" loading="lazy">
        <?php endif; ?>
        <div class="job-row__main">
          <span class="job-row__type mono"><?= View::e((string) $item['node']) ?> · run #<?= (int) $item['run_id'] ?></span>
          <span class="job-row__entity">Auto-approved by compliance agent (policy <?= View::e((string) $item['policy_version']) ?>)
            · <?= View::e(Format::utcTime((string) $item['decided_at'])) ?>
            <?php if (isset($item['score']['quality']['score'])): ?> · score <?= (int) $item['score']['quality']['score'] ?><?php endif; ?></span>
        </div>
        <a class="btn btn--ghost btn--sm" href="/runs/<?= (int) $item['run_id'] ?>">View run</a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Auto-published</h2>
    <span class="card__action"><span class="chip chip--faint num"><?= count($digest['auto_published']) ?></span></span>
  </div>
  <div class="card__body">
    <?php if ($digest['auto_published'] === []): ?>
    <p class="muted">Nothing auto-approved reached publish on this date.</p>
    <?php else: ?>
    <ul class="job-list">
      <?php foreach ($digest['auto_published'] as $item): ?>
      <li class="job-row">
        <div class="job-row__main">
          <span class="job-row__type mono">publish #<?= (int) $item['id'] ?> · run #<?= (int) $item['run_id'] ?></span>
          <span class="job-row__entity"><?= View::e((string) (($item['result']['mode'] ?? '') === 'mock' ? 'mock publish — nothing went live (real publishing is Phase 10)' : 'published')) ?>
            · <?= View::e(Format::utcTime((string) $item['finished_at'])) ?></span>
        </div>
        <a class="btn btn--ghost btn--sm" href="/runs/<?= (int) $item['run_id'] ?>">View run</a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Guardrail events</h2>
    <span class="card__action"><span class="chip chip--faint num"><?= count($digest['guardrail_events']) ?></span></span>
  </div>
  <div class="card__body">
    <?php if ($digest['guardrail_events'] === []): ?>
    <p class="muted">No guardrail fired on this date.</p>
    <?php else: ?>
    <div class="tl">
      <?php foreach ($digest['guardrail_events'] as $event): ?>
      <div class="tl__step is-done">
        <span class="tl__dot tl__dot--<?= View::e((string) $event['level']) ?>"></span>
        <span class="tl__label"><?= View::e(Messages::event((string) $event['key'], $event['params'])) ?></span>
        <span class="tl__time mono"><?= View::e(Format::utcTime((string) $event['created_at'])) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
