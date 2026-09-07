<?php

declare(strict_types=1);

namespace Fixture\Providers;

use Fixture\Events\BookPublished;
use Fixture\Listeners\NotifySubscribers;

class BookEventServiceProvider
{
    /** @var array<class-string, array<class-string>> */
    protected $listen = [
        BookPublished::class => [
            NotifySubscribers::class,
        ],
    ];
}
