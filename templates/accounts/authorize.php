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
    <h1>Authorize <?= View::e($platform) ?></h1>
    <p class="screen-sub">Mock Zernio authorization. Confirm to connect a <?= View::e($platform) ?>
      account reference. No password or token is requested or stored.</p>
  </div>
  <div class="screen-head__actions">
    <a class="btn btn--ghost btn--sm" href="/accounts">Cancel</a>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Connect to <?= View::e($platform) ?></h2></div>
  <div class="card__body">
    <p class="muted">In production this is the platform's own OAuth screen (handled by Zernio).
      Here it is mocked: choose a handle to identify the account.</p>
    <form method="get" action="/accounts/callback" class="settings-form">
      <input type="hidden" name="platform" value="<?= View::e($platform) ?>">
      <input type="hidden" name="state" value="<?= View::e($state) ?>">
      <input type="hidden" name="code" value="mock_authorization_code">
      <label class="field">
        <span class="field__label">Account handle (optional)</span>
        <input type="text" name="handle" placeholder="@your_<?= View::e($platform) ?>_handle" maxlength="64">
      </label>
      <button type="submit" class="btn btn--primary">Authorize &amp; connect</button>
    </form>
  </div>
</div>
