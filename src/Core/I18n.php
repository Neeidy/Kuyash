<?php

declare(strict_types=1);

namespace Kuyash\Core;

/**
 * Presentation-layer translator (Phase 14). Static — like View::e, Format and
 * Messages, it carries no instance state and needs no DI wiring; templates call
 * it directly. The DB stores message KEYS, never localized text, so i18n adds no
 * stored-text migration and the truthfulness of records is untouched.
 *
 * Resolution order for every key: active locale → EN (source language) → the key
 * itself. A missing key therefore renders visibly (the raw key) and is never
 * fatal. EN is the universal fallback because it is the source language: any TR
 * gap quietly degrades to the English string rather than to a broken token.
 *
 * Lang files are flat PHP arrays returned from lang/{locale}.php. They are loaded
 * lazily and cached per locale for the request.
 */
final class I18n
{
    /** Supported UI locales. EN is the source language and the universal fallback. */
    public const SUPPORTED = ['en', 'tr'];

    public const DEFAULT = 'en';

    private static string $locale = self::DEFAULT;

    private static ?string $langDir = null;

    /** @var array<string, array<string, string>> loaded lang maps, keyed by locale */
    private static array $cache = [];

    /**
     * Point the loader at a different lang directory and drop the cache. Used by
     * tests to exercise fallback precisely; production never calls it (the default
     * resolves to the repo's lang/ relative to this file).
     */
    public static function setLangDir(string $dir): void
    {
        self::$langDir = $dir;
        self::$cache = [];
    }

    /** Activate a locale. Unknown values clamp to EN — never trust raw input here. */
    public static function setLocale(string $locale): void
    {
        self::$locale = in_array($locale, self::SUPPORTED, true) ? $locale : self::DEFAULT;
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /**
     * Choose a locale from a (possibly null/untrusted) session value, falling
     * back to the configured default and finally to EN. Pure — unit-testable
     * without a session.
     */
    public static function resolve(?string $sessionLocale, string $default): string
    {
        foreach ([$sessionLocale, $default] as $candidate) {
            if (is_string($candidate) && in_array($candidate, self::SUPPORTED, true)) {
                return $candidate;
            }
        }

        return self::DEFAULT;
    }

    /**
     * Raw text lookup with no interpolation: active locale → EN → null.
     * Callers that need a custom miss-fallback (Messages::status/event) use this.
     */
    public static function lookup(string $key): ?string
    {
        return self::map(self::$locale)[$key]
            ?? self::map(self::DEFAULT)[$key]
            ?? null;
    }

    /**
     * Translate + interpolate. A missing key returns the key itself so gaps are
     * visible in the UI rather than crashing.
     *
     * @param array<string, scalar|null> $params
     */
    public static function t(string $key, array $params = []): string
    {
        return self::interpolate(self::lookup($key) ?? $key, $params);
    }

    /**
     * Substitute {name} placeholders from $params (same grammar Messages used).
     * A placeholder with no matching param is left literal — diagnostic, never
     * a blank.
     *
     * @param array<string, scalar|null> $params
     */
    public static function interpolate(string $template, array $params): string
    {
        if ($params === [] || !str_contains($template, '{')) {
            return $template;
        }

        return preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static function (array $m) use ($params): string {
                $value = $params[$m[1]] ?? null;

                return is_scalar($value) ? (string) $value : $m[0];
            },
            $template,
        ) ?? $template;
    }

    /** @return array<string, string> */
    private static function map(string $locale): array
    {
        if (!isset(self::$cache[$locale])) {
            $file = self::langDir() . '/' . $locale . '.php';
            $data = is_file($file) ? require $file : [];
            self::$cache[$locale] = is_array($data) ? $data : [];
        }

        return self::$cache[$locale];
    }

    private static function langDir(): string
    {
        return self::$langDir ??= dirname(__DIR__, 2) . '/lang';
    }
}
