<?php

declare(strict_types=1);

namespace Mapin\Mcp\Adapters;

use Mapin\Mcp\Tools\FindTool;
use Mapin\Mcp\ToolSpec;

final class FindToolAdapter extends AbstractToolAdapter
{
    public function __construct(private readonly FindTool $tool) {}

    protected function toolSpec(): ToolSpec
    {
        return $this->tool;
    }
}
