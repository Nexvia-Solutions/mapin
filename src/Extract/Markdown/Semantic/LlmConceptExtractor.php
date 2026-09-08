<?php

declare(strict_types=1);

namespace Mapin\Extract\Markdown\Semantic;

use Mapin\Extract\Contracts\SourceFile;
use Mapin\Extract\Discovery\Hasher;
use Mapin\Extract\Markdown\MarkdownExtractor;
use Mapin\Graph\Edge;
use Mapin\Graph\EdgeType;
use Mapin\Graph\Key;
use Mapin\Graph\Node;
use Mapin\Graph\NodeType;
use Mapin\Store\SqliteStore;

/**
 * SPEC.md section 4's `LlmConceptExtractor`: input is "sections", not files - re-reads every `.md`
 * file already known to the graph (via SqliteStore::docNodes(), itself only ever populated by
 * MarkdownExtractor) rather than taking a discovered file list of its own, since this never runs as
 * part of mapin:build's own discover step (SPEC.md section 13: a plain build must stay
 * network-free). Only ever reads `.md` files - structurally incapable of sending PHP source to an
 * LLM, not just disciplined about it.
 *
 * Per-section caching by content hash (SPEC.md section 7/phase 6 acceptance): every section in the
 * graph is hash-compared on every run, cheaply, and only a changed or never-processed section
 * actually calls the client. This is the same shape of cache the deterministic build already uses
 * at the file level (SPEC.md section 2 step 1), applied one level down.
 */
final class LlmConceptExtractor
{
    public function __construct(
        private readonly LlmClient $client,
        private readonly MarkdownExtractor $markdownExtractor = new MarkdownExtractor,
    ) {}

    public function run(string $projectRoot, SqliteStore $store): ConceptExtractionReport
    {
        $processed = 0;
        $skipped = 0;
        $conceptsFound = 0;
        $relationsFound = 0;
        $warnings = [];

        foreach ($store->docNodes() as $docRow) {
            $relativePath = $docRow['file_path'];
            $absolutePath = $projectRoot.'/'.$relativePath;
            if (! is_file($absolutePath)) {
                // Deleted since the build that created this doc node - nothing left to re-read;
                // the next mapin:build will prune the node itself, not this command's job.
                continue;
            }

            $file = new SourceFile($relativePath, $absolutePath, 'md', Hasher::hashFile($absolutePath));
            $sections = $this->markdownExtractor->nodes($file)['sections'];

            foreach ($sections as $section) {
                $sectionKey = Key::section($relativePath, $section['anchor']);
                $sectionNode = $store->findNodeByKey($sectionKey);
                if ($sectionNode === null) {
                    // A heading changed since the deterministic build ran, so its anchor no longer
                    // matches any stored section - a fresh mapin:build resolves this, not this pass.
                    continue;
                }

                // The model identifier is part of the cache key, not just the section text - a
                // section already processed by NullClient (config('mapin.llm.driver') left at its
                // default, or a build run without a real provider configured yet) must not read as
                // "already handled" the moment a real driver is configured; changing model or
                // provider is exactly the kind of change that should regenerate, same as the text
                // itself changing.
                $hash = hash('xxh128', $this->client->modelIdentifier().'|'.$section['text']);
                $meta = json_decode((string) ($sectionNode['meta'] ?? '{}'), true) ?? [];
                if (($meta['llm_hash'] ?? null) === $hash) {
                    $skipped++;

                    continue;
                }

                try {
                    $result = $this->client->extractConcepts($section['text']);
                } catch (\Throwable $e) {
                    $warnings[] = "Mapin: LLM extraction failed for {$sectionKey}: {$e->getMessage()}";

                    continue;
                }

                [$conceptsCreated, $relationsCreated] = $this->store($store, $relativePath, (int) $sectionNode['id'], (int) $sectionNode['file_id'], (int) $section['line'], $sectionKey, $result, $hash);
                $conceptsFound += $conceptsCreated;
                $relationsFound += $relationsCreated;
                $processed++;
            }
        }

        return new ConceptExtractionReport($processed, $skipped, $conceptsFound, $relationsFound, $warnings);
    }

    /** @return array{0: int, 1: int} [concepts created, relations created] */
    private function store(SqliteStore $store, string $relativePath, int $sectionId, int $fileId, int $line, string $sectionKey, ConceptExtractionResult $result, string $hash): array
    {
        return $store->transaction(function () use ($store, $relativePath, $sectionId, $fileId, $line, $sectionKey, $result, $hash): array {
            $store->clearSectionConceptEdges($fileId, $line);

            // Every concept name touched by either list gets a node - a relation naming a concept
            // the model left out of its own `concepts` list is still a real concept to record, not
            // a reason to drop the relation.
            $names = $result->concepts;
            foreach ($result->relations as [$a, $b]) {
                $names[] = $a;
                $names[] = $b;
            }
            $slugsByName = [];
            foreach ($names as $name) {
                if ($name === '' || isset($slugsByName[$name])) {
                    continue;
                }
                $slug = MarkdownExtractor::slugify($name);
                if ($slug !== '') {
                    $slugsByName[$name] = $slug;
                }
            }

            $conceptNodes = [];
            foreach ($slugsByName as $name => $slug) {
                $key = Key::concept($slug);
                $conceptNodes[$key] = new Node(NodeType::Concept, $name, $key);
            }
            $nodeIds = $conceptNodes !== [] ? $store->upsertNodes(array_values($conceptNodes), null) : [];
            $nodeIds[$sectionKey] = $sectionId;

            $meta = ['source' => 'llm', 'model' => $this->client->modelIdentifier()];
            $edges = [];
            foreach ($result->concepts as $name) {
                if (! isset($slugsByName[$name])) {
                    continue;
                }
                $edges[] = new Edge(EdgeType::Mentions, $sectionKey, Key::concept($slugsByName[$name]), $relativePath, $line, meta: $meta);
            }
            $relationsCreated = 0;
            foreach ($result->relations as [$a, $b]) {
                if (! isset($slugsByName[$a], $slugsByName[$b]) || $slugsByName[$a] === $slugsByName[$b]) {
                    continue;
                }
                $edges[] = new Edge(EdgeType::RelatesConcept, Key::concept($slugsByName[$a]), Key::concept($slugsByName[$b]), $relativePath, $line, meta: $meta);
                $relationsCreated++;
            }

            $store->insertEdges($edges, $nodeIds, $fileId);
            $store->mergeNodeMeta($sectionId, ['llm_hash' => $hash]);

            return [count($conceptNodes), $relationsCreated];
        });
    }
}
