<?php

declare(strict_types=1);

namespace Kuyash\Workflow;

/**
 * Validates a workflow's nodes_json against the canonical templates.
 * Runs at save time AND at run start (a stored workflow that drifted —
 * hand-edited DB, future bug — must never start a run).
 *
 * Deliberately strict: the node sequence must EXACTLY equal one template;
 * there is no subset/superset logic (speculative generality). Settings are
 * schema-light: scalar values only, bounded key counts and string lengths —
 * full per-node settings schemas arrive with the editing UI (Phase 5+).
 */
final class WorkflowValidator
{
    private const MAX_SETTINGS_KEYS = 16;
    private const MAX_KEY_LENGTH = 48;
    private const MAX_STRING_LENGTH = 300;

    /**
     * @param mixed $nodes decoded nodes_json (anything — validated from zero)
     *
     * @return list<string> error descriptions; empty list = valid
     */
    public function validate(string $template, mixed $nodes): array
    {
        if (!in_array($template, Nodes::TEMPLATES, true)) {
            return ["unknown template '{$template}'"];
        }

        if (!is_array($nodes) || !array_is_list($nodes)) {
            return ['nodes_json must be a list of node objects'];
        }

        $expected = Nodes::template($template);
        if (count($nodes) !== count($expected)) {
            return [sprintf(
                "template '%s' requires exactly %d nodes, got %d",
                $template,
                count($expected),
                count($nodes),
            )];
        }

        $errors = [];
        foreach ($nodes as $i => $entry) {
            $position = $expected[$i];

            if (!is_array($entry) || !is_string($entry['node'] ?? null)) {
                $errors[] = "node #{$i} is not a node object";
                continue;
            }

            $name = $entry['node'];
            if ($name !== $position) {
                $errors[] = "node #{$i} must be '{$position}', got '{$name}' (canonical order is fixed)";
                continue;
            }

            $mustLock = in_array($name, Nodes::LOCKED, true);
            if (($entry['locked'] ?? null) !== $mustLock) {
                $errors[] = $mustLock
                    ? "node '{$name}' must be locked"
                    : "node '{$name}' must not be locked";
            }

            $settings = $entry['settings'] ?? [];
            foreach ($this->settingsErrors($name, $settings) as $e) {
                $errors[] = $e;
            }

            if ($name === 'VISUALS') {
                $source = is_array($settings) ? ($settings['source'] ?? null) : null;
                if (!in_array($source, Nodes::VISUALS_SOURCES, true)) {
                    $errors[] = "VISUALS settings.source must be one of: "
                        . implode(', ', Nodes::VISUALS_SOURCES);
                }
            }
        }

        return $errors;
    }

    /**
     * Schema-light settings check: a flat map of scalars with bounded sizes.
     * Nested structures are rejected — node settings are simple values by
     * design, not documents.
     *
     * @return list<string>
     */
    private function settingsErrors(string $node, mixed $settings): array
    {
        if (!is_array($settings)) {
            return ["node '{$node}' settings must be a map"];
        }

        if (count($settings) > self::MAX_SETTINGS_KEYS) {
            return ["node '{$node}' settings exceed " . self::MAX_SETTINGS_KEYS . ' keys'];
        }

        $errors = [];
        foreach ($settings as $key => $value) {
            if (!is_string($key) || $key === '' || strlen($key) > self::MAX_KEY_LENGTH) {
                $errors[] = "node '{$node}' has an invalid settings key";
                continue;
            }
            if (is_array($value) || is_object($value)) {
                $errors[] = "node '{$node}' setting '{$key}' must be a scalar (nested structures rejected)";
                continue;
            }
            if (is_string($value) && strlen($value) > self::MAX_STRING_LENGTH) {
                $errors[] = "node '{$node}' setting '{$key}' exceeds " . self::MAX_STRING_LENGTH . ' characters';
            }
        }

        return $errors;
    }
}
