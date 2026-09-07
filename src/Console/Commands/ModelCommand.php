<?php

declare(strict_types=1);

namespace Mapin\Console\Commands;

use Illuminate\Console\Command;
use Mapin\Query\Query;
use Mapin\Store\SqliteStore;

final class ModelCommand extends Command
{
    /** @var string */
    protected $signature = 'mapin:model {name : FQCN or short class name} {--json}';

    /** @var string */
    protected $description = 'Show a model: table, relations, migrations that touch it, observers, callers';

    public function handle(): int
    {
        $storagePath = (string) config('mapin.storage');
        if (! file_exists($storagePath)) {
            $this->error('Mapin: no graph found. Run mapin:build first.');

            return 1;
        }

        $name = (string) $this->argument('name');
        $query = new Query(new SqliteStore($storagePath), base_path());
        $result = $query->model($name);
        $envelope = $query->envelope('model', ['name' => $name], $result);

        if ($this->option('json')) {
            $this->line((string) json_encode($envelope, JSON_PRETTY_PRINT));

            return 0;
        }

        if (! $result->found) {
            $this->warn('Mapin: not found.');

            return 0;
        }

        $r = $result->result;
        $this->line("Model: {$r['key']}");
        $this->line('Table: '.($r['table'] ?? '(none)'));
        $this->line('Relations: '.($r['relations'] === [] ? '(none)' : implode(', ', array_map(static fn (array $rel) => "{$rel['relation']} {$rel['target']}", $r['relations']))));
        $this->line('Observers: '.($r['observers'] === [] ? '(none)' : implode(', ', $r['observers'])));
        $this->line('Migrations touching table: '.($r['migrations_touching_table'] === [] ? '(none)' : implode(', ', $r['migrations_touching_table'])));
        $this->line("Call sites: {$r['call_sites']}");

        return 0;
    }
}
