<?php

declare(strict_types=1);

namespace Mapin\Mcp\Adapters;

use Mapin\Mcp\Tools\NodeTool;
use Mapin\Mcp\ToolSpec;

final class NodeToolAdapter extends AbstractToolAdapter
{
    public function __construct(private readonly NodeTool $tool) {}

    protected function toolSpec(): ToolSpec
    {
        return $this->tool;
    }
}
