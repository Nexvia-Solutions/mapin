<?php

declare(strict_types=1);

namespace Fixture\Observers;

use Fixture\Models\Author;

class AuthorObserver
{
    public function created(Author $author): void {}
}
