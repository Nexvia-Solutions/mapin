<?php

declare(strict_types=1);

namespace Fixture\Listeners;

use Fixture\Events\BookPublished;

class NotifySubscribers
{
    public function handle(BookPublished $event): void {}
}
