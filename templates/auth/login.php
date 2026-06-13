<?php

declare(strict_types=1);

use Kuyash\Core\View;

/** @var string $csrfField trusted generated HTML */
/** @var ?string $error */
/** @var string $email */
?>
<div class="panel">
  <h1><?= View::t('login.title') ?></h1>
  <?php if ($error !== null): ?>
  <div class="callout callout--err" role="alert">
    <span class="icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="6.5"/><path d="M8 5v3.5M8 11h.01"/></svg></span>
    <div><?= View::e($error) ?></div>
  </div>
  <?php endif; ?>
  <form method="post" action="/login">
    <?= $csrfField ?>
    <label class="field"><span><?= View::t('login.email') ?></span>
      <input type="email" name="email" value="<?= View::e($email) ?>" required autofocus autocomplete="username">
    </label>
    <label class="field"><span><?= View::t('login.password') ?></span>
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit" class="btn btn--primary"><?= View::t('login.submit') ?></button>
  </form>
  <p class="note"><?= View::t('login.note') ?> <code>php bin/create-user.php</code></p>
</div>
