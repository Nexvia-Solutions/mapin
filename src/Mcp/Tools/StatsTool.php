<?php

declare(strict_types=1);

namespace Mapin\Mcp\Tools;

use Mapin\Mcp\ToolSpec;
use Mapin\Query\Query;

final class StatsTool implements ToolSpec
{
    public function __construct(private readonly Query $query) {}

    public function name(): string
    {
        return 'stats';
    }

    public function description(): string
    {
        return 'Graph size (files, nodes, edges, unresolved) and the last build report.';
    }

    public function arguments(): array
    {
        return [];
    }

    public function handle(array $arguments): array
    {
        $result = $this->query->stats();

        return $this->query->envelope('stats', $arguments, $result);
    }
}
