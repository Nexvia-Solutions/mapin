<?php

declare(strict_types=1);

namespace Mapin\Mcp\Adapters;

use Mapin\Mcp\Tools\DocsTool;
use Mapin\Mcp\ToolSpec;

final class DocsToolAdapter extends AbstractToolAdapter
{
    public function __construct(private readonly DocsTool $tool) {}

    protected function toolSpec(): ToolSpec
    {
        return $this->tool;
    }
}
