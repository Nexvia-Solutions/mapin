<?php

declare(strict_types=1);

namespace Mapin\Mcp\Adapters;

use Mapin\Mcp\Tools\ModelTool;
use Mapin\Mcp\ToolSpec;

final class ModelToolAdapter extends AbstractToolAdapter
{
    public function __construct(private readonly ModelTool $tool) {}

    protected function toolSpec(): ToolSpec
    {
        return $this->tool;
    }
}
