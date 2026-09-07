<?php

declare(strict_types=1);

namespace Mapin\Mcp\Tools;

use Mapin\Mcp\ToolSpec;
use Mapin\Query\Query;

final class CallersTool implements ToolSpec
{
    public function __construct(private readonly Query $query) {}

    public function name(): string
    {
        return 'callers';
    }

    public function description(): string
    {
        return 'Who calls, injects, instantiates or resolves a node - walked backward up to a given depth.';
    }

    public function arguments(): array
    {
        return [
            'key' => ['type' => 'string', 'required' => true, 'description' => 'Exact node key, e.g. "method:App\\Services\\Billing::charge"'],
            'depth' => ['type' => 'integer', 'required' => false, 'default' => 1, 'description' => 'How many hops backward to walk'],
            'min_confidence' => ['type' => 'number', 'required' => false, 'default' => 0, 'description' => 'Drop edges below this confidence'],
        ];
    }

    public function handle(array $arguments): array
    {
        $result = $this->query->callers((string) $arguments['key'], (int) ($arguments['depth'] ?? 1), (float) ($arguments['min_confidence'] ?? 0));

        return $this->query->envelope('callers', $arguments, $result);
    }
}
