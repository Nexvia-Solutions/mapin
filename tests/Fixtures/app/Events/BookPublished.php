<?php

declare(strict_types=1);

namespace Fixture\Events;

class BookPublished
{
    public function __construct(public string $isbn) {}
}
