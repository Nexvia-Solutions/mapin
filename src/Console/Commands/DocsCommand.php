<?php

declare(strict_types=1);

namespace Mapin\Console\Commands;

use Illuminate\Console\Command;
use Mapin\Extract\Markdown\Semantic\LlmClientFactory;
use Mapin\Extract\Markdown\Semantic\LlmConceptExtractor;
use Mapin\Query\GitStatus;
use Mapin\Query\Query;
use Mapin\Store\SqliteStore;

/**
 * Markdown files are discovered and extracted by the same mapin:build pipeline as everything else
 * (SPEC.md section 7: "separate extractor, same graph", not a separate build) - so `--with-llm` is
 * the one thing here that actually cannot live in mapin:build itself, since a plain build must stay
 * network-free (SPEC.md section 13). Without the flag this is still just mapin:build plus a
 * docs-shaped report; with it, LlmConceptExtractor runs afterward against the graph that build just
 * produced, and only ever runs from here.
 */
final class DocsCommand extends Command
{
    /** @var string */
    protected $signature = 'mapin:docs
        {--full : Discard the existing graph and rebuild everything from scratch}
        {--paths= : Comma-separated project paths, overriding config(mapin.paths)}
        {--with-llm : Also run the semantic layer (concepts and relations) via config(mapin.llm)}
        {--json : Print the docs summary as JSON}';

    /** @var string */
    protected $description = 'Build the graph, report Markdown doc/section coverage, and optionally extract LLM concepts';

    public function handle(): int
    {
        // callSilently, not call: mapin:build's own report is about the whole graph, not the docs
        // slice this command exists to report on, and mixing the two would break --json's promise
        // of a single parseable object - php artisan mapin:build is still there directly for the
        // full report, this is not the only way to build.
        $exitCode = $this->callSilently('mapin:build', array_filter([
            '--full' => $this->option('full'),
            '--paths' => $this->option('paths'),
        ]));
        if ($exitCode !== 0) {
            if (! $this->option('json')) {
                $this->error('Mapin: build failed, run php artisan mapin:build directly for details.');
            }

            return $exitCode;
        }

        $storagePath = (string) config('mapin.storage');
        $store = new SqliteStore($storagePath);
        $query = new Query($store, base_path(), new GitStatus);
        $summary = $query->docsSummary()->result;

        if ($this->option('with-llm')) {
            $client = LlmClientFactory::fromConfig((array) config('mapin.llm'));
            $report = (new LlmConceptExtractor($client))->run(base_path(), $store)->toArray();
            $summary['llm'] = ['driver' => (string) config('mapin.llm.driver'), ...$report];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT));

            return 0;
        }

        $this->info(sprintf(
            'Mapin docs: %d doc files, %d sections, %d documents edges, %d links_doc edges, %d unresolved.',
            $summary['docs'],
            $summary['sections'],
            $summary['documents_edges'],
            $summary['links_doc_edges'],
            $summary['unresolved'],
        ));

        if (isset($summary['llm'])) {
            $llm = $summary['llm'];
            $this->info(sprintf(
                'Mapin docs (llm, driver=%s): %d sections processed, %d skipped (cached), %d concepts, %d relations.',
                $llm['driver'],
                $llm['sections_processed'],
                $llm['sections_skipped'],
                $llm['concepts_found'],
                $llm['relations_found'],
            ));
            foreach ($llm['warnings'] as $warning) {
                $this->warn($warning);
            }
        }

        return 0;
    }
}
