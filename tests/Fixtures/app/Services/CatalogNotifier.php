<?php

declare(strict_types=1);

namespace Fixture\Services;

class CatalogNotifier
{
    public function notifyNewBook(string $title): bool
    {
        return $title !== '';
    }
}
