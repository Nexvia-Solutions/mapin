<?php

declare(strict_types=1);

namespace Mapin\Mcp\Adapters;

use Mapin\Mcp\Tools\RouteTool;
use Mapin\Mcp\ToolSpec;

final class RouteToolAdapter extends AbstractToolAdapter
{
    public function __construct(private readonly RouteTool $tool) {}

    protected function toolSpec(): ToolSpec
    {
        return $this->tool;
    }
}
