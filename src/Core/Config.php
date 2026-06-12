<?php

declare(strict_types=1);

namespace Kuyash\Core;

use RuntimeException;

/**
 * Config loader + pure-PHP .env parser (no packages).
 *
 * - Env vars: Config::loadEnvFile() parses KEY=VALUE lines into $_ENV
 *   (existing real environment variables always win).
 * - Config values: every config/<name>.php returns an array; values are
 *   read with dot notation, e.g. get('app.debug').
 */
final class Config
{
    /** @var array<string, array<string, mixed>> */
    private array $items = [];

    public function __construct(string $configDir)
    {
        if (!is_dir($configDir)) {
            throw new RuntimeException("Config directory not found: {$configDir}");
        }

        foreach (glob($configDir . '/*.php') ?: [] as $file) {
            $values = require $file;
            if (!is_array($values)) {
                continue; // defensive: config/ holds only files returning arrays (routes live in src/routes.php)
            }
            $this->items[basename($file, '.php')] = $values;
        }
    }

    /** Dot-notation lookup: get('app.debug', false) */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items[array_shift($segments)] ?? null;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value ?? $default;
    }

    /**
     * Parse a .env file into $_ENV. Missing file is fine (prod may use real env).
     * Supported syntax: KEY=VALUE, blank lines, full-line # comments,
     * single/double quoted values. Real environment variables are never overridden.
     */
    public static function loadEnvFile(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '' || !preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }

            // strip matching surrounding quotes
            if (strlen($value) >= 2
                && ($value[0] === '"' || $value[0] === "'")
                && str_ends_with($value, $value[0])
            ) {
                $value = substr($value, 1, -1);
            }

            if (getenv($key) === false && !array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }
        }
    }

    /**
     * Read an env var with type coercion for booleans/null.
     * "true"/"false"/"null" (case-insensitive) become real types.
     */
    public static function env(string $key, mixed $default = null): mixed
    {
        $raw = $_ENV[$key] ?? getenv($key);
        if ($raw === false || $raw === null) {
            return $default;
        }

        return match (strtolower((string) $raw)) {
            'true' => true,
            'false' => false,
            'null', '' => $default,
            default => $raw,
        };
    }
}
