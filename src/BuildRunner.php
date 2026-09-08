<?php

declare(strict_types=1);

namespace Mapin;

use Mapin\Extract\Blade\BladeExtractor;
use Mapin\Extract\Container\BindingExtractor;
use Mapin\Extract\Contracts\ExtractionContext;
use Mapin\Extract\Contracts\Extractor;
use Mapin\Extract\Contracts\Fragment;
use Mapin\Extract\Contracts\SourceFile;
use Mapin\Extract\Contracts\UnresolvedRow;
use Mapin\Extract\Discovery\FileDiscovery;
use Mapin\Extract\Discovery\Hasher;
use Mapin\Extract\Js\JsExtractor;
use Mapin\Extract\Markdown\MarkdownExtractor;
use Mapin\Extract\Php\PhpExtractor;
use Mapin\Extract\Routes\RouteExtractor;
use Mapin\Extract\SymbolIndex;
use Mapin\Graph\Edge;
use Mapin\Resolve\Resolver;
use Mapin\Store\BuildReport;
use Mapin\Store\SqliteStore;

/**
 * The full discover -> extract -> index -> resolve -> store -> report pipeline (SPEC.md section
 * 2), independent of Artisan or a booted Laravel application. BuildCommand is a thin wrapper
 * around this so the same logic runs from `bin/benchmark` and from tests without needing the
 * console kernel.
 */
final class BuildRunner
{
    /**
     * @param  string[]  $projectPaths
     * @param  string[]  $vendorPaths
     * @param  string[]  $exclude
     * @param  Extractor[]  $extraExtractors  host app or third-party plugin extractors (SPEC.md
     *                                        section 4: "registered through the service provider"
     *                                        - BuildCommand resolves config('mapin.extractors')
     *                                        into instances and passes them here, since this class
     *                                        itself stays container-agnostic (it also runs from
     *                                        bin/benchmark, with no Laravel application booted)
     */
    public function run(
        string $root,
        string $storagePath,
        array $projectPaths,
        array $vendorPaths,
        array $exclude,
        bool $full,
        ?ExtractionContext $ctx = null,
        array $extraExtractors = [],
    ): BuildReport {
        $start = microtime(true);
        $ctx ??= new ExtractionContext($root, booted: false);

        if ($full) {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($storagePath.$suffix);
            }
        }

        $store = new SqliteStore($storagePath);

        $projectFiles = iterator_to_array((new FileDiscovery($root, $projectPaths, $exclude, isProject: true))->discover());
        $vendorFiles = iterator_to_array((new FileDiscovery($root, $vendorPaths, $exclude, isProject: false))->discover());
        $allFiles = array_merge($projectFiles, $vendorFiles);

        $storedHashes = $store->fileHashes();
        $discoveredPaths = array_map(static fn (SourceFile $f) => $f->relativePath, $allFiles);
        $deletedPaths = array_values(array_diff(array_keys($storedHashes), $discoveredPaths));

        /** @var SourceFile[] $changedFiles */
        $changedFiles = array_values(array_filter(
            $allFiles,
            static fn (SourceFile $f) => $full || ! isset($storedHashes[$f->relativePath]) || $storedHashes[$f->relativePath] !== $f->hash,
        ));

        if ($deletedPaths !== []) {
            $store->deleteFiles($deletedPaths);
        }

        $phpExtractor = new PhpExtractor;
        /** @var Extractor[] */
        $fileExtractors = [$phpExtractor, new BladeExtractor, ...$extraExtractors];

        $warnings = [];
        $fragments = [];
        foreach ($changedFiles as $file) {
            $fragment = $this->extractWith($fileExtractors, $file, $ctx);
            if ($fragment !== null) {
                $fragments[$file->relativePath] = $fragment;
                $warnings = array_merge($warnings, $fragment->warnings);
            }
        }

        $fileIds = [];
        foreach ($changedFiles as $file) {
            $fileIds[$file->relativePath] = $store->upsertFile($file, strlen($file->contents()));
        }

