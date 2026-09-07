<?php

declare(strict_types=1);

namespace Mapin\Console\Commands;

use Illuminate\Console\Command;
use Mapin\Query\Query;
use Mapin\Store\SqliteStore;

final class StatsCommand extends Command
{
    /** @var string */
    protected $signature = 'mapin:stats {--json}';

    /** @var string */
    protected $description = 'Show graph size and the last build report';

    public function handle(): int
    {
        $storagePath = (string) config('mapin.storage');
        if (! file_exists($storagePath)) {
            $this->error('Mapin: no graph found. Run mapin:build first.');

            return 1;
        }

        $query = new Query(new SqliteStore($storagePath), base_path());
        $result = $query->stats();
        $envelope = $query->envelope('stats', [], $result);

        if ($this->option('json')) {
            $this->line((string) json_encode($envelope, JSON_PRETTY_PRINT));

            return 0;
        }

        $counts = $result->result['counts'];
        $lastBuild = $result->result['last_build'];

        $this->table(['Metric', 'Count'], [
            ['Files', $counts['files']],
            ['Nodes', $counts['nodes']],
            ['Edges', $counts['edges']],
            ['Unresolved', $counts['unresolved']],
        ]);

        if ($lastBuild !== null) {
            $this->line('');
            $this->line(sprintf(
                'Last build: %s mode, %s, commit %s, %d files changed, %d warnings.',
                $lastBuild['mode'],
                $lastBuild['finished_at'],
                $lastBuild['commit_hash'] ?? 'unknown',
                $lastBuild['files_changed'],
                $lastBuild['warnings'] !== null ? count(json_decode((string) $lastBuild['warnings'], true)) : 0,
            ));
        }

        return 0;
    }
}
