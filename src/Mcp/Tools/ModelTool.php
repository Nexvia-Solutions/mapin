<?php

declare(strict_types=1);

namespace Mapin\Mcp\Tools;

use Mapin\Mcp\ToolSpec;
use Mapin\Query\Query;

final class ModelTool implements ToolSpec
{
    public function __construct(private readonly Query $query) {}

    public function name(): string
    {
        return 'model';
    }

    public function description(): string
    {
        return 'An Eloquent model: table, relations, migrations that touch it, observers, call sites.';
    }

    public function arguments(): array
    {
        return [
            'name' => ['type' => 'string', 'required' => true, 'description' => 'FQCN or short class name'],
        ];
    }

    public function handle(array $arguments): array
    {
        $result = $this->query->model((string) $arguments['name']);

        return $this->query->envelope('model', $arguments, $result);
    }
}