        // Read declared symbols for the changed files as still stored (pre-change) before anything
        // below overwrites their nodes, then compare against what they declare now. The union is
        // every symbol this build actually changed, added or removed.
        $affectedFiles = [];
        if (! $full && $changedFiles !== []) {
            $affectedFiles = $this->findAffectedFiles($root, $store, $phpExtractor, $changedFiles, $fileIds);
            foreach ($affectedFiles as $file) {
                $fragment = $this->extractWith($fileExtractors, $file, $ctx);
                if ($fragment !== null) {
                    $fragments[$file->relativePath] = $fragment;
                    $warnings = array_merge($warnings, $fragment->warnings);
                }
                $fileIds[$file->relativePath] = $store->fileId($file->relativePath);
            }
        }

        $filesToResolve = array_merge($changedFiles, $affectedFiles);

        $index = new SymbolIndex;
        $store->hydrateIndex($index, array_values($fileIds));
        foreach ($filesToResolve as $file) {
            foreach ($phpExtractor->classMetasFor($file->relativePath) as $classMeta) {
                $index->addClass($classMeta);
            }
            foreach ($phpExtractor->functionMetasFor($file->relativePath) as $name => $return) {
                $index->addFunction($name, $return);
            }
        }

        $totalNodes = 0;
        $totalEdges = 0;
        $totalUnresolved = 0;
        // Counting the reported node total needs to survive across every separate upsertNodes() call
        // in this one build, not just within a single call - two different files each referencing the
        // same external class (Illuminate\Support\Str, say) each produce their own extraNode with the
        // identical key, in two separate Resolver::resolveFile() calls (one per file). Each call's own
        // id-by-key result is correctly deduplicated on its own, but that alone still double-counts a
        // key seen in an earlier call - $seenNodeKeys is what makes a key count as a new node exactly
        // once for the whole build, matching the row it actually becomes (found 2026-09-08, the same
        // day as the per-call fix below: a real application still over-reported after that fix alone,
        // because it never covered a key repeating ACROSS files, only within one file's own nodes).
        $seenNodeKeys = [];
        $countNewNodes = function (array $nodeIds) use (&$seenNodeKeys): int {
            $new = 0;
            foreach (array_keys($nodeIds) as $key) {
                if (! isset($seenNodeKeys[$key])) {
                    $seenNodeKeys[$key] = true;
                    $new++;
                }
            }

            return $new;
        };

        // Pass 1: upsert every file's own nodes first, in its own transaction. Route/binding
        // extraction below and edge resolution in pass 2 both need these nodes to already exist:
        // an edge from one file in this batch can target a class declared by another file in the
        // same batch, and route/binding edges target project classes too. Markdown files' own doc
        // and section nodes are upserted here too, for the same reason: a same-page anchor link or
        // a link to another markdown file in this same batch needs that file's own doc node to
        // already exist by the time links are resolved in pass 2, not just be about to.
        $markdownExtractor = new MarkdownExtractor;
        $jsExtractor = new JsExtractor;
        /** @var array<string, array<int, array{level: int, title: string, anchor: string, line: int, text: string}>> $markdownSectionsByFile */
        $markdownSectionsByFile = [];
        /** @var array<string, array<int, array{verb: ?string, url: string, line: int}>> $jsCallsByFile */
        $jsCallsByFile = [];
        /** @var array<string, array<string,int>> $nodeIdsByFile */
        $nodeIdsByFile = [];
        $store->transaction(function () use ($store, $filesToResolve, $fragments, $fileIds, $markdownExtractor, $jsExtractor, $countNewNodes, &$markdownSectionsByFile, &$jsCallsByFile, &$nodeIdsByFile, &$totalNodes): void {
            foreach ($filesToResolve as $file) {
                if ($file->lang === 'md') {
                    $parsed = $markdownExtractor->nodes($file);
                    $markdownSectionsByFile[$file->relativePath] = $parsed['sections'];
                    $fileId = $fileIds[$file->relativePath];
                    $store->pruneStaleNodes($fileId, array_map(static fn ($n) => $n->key, $parsed['nodes']));
                    $store->clearFileOutput($fileId);
                    $nodeIdsByFile[$file->relativePath] = $store->upsertNodes($parsed['nodes'], $fileId);
                    $totalNodes += $countNewNodes($nodeIdsByFile[$file->relativePath]);

                    continue;
                }
                if ($file->lang === 'js') {
                    $parsed = $jsExtractor->nodes($file);
                    $jsCallsByFile[$file->relativePath] = $parsed['calls'];
                    $fileId = $fileIds[$file->relativePath];
                    $store->pruneStaleNodes($fileId, array_map(static fn ($n) => $n->key, $parsed['nodes']));
                    $store->clearFileOutput($fileId);
                    $nodeIdsByFile[$file->relativePath] = $store->upsertNodes($parsed['nodes'], $fileId);
                    $totalNodes += $countNewNodes($nodeIdsByFile[$file->relativePath]);

                    continue;
                }
                $fragment = $fragments[$file->relativePath] ?? null;
                if ($fragment === null) {
                    continue;
                }
                $fileId = $fileIds[$file->relativePath];
                $store->pruneStaleNodes($fileId, array_map(static fn ($n) => $n->key, $fragment->nodes));
                $store->clearFileOutput($fileId);
                $nodeIdsByFile[$file->relativePath] = $store->upsertNodes($fragment->nodes, $fileId);
                $totalNodes += $countNewNodes($nodeIdsByFile[$file->relativePath]);
            }
        });

