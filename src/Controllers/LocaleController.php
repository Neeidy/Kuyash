<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Auth\Auth;
use Kuyash\Core\Database;
use Kuyash\Core\Flash;
use Kuyash\Core\I18n;
use Kuyash\Core\Response;

/**
 * UI language switch (Phase 14). POST /locale persists the chosen locale on the
 * logged-in user's row (the source of truth) and updates the session cache, then
 * redirects back to where the switch was triggered. CSRF is enforced by the
 * blanket POST gate in public/index.php; the route is auth-protected.
 *
 * i18n is a presentation concern only — this writes a column, never localized
 * content, so approval-record truthfulness is unaffected.
 */
final class LocaleController
{
    public function __construct(
        private readonly Database $db,
        private readonly Auth $auth,
        private readonly Flash $flash,
    ) {
    }

    /** @param array<string, string> $params */
    public function set(array $params = []): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            // unreachable behind the route guard; fail-closed backstop
            return Response::redirect('/login');
        }

        $locale = (string) ($_POST['locale'] ?? '');
        if (!in_array($locale, I18n::SUPPORTED, true)) {
            $this->flash->add('error', 'locale.invalid');

            return Response::redirect($this->backTo(), 303);
        }

        // additive column update (CHECK-constrained to the same set); keep the
        // session cache in step so the very next render is in the new language
        $this->db->run(
            'UPDATE users SET locale = ?, updated_at = ? WHERE id = ?',
            [$locale, gmdate('Y-m-d\TH:i:s\Z'), (int) $user['id']],
        );
        $this->auth->setSessionLocale($locale);

        $this->flash->add('success', 'locale.updated');

        return Response::redirect($this->backTo(), 303);
    }

    /**
     * A safe same-origin redirect target taken from the Referer PATH only (never
     * the host → no open redirect). Falls back to the dashboard when the header
     * is absent or not a local path.
     */
    private function backTo(): string
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (is_string($referer) && $referer !== '') {
            $path = parse_url($referer, PHP_URL_PATH);
            // local absolute path only — reject protocol-relative forms (`//host`
            // AND `/\host`, which browsers normalize to `//host`) so the Referer's
            // host can never steer an off-site redirect.
            if (is_string($path) && preg_match('#^/(?![/\\\\])#', $path) === 1) {
                $query = parse_url($referer, PHP_URL_QUERY);

                return is_string($query) && $query !== '' ? $path . '?' . $query : $path;
            }
        }

        return '/dashboard';
    }
}
