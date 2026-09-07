<?php

declare(strict_types=1);

namespace Fixture\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyNewBook implements ShouldQueue
{
    public function __construct(public string $isbn) {}

    public function handle(): void {}
}
