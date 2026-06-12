<?php

declare(strict_types=1);

use Kuyash\Core\View;

/** @var string $appName */
/** @var string $env */
/** @var string $version */
/** @var bool $debug */
?>
<h1><?= View::e($appName) ?> — PHP skeleton online</h1>
<p>Phase 1 app skeleton is running: router, config, container, error handler and
layout system are wired. No business logic yet — that arrives with later phases.</p>
<p class="meta mono">
  version <?= View::e($version) ?> ·
  env <?= View::e($env) ?> ·
  debug <?= $debug ? 'on' : 'off' ?> ·
  <a href="/health">/health</a>
</p>
