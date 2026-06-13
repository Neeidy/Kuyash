<?php

declare(strict_types=1);

namespace Kuyash\Core;

use RuntimeException;
use Throwable;

/**
 * Tiny PHP template renderer: a template is included inside an output buffer,
 * then wrapped in a layout that receives the result as $content.
 * Templates escape output with View::e().
 */
final class View
{
    public function __construct(private readonly string $templateDir)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], ?string $layout = 'layout/base'): string
    {
        $content = $this->renderFile($template, $data);

        if ($layout === null) {
            return $content;
        }

        return $this->renderFile($layout, $data + ['content' => $content]);
    }

    /** HTML-escape helper for templates. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Translate + escape — the template short form for static UI chrome:
     * `<?= View::t('queue.title') ?>`. Equivalent to e(I18n::t(...)); the
     * translated string is always escaped, so lang files hold plain text.
     *
     * @param array<string, scalar|null> $params
     */
    public static function t(string $key, array $params = []): string
    {
        return self::e(I18n::t($key, $params));
    }

    /** @param array<string, mixed> $data */
    private function renderFile(string $template, array $data): string
    {
        $file = $this->templateDir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Template not found: {$template}");
        }

        extract($data, EXTR_SKIP);

        ob_start();
        try {
            require $file;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
