<?php

declare(strict_types=1);

namespace Mapin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Mapin\Store\SqliteStore;

/**
 * The one deliberate exception to SPEC.md section 13's "never write outside storage/" - and only
 * when a human runs this command on purpose, never during mapin:build or any query. Reads
 * query_misses (populated locally by Query::envelope() on every found: false, SPEC.md section 6.2)
 * and appends whatever hasn't been exported yet to a JSONL file inside the HOST project - never
 * inside this package's own vendor/ directory, so a real team can commit it, review it together, and
 * use it to find real gaps in what the graph resolves. Purely local start to finish: this command
 * never makes a network call, so a third-party install of this package never reports anything about
 * that project back to anyone.
 */
final class MissesCommand extends Command
{
    /** @var string */
    protected $signature = 'mapin:misses {--output=docs/mapin-misses.jsonl : Path relative to the project root} {--json}';

    /** @var string */
    protected $description = 'Export queries the graph could not answer (found: false) to a JSONL file for the team to review';

    public function handle(): int
    {
        $storagePath = (string) config('mapin.storage');
        if (! file_exists($storagePath)) {
            $this->error('Mapin: no graph found. Run mapin:build first.');

            return 1;
        }

        $store = new SqliteStore($storagePath);
        $misses = $store->unexportedQueryMisses();

        if ($misses === []) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['exported' => 0, 'path' => null], JSON_PRETTY_PRINT));
            } else {
                $this->info('Mapin: nothing new to export - every recorded miss already made it into the file.');
            }

            return 0;
        }

        $outputPath = base_path((string) $this->option('output'));
        File::ensureDirectoryExists(dirname($outputPath));

        $lines = array_map(static function (array $miss): string {
            return (string) json_encode([
                'tool' => $miss['tool'],
                'args' => $miss['args'] !== null ? json_decode($miss['args'], true) : null,
                'suggestions' => $miss['suggestions'] !== null ? json_decode($miss['suggestions'], true) : [],
                'occurred_at' => $miss['occurred_at'],
            ]);
        }, $misses);

        File::append($outputPath, implode("\n", $lines)."\n");

        $exportedAt = gmdate('c');
        $store->markQueryMissesExported(array_map(static fn (array $m) => (int) $m['id'], $misses), $exportedAt);

        if ($this->option('json')) {
            $this->line((string) json_encode(['exported' => count($misses), 'path' => $outputPath], JSON_PRETTY_PRINT));
        } else {
            $this->info(sprintf('Mapin: exported %d new miss(es) to %s.', count($misses), $outputPath));
        }

        return 0;
    }
}
