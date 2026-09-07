<?php

declare(strict_types=1);

namespace Fixture\Http\Controllers;

use Fixture\Services\TenantContext;

class ReportController
{
    public function __invoke(): int
    {
        return TenantContext::id() ?? 0;
    }
}
