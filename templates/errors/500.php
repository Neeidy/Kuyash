<?php

declare(strict_types=1);

use Kuyash\Core\View;
?>
<h1><?= View::t('error.500.title') ?></h1>
<p><?= View::t('error.500.body') ?></p>
<p class="meta"><a href="/"><?= View::t('error.home') ?></a></p>