        // Routes and container bindings are not tied to one file (SPEC.md section 4's own
        // extractor table: their input is "booted router"/"booted container", not a file set), so
        // they run once per build here - now that every project class/method node exists, their
        // own edges (routes_to, uses_middleware, binds) can resolve their target IDs too.
        [$routeNodeCount, $routeEdgeCount] = $this->extractRoutes($ctx, $index, $store, $countNewNodes);
        $totalNodes += $routeNodeCount;
        $totalEdges += $routeEdgeCount;
        $totalEdges += $this->extractBindings($ctx, $index, $store);
        $routeKeysByName = $store->routeKeysByName();

        $resolver = new Resolver($index, $routeKeysByName);

        $store->transaction(function () use ($store, $filesToResolve, $fragments, $fileIds, $phpExtractor, $resolver, $markdownExtractor, $markdownSectionsByFile, $jsExtractor, $jsCallsByFile, $nodeIdsByFile, $countNewNodes, &$totalNodes, &$totalEdges, &$totalUnresolved): void {
            // Pass 2: resolve each file's calls/instantiates/etc, but still don't look up target
            // node IDs yet - an extraNode (view, table, component) created while resolving one
            // file could be the target of an edge from a file resolved earlier in this same loop.
            // Markdown files resolve their links and mentions here too, now that every other
            // file's nodes - including every other markdown file's own doc/section nodes from pass
            // 1 above, and the routes/bindings extracted between pass 1 and here - exist to search.
            // JS files resolve their requests edges here for the identical reason: route nodes only
            // exist from that same routes/bindings step between pass 1 and here.
            /** @var array<string, array{0: int, 1: array<string,int>, 2: Edge[], 3: UnresolvedRow[]}> $perFile */
            $perFile = [];
            foreach ($filesToResolve as $file) {
                $fileId = $fileIds[$file->relativePath];
                $nodeIds = $nodeIdsByFile[$file->relativePath] ?? [];

                if ($file->lang === 'md') {
                    $sections = $markdownSectionsByFile[$file->relativePath] ?? [];
                    $resolved = $markdownExtractor->edges($file, $sections, $store);
                    $perFile[$file->relativePath] = [$fileId, $nodeIds, $resolved['edges'], $resolved['unresolved']];

                    continue;
                }
                if ($file->lang === 'js') {
                    $calls = $jsCallsByFile[$file->relativePath] ?? [];
                    $resolved = $jsExtractor->edges($file, $calls, $store);
                    $perFile[$file->relativePath] = [$fileId, $nodeIds, $resolved['edges'], $resolved['unresolved']];

                    continue;
                }

                $fragment = $fragments[$file->relativePath] ?? null;
                if ($fragment === null) {
                    continue;
                }

                $edges = $fragment->edges;
                $unresolved = [];
                $stmts = $phpExtractor->parsedStatementsFor($file->relativePath);
                if ($stmts !== null) {
                    $resolved = $resolver->resolveFile($file->relativePath, $stmts);
                    $edges = array_merge($edges, $resolved['edges']);
                    $unresolved = $resolved['unresolved'];
                    if ($resolved['extraNodes'] !== []) {
                        // extraNodes are only deduplicated within the one ReferenceVisitor instance
                        // that produced them (one per file) - two files referencing the same
                        // view/table/external class each contribute their own extraNode with the same
                        // key, in two separate upsertNodes() calls here. $countNewNodes (not a plain
                        // count()) is what makes that collapse in the total too, the same way it
                        // already collapses in the row this actually becomes.
                        $extraNodeIds = $store->upsertNodes($resolved['extraNodes'], null);
                        $totalNodes += $countNewNodes($extraNodeIds);
                        $nodeIds = array_merge($nodeIds, $extraNodeIds);
                    }
                }

                $perFile[$file->relativePath] = [$fileId, $nodeIds, $edges, $unresolved];
            }

            // Pass 3: every node any file's edges could target now exists in the store, so
            // resolving target keys to IDs no longer depends on file or extractor ordering.
            foreach ($perFile as [$fileId, $nodeIds, $edges, $unresolved]) {
                $targetKeys = array_merge(
                    array_map(static fn ($e) => $e->fromKey, $edges),
                    array_map(static fn ($e) => $e->toKey, $edges),
                );
                $nodeIds = array_merge($nodeIds, $store->nodeIds($targetKeys));

                $totalEdges += $store->insertEdges($edges, $nodeIds, $fileId);
                $totalUnresolved += $store->insertUnresolved($unresolved, $fileId);

                $deps = [];
                foreach ($edges as $edge) {
                    $deps = array_merge($deps, $edge->dependsOn);
                }
                foreach ($unresolved as $row) {
                    $deps = array_merge($deps, $row->dependsOn);
                }
                $store->replaceSymbolDeps($fileId, $deps);
            }
        });

