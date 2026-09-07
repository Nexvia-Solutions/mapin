<?php

declare(strict_types=1);

namespace Mapin\Mcp\Adapters;

use Mapin\Mcp\Tools\UnresolvedTool;
use Mapin\Mcp\ToolSpec;

final class UnresolvedToolAdapter extends AbstractToolAdapter
{
    public function __construct(private readonly UnresolvedTool $tool) {}

    protected function toolSpec(): ToolSpec
    {
        return $this->tool;
    }
}
