<?php

declare(strict_types=1);

namespace Mapin\Mcp\Adapters;

use Mapin\Mcp\Tools\HubsTool;
use Mapin\Mcp\ToolSpec;

final class HubsToolAdapter extends AbstractToolAdapter
{
    public function __construct(private readonly HubsTool $tool) {}

    protected function toolSpec(): ToolSpec
    {
        return $this->tool;
    }
}
