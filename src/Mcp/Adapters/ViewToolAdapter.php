<?php

declare(strict_types=1);

namespace Mapin\Mcp\Adapters;

use Mapin\Mcp\Tools\ViewTool;
use Mapin\Mcp\ToolSpec;

final class ViewToolAdapter extends AbstractToolAdapter
{
    public function __construct(private readonly ViewTool $tool) {}

    protected function toolSpec(): ToolSpec
    {
        return $this->tool;
    }
}
