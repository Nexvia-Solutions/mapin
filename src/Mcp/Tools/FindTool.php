<?php

declare(strict_types=1);

namespace Mapin\Mcp\Tools;

use Mapin\Graph\NodeType;
use Mapin\Mcp\ToolSpec;
use Mapin\Query\Query;

final class FindTool implements ToolSpec
{
    public function __construct(private readonly Query $query) {}

    public function name(): string
    {
        return 'find';
    }

    public function description(): string
    {
        return 'Find a node by key, FQCN, short class name, method name, route name/URI, view name, or table name.';
    }

    public function arguments(): array
    {
        return [
            'name' => ['type' => 'string', 'required' => true, 'description' => 'Key, FQCN, short name, route name/URI, view name, or table name'],
            'type' => ['type' => 'string', 'required' => false, 'description' => 'Restrict to one node type', 'enum' => array_map(static fn (NodeType $t) => $t->value, NodeType::cases())],
            'fuzzy' => ['type' => 'boolean', 'required' => false, 'default' => false, 'description' => 'Allow a partial/look-alike match to stand in for an exact one'],
        ];
    }

    public function handle(array $arguments): array
    {
        $type = isset($arguments['type']) && $arguments['type'] !== null ? NodeType::from((string) $arguments['type']) : null;
        $result = $this->query->find((string) $arguments['name'], $type, (bool) ($arguments['fuzzy'] ?? false));

        return $this->query->envelope('find', $arguments, $result);
    }
}
