<?php

declare(strict_types=1);

namespace Fixture\Http\Controllers;

use Fixture\Events\BookPublished;
use Fixture\Jobs\NotifyNewBook;
use Fixture\Listeners\NotifySubscribers;
use Fixture\Models\Author;
use Fixture\Observers\AuthorObserver;
use Illuminate\Support\Facades\Event;

class DashboardController
{
    public function index(): mixed
    {
        Author::observe(AuthorObserver::class);
        Event::listen(BookPublished::class, NotifySubscribers::class);

        NotifyNewBook::dispatch('978-0-00-000000-0');

        return view('dashboard.index');
    }

    public function bookIndexLink(): string
    {
        return route('dashboard.index');
    }
}
