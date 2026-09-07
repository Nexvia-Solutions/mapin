<?php

declare(strict_types=1);

namespace Mapin\Mcp\Tools;

use Mapin\Mcp\ToolSpec;
use Mapin\Query\Query;

final class PathTool implements ToolSpec
{
    public function __construct(private readonly Query $query) {}

    public function name(): string
    {
        return 'path';
    }

    public function description(): string
    {
        return 'Shortest forward edge path from one node to another, up to a maximum depth.';
    }

    public function arguments(): array
    {
        return [
            'from' => ['type' => 'string', 'required' => true, 'description' => 'Starting node key'],
            'to' => ['type' => 'string', 'required' => true, 'description' => 'Target node key'],
            'max_depth' => ['type' => 'integer', 'required' => false, 'default' => 6, 'description' => 'Give up searching beyond this many hops'],
        ];
    }

    public function handle(array $arguments): array
    {
        $result = $this->query->path((string) $arguments['from'], (string) $arguments['to'], (int) ($arguments['max_depth'] ?? 6));

        return $this->query->envelope('path', $arguments, $result);
    }
}
