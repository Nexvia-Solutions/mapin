<?php

declare(strict_types=1);

namespace Mapin\Mcp\Tools;

use Mapin\Mcp\ToolSpec;
use Mapin\Query\Query;

final class CalleesTool implements ToolSpec
{
    public function __construct(private readonly Query $query) {}

    public function name(): string
    {
        return 'callees';
    }

    public function description(): string
    {
        return 'What a node calls, renders, or dispatches - walked forward up to a given depth.';
    }

    public function arguments(): array
    {
        return [
            'key' => ['type' => 'string', 'required' => true, 'description' => 'Exact node key, e.g. "method:App\\Http\\Controllers\\OrderController::store"'],
            'depth' => ['type' => 'integer', 'required' => false, 'default' => 1, 'description' => 'How many hops forward to walk'],
        ];
    }

    public function handle(array $arguments): array
    {
        $result = $this->query->callees((string) $arguments['key'], (int) ($arguments['depth'] ?? 1));

        return $this->query->envelope('callees', $arguments, $result);
    }
}
