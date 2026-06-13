<?php

declare(strict_types=1);

use Kuyash\Core\I18n;
use Kuyash\Core\View;

/** @var string $content */
/** @var string $title */
?>
<!DOCTYPE html>
<html lang="<?= View::e(I18n::locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title ?? 'Kuyash') ?></title>
<link rel="stylesheet" href="/assets/css/base.css">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="center-shell">
<main>
<?= $content ?>
</main>
</body>
</html>
