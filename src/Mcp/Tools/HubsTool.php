<?php

declare(strict_types=1);

namespace Mapin\Mcp\Tools;

use Mapin\Mcp\ToolSpec;
use Mapin\Query\Query;

final class HubsTool implements ToolSpec
{
    public function __construct(private readonly Query $query) {}

    public function name(): string
    {
        return 'hubs';
    }

    public function description(): string
    {
        return 'Highest degree nodes in the call/injection graph, vendor code and facades excluded.';
    }

    public function arguments(): array
    {
        return [
            'limit' => ['type' => 'integer', 'required' => false, 'default' => 20, 'description' => 'Maximum number of hubs to return'],
            'types' => ['type' => 'string', 'required' => false, 'description' => 'Comma-separated node types to restrict results to, e.g. "class,view"'],
        ];
    }

    public function handle(array $arguments): array
    {
        $types = isset($arguments['types']) && $arguments['types'] !== null && $arguments['types'] !== ''
            ? array_map('trim', explode(',', (string) $arguments['types']))
            : null;
        $result = $this->query->hubs((int) ($arguments['limit'] ?? 20), $types);

        return $this->query->envelope('hubs', $arguments, $result);
    }
}
