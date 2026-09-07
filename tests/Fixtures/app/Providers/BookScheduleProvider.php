<?php

declare(strict_types=1);

namespace Fixture\Providers;

use Fixture\Jobs\NotifyNewBook;
use Illuminate\Console\Scheduling\Schedule;

class BookScheduleProvider
{
    public function schedule(Schedule $schedule): void
    {
        $schedule->job(new NotifyNewBook('978-0-00-000000-0'))->daily();
    }
}
