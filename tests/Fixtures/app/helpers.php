<?php

declare(strict_types=1);

use Fixture\Services\ShippingCalculator;

function shipping_calculator(): ShippingCalculator
{
    return new ShippingCalculator;
}
