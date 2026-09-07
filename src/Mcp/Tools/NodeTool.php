<?php

declare(strict_types=1);

namespace Mapin\Mcp\Tools;

use Mapin\Mcp\ToolSpec;
use Mapin\Query\Query;

final class NodeTool implements ToolSpec
{
    public function __construct(private readonly Query $query) {}

    public function name(): string
    {
        return 'node';
    }

    public function description(): string
    {
        return 'Node details: name, type, file, line, meta, and outgoing/incoming edge counts by type.';
    }

    public function arguments(): array
    {
        return [
            'key' => ['type' => 'string', 'required' => true, 'description' => 'Exact node key, e.g. "class:App\\Services\\Billing"'],
        ];
    }

    public function handle(array $arguments): array
    {
        $result = $this->query->node((string) $arguments['key']);

        return $this->query->envelope('node', $arguments, $result);
    }
}
