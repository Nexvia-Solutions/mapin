<?php

declare(strict_types=1);

namespace Fixture\Http\Controllers;

use Fixture\Contracts\PricingEngine;
use Fixture\Models\Author;
use Fixture\Services\CatalogNotifier;
use Fixture\Services\InventoryLedger;
use Fixture\Services\ShippingCalculator;
use Fixture\Services\TenantContext;

class BookController
{
    public function __construct(protected CatalogNotifier $notifier, private PricingEngine $pricing) {}

    public function store(string $title, float $weight): float
    {
        TenantContext::setCurrent(1);
        $tenantId = TenantContext::id();

        $this->notifier->notifyNewBook($title);

        $shipping = new ShippingCalculator;
        $cost = $shipping->estimate($weight);

        app(InventoryLedger::class)->recordSale('978-0-00-000000-0');

        return $this->pricing->quote($cost) + (float) $tenantId;
    }

    public function authorBooks(Author $author): int
    {
        return $author->books()->where('published', true)->count() ?? 0;
    }

    public function safeStore(string $title, float $weight): float
    {
        try {
            return $this->store($title, $weight);
        } catch (\RuntimeException $e) {
            report($e->getMessage());

            return 0.0;
        }
    }

    public function quickEstimate(float $weight): float
    {
        return shipping_calculator()->estimate($weight);
    }
}
