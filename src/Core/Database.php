<?php

declare(strict_types=1);

namespace Kuyash\Core;

use Closure;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * SQLite access layer: a thin PDO wrapper, deliberately NOT a query builder.
 * Every statement goes through prepare/execute (prepared statements only).
 * Connection is lazy; pragmas are applied on every connect:
 * WAL, busy_timeout=5000, foreign_keys=ON, synchronous=NORMAL.
 *
 * Transactions must stay short — never hold one across an external call
 * (ffmpeg, OpenAI, HTTP, …); that rule is enforced by review, not code.
 */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly string $path)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if ($this->path !== ':memory:') {
            $dir = dirname($this->path);
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new RuntimeException("Database directory cannot be created: {$dir}");
            }
        }

        $pdo = new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        // without this, INSERT OR REPLACE skips BEFORE DELETE triggers and
        // could silently rewrite "append-only" audit rows (security audit)
        $pdo->exec('PRAGMA recursive_triggers=ON');

        return $this->pdo = $pdo;
    }

    /**
     * Prepare + execute. The ONLY way queries run — raw exec() is reserved
     * for the Migrator (trusted, reviewed .sql files).
     *
     * @param list<mixed>|array<string, mixed> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * @param list<mixed>|array<string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param list<mixed>|array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** Run $fn inside a short transaction; rolls back on any throwable. */
    public function transaction(Closure $fn): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $result = $fn($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Like transaction(), but takes the write lock UP FRONT (`BEGIN IMMEDIATE`).
     *
     * PDO's beginTransaction() issues a plain DEFERRED `BEGIN`. A closure that
     * READS before it WRITES therefore holds a read snapshot, and in WAL that
     * transaction can no longer upgrade to a writer once any other connection
     * has committed in the meantime — SQLite answers SQLITE_BUSY_SNAPSHOT, which
     * `busy_timeout` deliberately does NOT cover, so it surfaces immediately as
     * "database is locked". Read-then-write paths that race the worker (which
     * commits on every job claim and every event) need this instead.
     *
     * DOES NOT NEST. PDO does not know a hand-issued BEGIN is open, so a
     * transaction() call inside this closure would try to start a second one and
     * fail. Keep the closure to plain reads and run() calls.
     */
    public function immediateTransaction(Closure $fn): mixed
    {
        $pdo = $this->pdo();
        $pdo->exec('BEGIN IMMEDIATE');

        try {
            $result = $fn($this);
            $pdo->exec('COMMIT');

            return $result;
        } catch (Throwable $e) {
            // inTransaction() does not track a hand-issued BEGIN, so this asks
            // SQLite itself rather than PDO's bookkeeping.
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // already rolled back by SQLite (e.g. a failed BEGIN) — nothing to undo
            }
            throw $e;
        }
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }
}
