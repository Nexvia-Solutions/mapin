<?php

declare(strict_types=1);

namespace Mapin\Console\Commands;

use Illuminate\Console\Command;
use Mapin\Query\Query;
use Mapin\Store\SqliteStore;

final class CallersCommand extends Command
{
    /** @var string */
    protected $signature = 'mapin:callers {key : Exact node key, e.g. "class:App\\Services\\Billing" or "method:App\\Services\\Billing::charge"}
        {--depth=1}
        {--min-confidence=0}
        {--json}';

    /** @var string */
    protected $description = 'List who calls, injects, instantiates or resolves a node';

    public function handle(): int
    {
        $storagePath = (string) config('mapin.storage');
        if (! file_exists($storagePath)) {
            $this->error('Mapin: no graph found. Run mapin:build first.');

            return 1;
        }

        $key = (string) $this->argument('key');
        $depth = (int) $this->option('depth');
        $minConfidence = (float) $this->option('min-confidence');
        $query = new Query(new SqliteStore($storagePath), base_path());
        $result = $query->callers($key, $depth, $minConfidence);
        $envelope = $query->envelope('callers', ['key' => $key, 'depth' => $depth, 'min_confidence' => $minConfidence], $result);

        if ($this->option('json')) {
            $this->line((string) json_encode($envelope, JSON_PRETTY_PRINT));

            return 0;
        }

        if (! $result->found) {
            $this->warn('Mapin: not found.');

            return 0;
        }

        if ($result->result === []) {
            $this->line('No callers found.');

            return 0;
        }

        $this->table(['Depth', 'Caller', 'Edge', 'Resolution', 'Confidence'], array_map(
            static fn (array $c) => [$c['depth'], $c['key'], $c['edge_type'], $c['resolution'], $c['confidence']],
            $result->result,
        ));

        return 0;
    }
}
