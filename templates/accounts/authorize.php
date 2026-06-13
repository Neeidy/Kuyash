<?php

declare(strict_types=1);

use Kuyash\Core\View;

/** @var string $platform */
/** @var string $state one-time CSRF-equivalent nonce echoed back on the callback */

// Mock provider authorize screen. The "Authorize" button is a GET form to the
// callback (mimicking a real OAuth redirect, state carried in the URL). No real
// platform call is made — publishing is doc-gated.
?>
<div class="screen-head">
  <div>
    <h1><?= View::t('authz.title', ['platform' => $platform]) ?></h1>
    <p class="screen-sub"><?= View::t('authz.subtitle', ['platform' => $platform]) ?></p>
  </div>
  <div class="screen-head__actions">
    <a class="btn btn--ghost btn--sm" href="/accounts"><?= View::t('authz.cancel') ?></a>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2><?= View::t('authz.connect_to', ['platform' => $platform]) ?></h2></div>
  <div class="card__body">
    <p class="muted"><?= View::t('authz.desc') ?></p>
    <form method="get" action="/accounts/callback" class="settings-form">
      <input type="hidden" name="platform" value="<?= View::e($platform) ?>">
      <input type="hidden" name="state" value="<?= View::e($state) ?>">
      <input type="hidden" name="code" value="mock_authorization_code">
      <label class="field">
        <span class="field__label"><?= View::t('authz.handle_label') ?></span>
        <input type="text" name="handle" placeholder="<?= View::t('authz.handle_placeholder', ['platform' => $platform]) ?>" maxlength="64">
      </label>
      <button type="submit" class="btn btn--primary"><?= View::t('authz.authorize_connect') ?></button>
    </form>
  </div>
</div>
