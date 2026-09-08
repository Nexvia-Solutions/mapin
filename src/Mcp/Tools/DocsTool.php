<?php

declare(strict_types=1);

namespace Mapin\Mcp\Tools;

use Mapin\Mcp\ToolSpec;
use Mapin\Query\Query;

final class DocsTool implements ToolSpec
{
    public function __construct(private readonly Query $query) {}

    public function name(): string
    {
        return 'docs';
    }

    public function description(): string
    {
        return 'Sections that document a node, or the nodes a section documents.';
    }

    public function arguments(): array
    {
        return [
            'key' => ['type' => 'string', 'required' => true, 'description' => 'Any node key, e.g. "class:App\\Models\\Order" or "section:docs/billing.md#refunds"'],
        ];
    }

    public function handle(array $arguments): array
    {
        $result = $this->query->docs((string) $arguments['key']);

        return $this->query->envelope('docs', $arguments, $result);
    }
}
