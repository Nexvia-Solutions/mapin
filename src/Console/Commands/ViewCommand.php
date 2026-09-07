<?php

declare(strict_types=1);

namespace Mapin\Console\Commands;

use Illuminate\Console\Command;
use Mapin\Query\Query;
use Mapin\Store\SqliteStore;

final class ViewCommand extends Command
{
    /** @var string */
    protected $signature = 'mapin:view {name : Dot-notation view name, e.g. admin.orders.show} {--json}';

    /** @var string */
    protected $description = 'Show a view: who renders it, what it includes, its components, routes it links to';

    public function handle(): int
    {
        $storagePath = (string) config('mapin.storage');
        if (! file_exists($storagePath)) {
            $this->error('Mapin: no graph found. Run mapin:build first.');

            return 1;
        }

        $name = (string) $this->argument('name');
        $query = new Query(new SqliteStore($storagePath), base_path());
        $result = $query->view($name);
        $envelope = $query->envelope('view', ['name' => $name], $result);

        if ($this->option('json')) {
            $this->line((string) json_encode($envelope, JSON_PRETTY_PRINT));

            return 0;
        }

        if (! $result->found) {
            $this->warn('Mapin: not found.');

            return 0;
        }

        $r = $result->result;
        $this->line("View: {$r['key']}");
        foreach (['rendered_by', 'includes', 'included_by', 'components', 'links_to_routes'] as $field) {
            $this->line(ucfirst(str_replace('_', ' ', $field)).': '.($r[$field] === [] ? '(none)' : implode(', ', $r[$field])));
        }

        return 0;
    }
}
