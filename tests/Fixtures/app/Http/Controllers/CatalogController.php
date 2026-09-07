<?php

declare(strict_types=1);

namespace Fixture\Http\Controllers;

use Fixture\Services\Catalog;

class CatalogController
{
    public function __construct(private Catalog $catalog) {}

    public function show(string $isbn): mixed
    {
        $book = $this->catalog->findBook($isbn);

        return $book->author();
    }
}
