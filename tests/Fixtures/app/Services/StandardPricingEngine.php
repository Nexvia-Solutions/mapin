<?php

declare(strict_types=1);

namespace Fixture\Services;

use Fixture\Contracts\PricingEngine;

class StandardPricingEngine implements PricingEngine
{
    public function quote(float $amount): float
    {
        return round($amount * 1.21, 2);
    }
}
