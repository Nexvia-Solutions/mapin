<?php

declare(strict_types=1);

namespace Mapin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Mapin\Store\SqliteStore;

/**
 * mapin:misses's sibling for the other kind of signal a build already produces and used to lose:
 * a file that fails to parse/compile (SPEC.md 1.17/1.21's own graceful-degradation path - never
 * aborts the build, but the warning itself only ever lived inside that one build's own row in
 * `builds`, easy to miss and never deduplicated across the many builds a real file keeps failing
 * on until someone actually fixes it). Same host-project export boundary as mapin:misses, and the
 * same one deliberate exception to SPEC.md section 13's "never write outside storage/" - only when
 * a human runs this on purpose, never during mapin:build or any query. Purely local start to
 * finish: no network call, ever - see SPEC.md section 13 point 7.
 */
final class WarningsCommand extends Command
{
    /** @var string */
    protected $signature = 'mapin:warnings {--output=docs/mapin-warnings.jsonl : Path relative to the project root} {--json}';

    /** @var string */
    protected $description = 'Export files that failed to parse/compile during a build to a JSONL file for the team to review';

    public function handle(): int
    {
        $storagePath = (string) config('mapin.storage');
        if (! file_exists($storagePath)) {
            $this->error('Mapin: no graph found. Run mapin:build first.');

            return 1;
        }

        $store = new SqliteStore($storagePath);
        $warnings = $store->unexportedBuildWarnings();

        if ($warnings === []) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['exported' => 0, 'path' => null], JSON_PRETTY_PRINT));
            } else {
                $this->info('Mapin: nothing new to export - every recorded warning already made it into the file.');
            }

            return 0;
        }

        $outputPath = base_path((string) $this->option('output'));
        File::ensureDirectoryExists(dirname($outputPath));

        $lines = array_map(static function (array $warning): string {
            return (string) json_encode([
                'warning' => $warning['warning'],
                'first_seen_at' => $warning['first_seen_at'],
                'last_seen_at' => $warning['last_seen_at'],
            ]);
        }, $warnings);

        File::append($outputPath, implode("\n", $lines)."\n");

        $exportedAt = gmdate('c');
        $store->markBuildWarningsExported(array_map(static fn (array $w) => (int) $w['id'], $warnings), $exportedAt);

        if ($this->option('json')) {
            $this->line((string) json_encode(['exported' => count($warnings), 'path' => $outputPath], JSON_PRETTY_PRINT));
        } else {
            $this->info(sprintf('Mapin: exported %d new warning(s) to %s.', count($warnings), $outputPath));
        }

        return 0;
    }
}
