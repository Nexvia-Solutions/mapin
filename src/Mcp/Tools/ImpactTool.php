<?php

declare(strict_types=1);

namespace Mapin\Mcp\Tools;

use Mapin\Mcp\ToolSpec;
use Mapin\Query\Query;

final class ImpactTool implements ToolSpec
{
    public function __construct(private readonly Query $query) {}

    public function name(): string
    {
        return 'impact';
    }

    public function description(): string
    {
        return 'Everything reachable backward from a node, grouped by kind: routes, views, jobs, commands, methods, classes.';
    }

    public function arguments(): array
    {
        return [
            'key' => ['type' => 'string', 'required' => true, 'description' => 'Exact node key, or a name find() would resolve'],
            'depth' => ['type' => 'integer', 'required' => false, 'default' => 3, 'description' => 'How many hops backward to walk'],
            'min_confidence' => ['type' => 'number', 'required' => false, 'default' => 0, 'description' => 'Drop `calls` edges below this confidence (structural edges are never filtered)'],
        ];
    }

    public function handle(array $arguments): array
    {
        $result = $this->query->impact((string) $arguments['key'], (int) ($arguments['depth'] ?? 3), (float) ($arguments['min_confidence'] ?? 0));

        return $this->query->envelope('impact', $arguments, $result);
    }
}
