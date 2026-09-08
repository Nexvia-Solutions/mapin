<?php

declare(strict_types=1);

namespace Mapin\Mcp\Adapters;

use Mapin\Mcp\Tools\CommunitiesTool;
use Mapin\Mcp\ToolSpec;

final class CommunitiesToolAdapter extends AbstractToolAdapter
{
    public function __construct(private readonly CommunitiesTool $tool) {}

    protected function toolSpec(): ToolSpec
    {
        return $this->tool;
    }
}
