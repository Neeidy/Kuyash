<?php

declare(strict_types=1);

use Kuyash\Core\View;

/** @var string $content */
/** @var string $title */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title ?? 'Kuyash') ?></title>
<style>
  /* Minimal skeleton styling only — the real UI ships in later phases
     and must follow .claude/docs/ui-style-guide.md */
  :root { color-scheme: dark; }
  body {
    margin: 0; min-height: 100vh; display: grid; place-items: center;
    background: #0b1416; color: #d7e2e4;
    font: 16px/1.6 -apple-system, "Segoe UI", system-ui, sans-serif;
  }
  main { max-width: 36rem; padding: 2rem; }
  h1 { color: #2dd4bf; font-size: 1.4rem; letter-spacing: .02em; }
  code, .mono { font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: .85em; }
  .meta { color: #7e9398; font-size: .85rem; }
  a { color: #2dd4bf; }
</style>
</head>
<body>
<main>
<?= $content ?>
</main>
</body>
</html>
