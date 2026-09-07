<?php

declare(strict_types=1);

namespace Fixture\Contracts;

interface PricingEngine
{
    public function quote(float $amount): float;
}
