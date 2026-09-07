<?php

declare(strict_types=1);

namespace Mapin\Mcp\Tools;

use Mapin\Mcp\ToolSpec;
use Mapin\Query\Query;

final class ViewTool implements ToolSpec
{
    public function __construct(private readonly Query $query) {}

    public function name(): string
    {
        return 'view';
    }

    public function description(): string
    {
        return 'A Blade view: who renders it, what it includes, its components, routes it links to.';
    }

    public function arguments(): array
    {
        return [
            'name' => ['type' => 'string', 'required' => true, 'description' => 'Dot-notation view name, e.g. "admin.orders.show"'],
        ];
    }

    public function handle(array $arguments): array
    {
        $result = $this->query->view((string) $arguments['name']);

        return $this->query->envelope('view', $arguments, $result);
    }
}
