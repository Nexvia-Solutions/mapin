<?php

declare(strict_types=1);

namespace Mapin\Mcp\Tools;

use Mapin\Mcp\ToolSpec;
use Mapin\Query\Query;

final class RouteTool implements ToolSpec
{
    public function __construct(private readonly Query $query) {}

    public function name(): string
    {
        return 'route';
    }

    public function description(): string
    {
        return 'A route: handler, middleware, views it renders, models it touches.';
    }

    public function arguments(): array
    {
        return [
            'route' => ['type' => 'string', 'required' => true, 'description' => 'Route name, or "METHOD /uri"'],
        ];
    }

    public function handle(array $arguments): array
    {
        $result = $this->query->route((string) $arguments['route']);

        return $this->query->envelope('route', $arguments, $result);
    }
}
