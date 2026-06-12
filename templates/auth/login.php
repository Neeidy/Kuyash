<?php

declare(strict_types=1);

use Kuyash\Core\View;

/** @var string $csrfField trusted generated HTML */
/** @var ?string $error */
/** @var string $email */
?>
<h1>Sign in to Kuyash</h1>
<?php if ($error !== null): ?>
<p role="alert" style="color:#f87171;border:1px solid #7f1d1d;border-radius:6px;padding:.6rem .9rem;">
  <?= View::e($error) ?>
</p>
<?php endif; ?>
<form method="post" action="/login" style="display:grid;gap:.9rem;max-width:22rem;">
  <?= $csrfField ?>
  <label style="display:grid;gap:.3rem;">Email
    <input type="email" name="email" value="<?= View::e($email) ?>" required autofocus
           autocomplete="username"
           style="padding:.5rem .7rem;border-radius:6px;border:1px solid #2a3a3e;background:#0f1c1f;color:inherit;">
  </label>
  <label style="display:grid;gap:.3rem;">Password
    <input type="password" name="password" required autocomplete="current-password"
           style="padding:.5rem .7rem;border-radius:6px;border:1px solid #2a3a3e;background:#0f1c1f;color:inherit;">
  </label>
  <button type="submit"
          style="padding:.55rem .9rem;border-radius:6px;border:0;background:#2dd4bf;color:#06302b;font-weight:600;cursor:pointer;">
    Sign in
  </button>
</form>
<p class="meta">No account? Create one from the terminal: <code>php bin/create-user.php</code></p>
