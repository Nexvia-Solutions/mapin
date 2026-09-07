<?php

declare(strict_types=1);

namespace Mapin\Mcp\Tools;

use Mapin\Mcp\ToolSpec;
use Mapin\Query\Query;

final class UnresolvedTool implements ToolSpec
{
    public function __construct(private readonly Query $query) {}

    public function name(): string
    {
        return 'unresolved';
    }

    public function description(): string
    {
        return 'Unresolved references, for improving code or the resolver - never a guess presented as a real answer.';
    }

    public function arguments(): array
    {
        return [
            'file' => ['type' => 'string', 'required' => false, 'description' => 'Restrict to file paths containing this substring'],
            'member' => ['type' => 'string', 'required' => false, 'description' => 'Restrict to this exact member name'],
            'limit' => ['type' => 'integer', 'required' => false, 'default' => 50, 'description' => 'Maximum rows to return'],
        ];
    }

    public function handle(array $arguments): array
    {
        $result = $this->query->unresolved($arguments['file'] ?? null, $arguments['member'] ?? null, (int) ($arguments['limit'] ?? 50));

        return $this->query->envelope('unresolved', $arguments, $result);
    }
}
