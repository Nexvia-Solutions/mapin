<?php

declare(strict_types=1);

namespace Mapin\Mcp\Tools;

use Mapin\Mcp\ToolSpec;
use Mapin\Query\Query;

/**
 * No arguments: SPEC.md section 6.1's table lists `resolution?` here, but resolution is a Louvain
 * parameter `mapin:communities` itself already takes (`--resolution=1.0`, section 9) - this tool
 * only ever reads whatever that command last computed (its own description says so: "requires
 * mapin:communities to have run"), so a resolution argument here would do nothing. Treated as the
 * same kind of copy-paste drift already found and corrected once in section 3.1's node key table,
 * not implemented as a dead parameter for the sake of matching the table literally.
 */
final class CommunitiesTool implements ToolSpec
{
    public function __construct(private readonly Query $query) {}

    public function name(): string
    {
        return 'communities';
    }

    public function description(): string
    {
        return 'Community membership summary from the last mapin:communities run: size, top nodes and dominant namespaces per community.';
    }

    public function arguments(): array
    {
        return [];
    }

    public function handle(array $arguments): array
    {
        $result = $this->query->communities();

        return $this->query->envelope('communities', $arguments, $result);
    }
}
