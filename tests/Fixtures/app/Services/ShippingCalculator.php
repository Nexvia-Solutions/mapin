<?php

declare(strict_types=1);

namespace Fixture\Services;

class ShippingCalculator
{
    public function estimate(float $weight): float
    {
        return $weight * 2.5;
    }
}
