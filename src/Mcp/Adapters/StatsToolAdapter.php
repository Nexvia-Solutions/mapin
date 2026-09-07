<?php

declare(strict_types=1);

namespace Mapin\Mcp\Adapters;

use Mapin\Mcp\Tools\StatsTool;
use Mapin\Mcp\ToolSpec;

final class StatsToolAdapter extends AbstractToolAdapter
{
    public function __construct(private readonly StatsTool $tool) {}

    protected function toolSpec(): ToolSpec
    {
        return $this->tool;
    }
}
