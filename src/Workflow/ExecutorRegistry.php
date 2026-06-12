<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

use RuntimeException;

/**
 * job type → executor lookup. Phase 4 registers the single MockExecutor for
 * every type; later phases swap individual types to real adapters with one
 * register() line each (integration rule: mock-first, provider-agnostic).
 */
final class ExecutorRegistry
{
    /** @var array<string, JobExecutor> */
    private array $map = [];

    public function register(string $type, JobExecutor $executor): void
    {
        $this->map[$type] = $executor;
    }

    public function registerForAll(JobExecutor $executor): void
    {
        foreach (Nodes::jobTypes() as $type) {
            $this->register($type, $executor);
        }
    }

    public function for(string $type): JobExecutor
    {
        return $this->map[$type]
            ?? throw new RuntimeException("No executor registered for job type '{$type}'");
    }
}