        $duration = microtime(true) - $start;
        $report = new BuildReport(
            $full ? 'full' : 'incremental',
            count($allFiles),
            count($changedFiles),
            count($affectedFiles),
            count($deletedPaths),
            $totalNodes,
            $totalEdges,
            $totalUnresolved,
            $duration,
            $warnings,
        );
        $store->recordBuild(
            gmdate('c', (int) $start),
            $report->mode,
            $report->filesSeen,
            $report->filesChanged,
            $report->filesAffected,
            $report->nodes,
            $report->edges,
            $report->unresolved,
            $report->warnings,
            $this->currentCommit($root),
        );

        return $report;
    }

    /**
     * Every supporting extractor gets a turn, not just the first one to claim the file - a plugin
     * extractor (config('mapin.extractors'), docs/EXTENDING.md) that also supports `.php` files
     * needs to run alongside PhpExtractor, contributing its own nodes and edges on top, not replace
     * it. For the two built-in extractors this is unchanged behaviour: their own supports() checks
     * are mutually exclusive by lang, so at most one of them ever matches a given file anyway.
     *
     * @param  Extractor[]  $extractors
     */
    private function extractWith(array $extractors, SourceFile $file, ExtractionContext $ctx): ?Fragment
    {
        $nodes = [];
        $edges = [];
        $warnings = [];
        $matched = false;
        foreach ($extractors as $extractor) {
            if (! $extractor->supports($file)) {
                continue;
            }
            $matched = true;
            $fragment = $extractor->extract($file, $ctx);
            $nodes = [...$nodes, ...$fragment->nodes];
            $edges = [...$edges, ...$fragment->edges];
            $warnings = [...$warnings, ...$fragment->warnings];
        }

        return $matched ? new Fragment($nodes, $edges, $warnings) : null;
    }

    /**
     * @param  callable(array<string,int>):int  $countNewNodes
     * @return array{0: int, 1: int} [nodes persisted, edges persisted]
     */
    private function extractRoutes(ExtractionContext $ctx, SymbolIndex $index, SqliteStore $store, callable $countNewNodes): array
    {
        $extractor = new RouteExtractor;
        $fragment = $extractor->extract($ctx, $index);
        if ($fragment->nodes === [] && $fragment->edges === []) {
            return [0, 0];
        }

        $nodeCount = 0;
        $edgeCount = $store->transaction(function () use ($store, $fragment, $countNewNodes, &$nodeCount): int {
            $store->clearRouteData();
            $routeNodeIds = $store->upsertNodes($fragment->nodes, null);
            $nodeCount = $countNewNodes($routeNodeIds);
            $targetKeys = array_merge(
                array_map(static fn ($e) => $e->fromKey, $fragment->edges),
                array_map(static fn ($e) => $e->toKey, $fragment->edges),
            );
            $nodeIds = array_merge($routeNodeIds, $store->nodeIds($targetKeys));

            return $store->insertEdges($fragment->edges, $nodeIds, null);
        });

        return [$nodeCount, $edgeCount];
    }

    private function extractBindings(ExtractionContext $ctx, SymbolIndex $index, SqliteStore $store): int
    {
        $extractor = new BindingExtractor;
        $fragment = $extractor->extract($ctx, $index);
        if ($fragment->edges === []) {
            return 0;
        }

        return $store->transaction(function () use ($store, $fragment): int {
            $store->clearBindingData();
            $targetKeys = array_merge(
                array_map(static fn ($e) => $e->fromKey, $fragment->edges),
                array_map(static fn ($e) => $e->toKey, $fragment->edges),
            );
            $nodeIds = $store->nodeIds($targetKeys);

            return $store->insertEdges($fragment->edges, $nodeIds, null);
        });
    }

    /**
     * Files whose own content did not change but whose earlier resolution depended on a symbol
     * that one of the changed files added, removed or changed - see SPEC.md section 1.4 and
     * section 5, and SqliteStore's symbol_deps table.
     *
     * @param  SourceFile[]  $changedFiles
     * @param  array<string,int>  $changedFileIds  keyed by relative path
     * @return SourceFile[]
     */
    private function findAffectedFiles(string $root, SqliteStore $store, PhpExtractor $extractor, array $changedFiles, array $changedFileIds): array
    {
        $oldSymbols = $store->declaredSymbolsForFiles(array_values($changedFileIds));

        $newSymbols = [];
        foreach ($changedFiles as $file) {
            foreach ($extractor->classMetasFor($file->relativePath) as $classMeta) {
                $newSymbols[] = strtolower($classMeta->fqcn);
                foreach ($classMeta->interfaces as $interface) {
                    $newSymbols[] = strtolower($interface);
                }
            }
            foreach ($extractor->functionMetasFor($file->relativePath) as $name => $return) {
                $newSymbols[] = 'function:'.strtolower($name);
            }
        }

        $changedSymbols = array_values(array_unique(array_merge($oldSymbols, $newSymbols)));
        if ($changedSymbols === []) {
            return [];
        }

        $affectedFileIds = array_values(array_diff($store->filesDependingOn($changedSymbols), array_values($changedFileIds)));
        if ($affectedFileIds === []) {
            return [];
        }

        $files = [];
        foreach ($store->filesById($affectedFileIds) as $row) {
            $absolutePath = $root.'/'.$row['path'];
            if (! is_file($absolutePath)) {
                // Deleted between the last build and now, but not through this build's own
                // discovery pass (e.g. removed by something else mid-build) - nothing to re-resolve.
                continue;
            }
            $files[] = new SourceFile($row['path'], $absolutePath, $row['lang'], Hasher::hashFile($absolutePath), $row['is_project']);
        }

        return $files;
    }

    /** @return string[] */
    public function autoDetectVendorPaths(string $root): array
    {
        $candidates = [
            'vendor/laravel/framework/src/Illuminate',
            'vendor/illuminate',
            'vendor/nesbot/carbon/src',
        ];

        return array_values(array_filter($candidates, static fn ($path) => is_dir($root.'/'.$path)));
    }

    private function currentCommit(string $root): ?string
    {
        if (! is_dir($root.'/.git')) {
            return null;
        }
        $head = @file_get_contents($root.'/.git/HEAD');
        if ($head === false) {
            return null;
        }
        $head = trim($head);
        if (! str_starts_with($head, 'ref:')) {
            return substr($head, 0, 12);
        }
        $ref = trim(substr($head, 4));
        $refPath = $root.'/.git/'.$ref;

        return is_file($refPath) ? substr(trim((string) file_get_contents($refPath)), 0, 12) : null;
    }
}
