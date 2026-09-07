<?php

declare(strict_types=1);

namespace Mapin\Console\Commands;

use Illuminate\Console\Command;
use Mapin\Query\Query;
use Mapin\Store\SqliteStore;

/**
 * Reverse reachability from a node: everything that depends on it, so changing it could affect
 * them (SPEC.md 6.1). Structural edges (routes_to, renders, ...) carry no confidence of their own
 * - they are read from the source, not inferred - so --min-confidence only filters `calls` edges.
 */
final class ImpactCommand extends Command
{
    /** @var string */
    protected $signature = 'mapin:impact {key} {--depth=3} {--min-confidence=0} {--json}';

    /** @var string */
    protected $description = 'List everything reachable backward from a node: routes, views, jobs, commands, callers';

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
        $result = $query->impact($key, $depth, $minConfidence);
        $envelope = $query->envelope('impact', ['key' => $key, 'depth' => $depth, 'min_confidence' => $minConfidence], $result);

        if ($this->option('json')) {
            $this->line((string) json_encode($envelope, JSON_PRETTY_PRINT));

            return 0;
        }

        if (! $result->found) {
            $this->warn('Mapin: not found.');

            return 0;
        }

        foreach ($result->result as $bucket => $items) {
            $this->line(ucfirst($bucket).' ('.count($items).'):');
            foreach ($items as $item) {
                $this->line("  [{$item['depth']}] {$item['key']} (via {$item['via']})");
            }
        }

        return 0;
    }
}
