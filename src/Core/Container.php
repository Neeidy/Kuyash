<?php

declare(strict_types=1);

namespace Kuyash\Core;

use Closure;
use RuntimeException;

/**
 * Minimal service container: explicit factories only.
 * No autowiring, no reflection — services must be bound by hand (no-overbuild rule).
 * Factories are lazy; resolved instances are cached (singleton per container).
 */
final class Container
{
    /** @var array<string, Closure(Container): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function bind(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || isset($this->instances[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new RuntimeException(sprintf(
                'Container: no binding for "%s". Register it explicitly in src/bootstrap.php.',
                $id
            ));
        }

        return $this->instances[$id] = ($this->factories[$id])($this);
    }
}
