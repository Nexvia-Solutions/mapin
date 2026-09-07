<?php

declare(strict_types=1);

namespace Mapin\Mcp\Adapters;

use Mapin\Mcp\Tools\ImpactTool;
use Mapin\Mcp\ToolSpec;

final class ImpactToolAdapter extends AbstractToolAdapter
{
    public function __construct(private readonly ImpactTool $tool) {}

    protected function toolSpec(): ToolSpec
    {
        return $this->tool;
    }
}
