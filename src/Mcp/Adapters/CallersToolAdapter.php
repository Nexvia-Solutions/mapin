<?php

declare(strict_types=1);

namespace Mapin\Mcp\Adapters;

use Mapin\Mcp\Tools\CallersTool;
use Mapin\Mcp\ToolSpec;

final class CallersToolAdapter extends AbstractToolAdapter
{
    public function __construct(private readonly CallersTool $tool) {}

    protected function toolSpec(): ToolSpec
    {
        return $this->tool;
    }
}
