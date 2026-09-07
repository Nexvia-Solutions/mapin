<?php

declare(strict_types=1);

namespace Mapin\Console\Commands;

use Illuminate\Console\Command;
use Mapin\Graph\NodeType;
use Mapin\Query\Query;
use Mapin\Store\SqliteStore;

final class FindCommand extends Command
{
    /** @var string */
    protected $signature = 'mapin:find {name : Key, FQCN, short class name, or method name}
        {--type= : Restrict to one node type (class, method, function, external)}
        {--fuzzy : Allow a partial/look-alike match to stand in for an exact one}
        {--json}';

    /** @var string */
    protected $description = 'Find a node by key, name, or partial match';

    public function handle(): int
    {
        $storagePath = (string) config('mapin.storage');
        if (! file_exists($storagePath)) {
            $this->error('Mapin: no graph found. Run mapin:build first.');

            return 1;
        }

        $type = $this->option('type') !== null ? NodeType::from((string) $this->option('type')) : null;
        $fuzzy = (bool) $this->option('fuzzy');
        $query = new Query(new SqliteStore($storagePath), base_path());
        $result = $query->find((string) $this->argument('name'), $type, $fuzzy);
        $envelope = $query->envelope('find', ['name' => $this->argument('name'), 'type' => $this->option('type'), 'fuzzy' => $fuzzy], $result);

        if ($this->option('json')) {
            $this->line((string) json_encode($envelope, JSON_PRETTY_PRINT));

            return 0;
        }

        if (! $result->found) {
            $this->warn('Mapin: not found.');
            if ($result->suggestions !== []) {
                $this->line('Did you mean: '.implode(', ', array_column($result->suggestions, 'key')).'?');
            }

            return 0;
        }

        $this->table(['Type', 'Name', 'Key', 'File', 'Line'], array_map(
            static fn (array $row) => [$row['type'], $row['name'], $row['key'], $row['file_path'] ?? '(external)', $row['line']],
            $result->result,
        ));

        return 0;
    }
}
