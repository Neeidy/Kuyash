<?php

declare(strict_types=1);

use Kuyash\Core\View;

/** @var string $email */
/** @var string $name */
/** @var string $workspaceName */
/** @var string $role */
/** @var string $csrfField trusted generated HTML */
?>
<h1>Dashboard</h1>
<p>
  Signed in as <strong><?= View::e($name !== '' ? $name : $email) ?></strong>
  <span class="meta mono">(<?= View::e($email) ?>)</span><br>
  Workspace: <strong><?= View::e($workspaceName) ?></strong>
  <span class="meta mono">role: <?= View::e($role) ?></span>
</p>
<p class="meta">Nothing here yet — the Content Library arrives with Phase 3,
workflows and the production pipeline in later phases.</p>
<form method="post" action="/logout">
  <?= $csrfField ?>
  <button type="submit"
          style="padding:.45rem .8rem;border-radius:6px;border:1px solid #2a3a3e;background:transparent;color:inherit;cursor:pointer;">
    Sign out
  </button>
</form>
