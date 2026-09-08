<?php

declare(strict_types=1);

namespace Mapin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Mapin\BuildRunner;
use Mapin\Extract\Contracts\ExtractionContext;

final class BuildCommand extends Command
{
    /** @var string */
    protected $signature = 'mapin:build
        {--full : Discard the existing graph and rebuild everything from scratch}
        {--no-boot : Skip route and container binding extraction, which need a booted app}
        {--paths= : Comma-separated project paths, overriding config(mapin.paths)}
        {--json : Print the build report as JSON}';

    /** @var string */
    protected $description = 'Build or incrementally update the Mapin code graph';

    public function handle(): int
    {
        $root = base_path();
        $runner = new BuildRunner;

        $projectPaths = $this->option('paths') !== null
            ? array_filter(array_map('trim', explode(',', (string) $this->option('paths'))))
            : (array) config('mapin.paths');

        $configuredVendorPaths = config('mapin.vendor_paths');
        $vendorPaths = is_array($configuredVendorPaths) ? $configuredVendorPaths : $runner->autoDetectVendorPaths($root);

        // Command::$laravel is typed against the Application contract, but the object behind it is
        // always the real Illuminate\Foundation\Application, which extends the concrete container -
        // BindingExtractor needs getBindings(), which only exists on the concrete class.
        $container = $this->laravel instanceof Container ? $this->laravel : null;

        $ctx = $this->option('no-boot')
            ? new ExtractionContext($root, booted: false)
            : new ExtractionContext($root, booted: true, router: $this->laravel->make('router'), container: $container);

        // config('mapin.extractors'): a host app or third-party plugin's own Extractor
        // implementations (SPEC.md section 4 - "registered through the service provider"),
        // resolved through the container here rather than in BuildRunner itself, which stays
        // usable with no Laravel application booted at all (bin/benchmark, tests).
        $extraExtractors = array_map(
            fn (string $class) => $this->laravel->make($class),
            (array) config('mapin.extractors', []),
        );

        $report = $runner->run(
            $root,
            (string) config('mapin.storage'),
            $projectPaths,
            $vendorPaths,
            (array) config('mapin.exclude'),
            (bool) $this->option('full'),
            $ctx,
            $extraExtractors,
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT));
        } else {
            $this->info(sprintf(
                'Mapin: %s build in %.2fs, %d files seen, %d changed, %d affected, %d deleted, %d nodes, %d edges, %d unresolved.',
                $report->mode,
                $report->durationSeconds,
                $report->filesSeen,
                $report->filesChanged,
                $report->filesAffected,
                $report->filesDeleted,
                $report->nodes,
                $report->edges,
                $report->unresolved,
            ));
            foreach ($report->warnings as $warning) {
                $this->warn($warning);
            }
        }

        return 0;
    }
}
