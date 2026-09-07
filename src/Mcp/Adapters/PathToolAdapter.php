<?php

declare(strict_types=1);

namespace Mapin\Mcp\Adapters;

use Mapin\Mcp\Tools\PathTool;
use Mapin\Mcp\ToolSpec;

final class PathToolAdapter extends AbstractToolAdapter
{
    public function __construct(private readonly PathTool $tool) {}

    protected function toolSpec(): ToolSpec
    {
        return $this->tool;
    }
}
