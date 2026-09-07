<?php

declare(strict_types=1);

namespace Mapin\Mcp\Adapters;

use Mapin\Mcp\Tools\CalleesTool;
use Mapin\Mcp\ToolSpec;

final class CalleesToolAdapter extends AbstractToolAdapter
{
    public function __construct(private readonly CalleesTool $tool) {}

    protected function toolSpec(): ToolSpec
    {
        return $this->tool;
    }
}
