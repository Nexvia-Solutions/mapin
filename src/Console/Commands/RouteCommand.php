<?php

declare(strict_types=1);

namespace Mapin\Console\Commands;

use Illuminate\Console\Command;
use Mapin\Query\Query;
use Mapin\Store\SqliteStore;

final class RouteCommand extends Command
{
    /** @var string */
    protected $signature = 'mapin:route {route : Route name, or "METHOD /uri"} {--json}';

    /** @var string */
    protected $description = 'Show a route: handler, middleware, views it renders, models it touches';

    public function handle(): int
    {
        $storagePath = (string) config('mapin.storage');
        if (! file_exists($storagePath)) {
            $this->error('Mapin: no graph found. Run mapin:build first.');

            return 1;
        }

        $input = (string) $this->argument('route');
        $query = new Query(new SqliteStore($storagePath), base_path());
        $result = $query->route($input);
        $envelope = $query->envelope('route', ['route' => $input], $result);

        if ($this->option('json')) {
            $this->line((string) json_encode($envelope, JSON_PRETTY_PRINT));

            return 0;
        }

        if (! $result->found) {
            $this->warn('Mapin: not found.');

            return 0;
        }

        $r = $result->result;
        $this->line("Route: {$r['key']}");
        $this->line('Name: '.($r['name'] ?? '(none)'));
        $this->line('Action: '.($r['action'] ?? '(none)'));
        $this->line('Middleware: '.($r['middleware'] === [] ? '(none)' : implode(', ', $r['middleware'])));
        $this->line('Views: '.($r['views'] === [] ? '(none found)' : implode(', ', $r['views'])));

        return 0;
    }
}
