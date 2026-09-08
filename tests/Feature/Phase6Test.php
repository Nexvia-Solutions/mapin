<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mapin\Extract\Markdown\Semantic\ConceptExtractionResult;
use Mapin\Extract\Markdown\Semantic\LlmConceptExtractor;
use Mapin\Store\SqliteStore;
use Mapin\Tests\Support\FakeLlmClient;

/**
 * Phase 6: the semantic layer. Its own small fixture (tests/Fixtures/docs-semantic/notes.md), not
 * the shared library-domain one Phase4Test/GoldenTest use - concepts are LLM-invented labels, not
 * resolved code symbols, so nothing here needs a PHP fixture at all, and keeping it separate means
 * these tests never touch (or need to regenerate) the golden snapshot.
 *
 * Every test here drives LlmConceptExtractor directly against a FakeLlmClient, never a real
 * network call - the one exception is the safety test below, which deliberately points a
 * *configured* driver at an address nothing listens on and asserts mapin:docs still succeeds
 * without --with-llm, proving no request was ever attempted. OllamaClient's actual HTTP wiring
 * against a real Ollama server is verified separately by hand (SPEC.md's own established pattern
 * for anything that touches a real external service), not part of this automated suite - a CI run
 * or another machine has no reason to have Ollama installed.
 */
beforeEach(function () {
    File::ensureDirectoryExists($this->app->basePath('docs'));
    File::copyDirectory(__DIR__.'/../Fixtures/docs-semantic', $this->app->basePath('docs'));
    Artisan::call('mapin:build', ['--full' => true]);
});

afterEach(function () {
    File::deleteDirectory($this->app->basePath('docs'));
    @unlink(config('mapin.storage'));
});

it('never calls the configured LLM driver without --with-llm, even when one is configured and unreachable', function () {
    config(['mapin.llm.driver' => 'ollama', 'mapin.llm.ollama.base_url' => 'http://127.0.0.1:1']);

    $exitCode = Artisan::call('mapin:docs', ['--json' => true]);

    expect($exitCode)->toBe(0);
    $result = json_decode(Artisan::output(), true);
    expect($result)->not->toHaveKey('llm');
});

it('mapin:docs --with-llm defaults to NullClient: zero concepts, driver reported as null', function () {
    Artisan::call('mapin:docs', ['--with-llm' => true, '--json' => true]);
    $result = json_decode(Artisan::output(), true);

    expect($result['llm']['driver'])->toBe('null');
    expect($result['llm']['concepts_found'])->toBe(0);
    expect($result['llm']['relations_found'])->toBe(0);
    expect($result['llm']['sections_processed'])->toBe(2);
    expect($result['llm']['warnings'])->toBe([]);
});

it('creates concept nodes and mentions/relates_concept edges with source and model meta', function () {
    $store = new SqliteStore(config('mapin.storage'));
    $fake = new FakeLlmClient(['*' => new ConceptExtractionResult(['refund', 'billing service'], [['refund', 'billing service']])]);

    $report = (new LlmConceptExtractor($fake))->run(base_path(), $store);

    expect($report->sectionsProcessed)->toBe(2);
    expect($report->conceptsFound)->toBeGreaterThan(0);

    $refund = $store->findNodeByKey('concept:refund');
    $billing = $store->findNodeByKey('concept:billing-service');
    expect($refund)->not->toBeNull();
    expect($billing)->not->toBeNull();

    $stmt = $store->pdo()->prepare("SELECT meta FROM edges WHERE type = 'mentions' AND to_id = ? LIMIT 1");
    $stmt->execute([$refund['id']]);
    expect(json_decode((string) $stmt->fetchColumn(), true))->toBe(['source' => 'llm', 'model' => 'fake:test-model']);

    $relations = (int) $store->pdo()->query("SELECT COUNT(*) FROM edges WHERE type = 'relates_concept'")->fetchColumn();
    expect($relations)->toBeGreaterThan(0);
});

it('skips every section on a second run when nothing changed, without calling the client again', function () {
    $store = new SqliteStore(config('mapin.storage'));
    $fake = new FakeLlmClient(['*' => new ConceptExtractionResult(['refund'], [])]);

    $first = (new LlmConceptExtractor($fake))->run(base_path(), $store);
    $second = (new LlmConceptExtractor($fake))->run(base_path(), $store);

    expect($first->sectionsProcessed)->toBe(2);
    expect($first->sectionsSkipped)->toBe(0);
    expect($second->sectionsProcessed)->toBe(0);
    expect($second->sectionsSkipped)->toBe(2);
    expect($fake->callCount())->toBe(2);
});

it('regenerating with a different model invalidates the cache instead of reading it as already handled', function () {
    $store = new SqliteStore(config('mapin.storage'));
    $null = new FakeLlmClient(['*' => ConceptExtractionResult::empty()], model: 'null');
    (new LlmConceptExtractor($null))->run(base_path(), $store);

    $real = new FakeLlmClient(['*' => new ConceptExtractionResult(['refund'], [])], model: 'fake:real-model');
    $second = (new LlmConceptExtractor($real))->run(base_path(), $store);

    // Same section text as the first run, but a different model identifier - must not be treated
    // as a cache hit, or switching from a no-op default to a real driver would silently do nothing.
    expect($second->sectionsProcessed)->toBe(2);
    expect($second->sectionsSkipped)->toBe(0);
    expect($real->callCount())->toBe(2);
});

it('reprocesses only the section whose content changed, leaving the other cached', function () {
    $store = new SqliteStore(config('mapin.storage'));
    $fake = new FakeLlmClient(['*' => new ConceptExtractionResult(['refund'], [])]);
    (new LlmConceptExtractor($fake))->run(base_path(), $store);
    expect($fake->callCount())->toBe(2);

    // Matches tests/Fixtures/docs-semantic/notes.md byte for byte except the Refunds section's
    // body text - including the trailing newline the original file (written by the Write tool)
    // ends with, since a heredoc does not add one on its own and parseSections() folds a trailing
    // blank line into the last section's own text: without this, Shipping's hash would also
    // change despite looking identical, and the whole point of this test is that it does not.
    File::put($this->app->basePath('docs/notes.md'), <<<'MD'
        # Refunds

        Refunds go through the billing service before the customer sees a credit, and now also
        notify the ledger.

        # Shipping

        Shipping estimates come from a separate calculator, unrelated to billing.

        MD);

    $second = (new LlmConceptExtractor($fake))->run(base_path(), $store);

    expect($second->sectionsProcessed)->toBe(1);
    expect($second->sectionsSkipped)->toBe(1);
    expect($fake->callCount())->toBe(3);
});

it('records a warning and keeps processing other sections when the client fails for one', function () {
    $store = new SqliteStore(config('mapin.storage'));
    $fake = new FakeLlmClient(onCall: function (string $text): void {
        if (str_contains($text, 'Refunds go through')) {
            throw new RuntimeException('connection refused');
        }
    });

    $report = (new LlmConceptExtractor($fake))->run(base_path(), $store);

    expect($report->sectionsProcessed)->toBe(1);
    expect($report->warnings)->toHaveCount(1);
    expect($report->warnings[0])->toContain('connection refused');
});
