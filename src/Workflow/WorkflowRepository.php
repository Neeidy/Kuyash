<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

use Kuyash\Core\Database;
use Kuyash\Workspace\WorkspaceContext;
use RuntimeException;

/**
 * Workspace-scoped workflow reads + the default seed. Every query filters by
 * workspace_id (tenant isolation at query level). Transactional writes that
 * belong to run execution live in Engine, not here.
 */
final class WorkflowRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly WorkflowValidator $validator,
    ) {
    }

    /**
     * Idempotent per-template seed: a workspace gets one "Full pipeline" and
     * one "Distribution" workflow on first visit. Code-side because a
     * migration cannot know workspace ids.
     */
    public function ensureDefaults(WorkspaceContext $ctx): void
    {
        $defaults = [
            Nodes::TEMPLATE_FULL => 'Full pipeline',
            Nodes::TEMPLATE_DISTRIBUTION => 'Distribution',
            Nodes::TEMPLATE_QUICK_CREATE => 'Quick Create',
        ];

        foreach ($defaults as $template => $name) {
            $exists = $this->db->one(
                'SELECT id FROM workflows WHERE workspace_id = ? AND template = ? LIMIT 1',
                [$ctx->id(), $template],
            );
            if ($exists !== null) {
                continue;
            }

            $nodes = Nodes::defaultNodes($template);
            $errors = $this->validator->validate($template, $nodes);
            if ($errors !== []) {
                // would mean Nodes::defaultNodes and the validator disagree — a bug, not user input
                throw new RuntimeException('Default workflow invalid: ' . implode('; ', $errors));
            }

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $this->db->run(
                'INSERT INTO workflows (workspace_id, name, template, nodes_json, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $ctx->id(),
                    $name,
                    $template,
                    json_encode($nodes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    $now,
                    $now,
                ],
            );
        }
    }

    /**
     * The generic builder list — EXCLUDES quick_create, which has its own entry
     * surface (/quick: photo + prompt), not the node-track builder/run trigger.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(WorkspaceContext $ctx): array
    {
        return array_map(self::shape(...), $this->db->all(
            "SELECT * FROM workflows WHERE workspace_id = ? AND template != 'quick_create' ORDER BY id ASC",
            [$ctx->id()],
        ));
    }

    /** The workspace's workflow for a given template (e.g. quick_create), or null. */
    public function findByTemplate(WorkspaceContext $ctx, string $template): ?array
    {
        $row = $this->db->one(
            'SELECT * FROM workflows WHERE workspace_id = ? AND template = ? ORDER BY id ASC LIMIT 1',
            [$ctx->id(), $template],
        );

        return $row === null ? null : self::shape($row);
    }

    /** @return array<string, mixed>|null null = not found OR other tenant's workflow */
    public function find(WorkspaceContext $ctx, int $id): ?array
    {
        $row = $this->db->one(
            'SELECT * FROM workflows WHERE id = ? AND workspace_id = ?',
            [$id, $ctx->id()],
        );

        return $row === null ? null : self::shape($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $nodes = json_decode((string) $row['nodes_json'], true);
        $row['nodes'] = is_array($nodes) ? $nodes : [];
        $row['id'] = (int) $row['id'];
        $row['workspace_id'] = (int) $row['workspace_id'];

        return $row;
    }
}
