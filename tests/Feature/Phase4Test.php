<?php

declare(strict_types=1);

use Fixture\Contracts\PricingEngine;
use Fixture\Http\Controllers\BookController;
use Fixture\Http\Controllers\DashboardController;
use Fixture\Http\Controllers\ReportController;
use Fixture\Http\Middleware\EnsureTenant;
use Fixture\Services\StandardPricingEngine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Mapin\Query\Query;
use Mapin\Store\SqliteStore;

/**
 * Phase 4: the Markdown module against tests/Fixtures/docs/architecture.md and models.md, added to
 * the same shared fixture app GoldenTest.php snapshots (SPEC.md section 12) rather than a separate
 * one, exercising every pattern section 7 and section 531's testing strategy call for: backticked
 * and plain mentions of a class, a route name, a route URI, a table and a file path; a same-file
 * anchor link; a cross-file link in both directions; and a short name that resolves to more than
 * one node (a route and a view both named "dashboard.index"), which must land in `unresolved`
 * rather than picking either one.
 */
beforeEach(function () {
    mapinCopyPhase2Fixtures($this->app);

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
    Route::post('/books', [BookController::class, 'store'])->name('books.store');
    Route::get('/reports', ReportController::class)->name('reports.show')->middleware('ensure-tenant');
    $this->app->make('router')->aliasMiddleware('ensure-tenant', EnsureTenant::class);
    $this->app->bind(PricingEngine::class, StandardPricingEngine::class);

    Artisan::call('mapin:build', ['--full' => true]);
});

afterEach(function () {
    mapinCleanupPhase2Fixtures($this->app);
});

function mapinEdgeExists(SqliteStore $store, string $type, string $fromKey, string $toKey): bool
{
    $stmt = $store->pdo()->prepare(
        'SELECT COUNT(*) FROM edges JOIN nodes f ON f.id = edges.from_id JOIN nodes t ON t.id = edges.to_id
         WHERE edges.type = ? AND f.key = ? AND t.key = ?',
    );
    $stmt->execute([$type, $fromKey, $toKey]);

    return ((int) $stmt->fetchColumn()) > 0;
}

it('creates a doc node per Markdown file and a section node per heading, with GitHub-style anchors', function () {
    $store = new SqliteStore(config('mapin.storage'));
    $keys = $store->pdo()->query('SELECT key FROM nodes WHERE type IN (\'doc\', \'section\') ORDER BY key')->fetchAll(PDO::FETCH_COLUMN);

    expect($keys)->toBe([
        'doc:docs/architecture.md',
        'doc:docs/models.md',
        'section:docs/architecture.md#architecture',
        'section:docs/architecture.md#conventions',
        'section:docs/architecture.md#routing',
        'section:docs/architecture.md#source-layout',
        'section:docs/models.md#book',
        'section:docs/models.md#models',
    ]);
});

it('links a backticked FQCN mention to the class it names', function () {
    $store = new SqliteStore(config('mapin.storage'));

    expect(mapinEdgeExists(
        $store,
        'documents',
        'section:docs/architecture.md#architecture',
        'class:Fixture\\Services\\Catalog',
    ))->toBeTrue();
});

it('links a plain (non-backticked) class mention by its short name', function () {
    $store = new SqliteStore(config('mapin.storage'));

    expect(mapinEdgeExists(
        $store,
        'documents',
        'section:docs/architecture.md#architecture',
        'class:Fixture\\Http\\Controllers\\CatalogController',
    ))->toBeTrue();
});

it('links a backticked route name mention to the route', function () {
    $store = new SqliteStore(config('mapin.storage'));

    expect(mapinEdgeExists(
        $store,
        'documents',
        'section:docs/architecture.md#routing',
        'route:POST /books',
    ))->toBeTrue();
});

it('links a plain route URI mention to the route, found by the URI itself rather than backticks', function () {
    $store = new SqliteStore(config('mapin.storage'));

    expect(mapinEdgeExists(
        $store,
        'documents',
        'section:docs/architecture.md#routing',
        'route:GET /reports',
    ))->toBeTrue();
});

it('links a backticked file path mention to the file it names', function () {
    $store = new SqliteStore(config('mapin.storage'));

    expect(mapinEdgeExists(
        $store,
        'documents',
        'section:docs/architecture.md#source-layout',
        'file:app/MapinFixture/Models/Book.php',
    ))->toBeTrue();
});

it('links backticked table mentions to both an overridden and a conventional table name', function () {
    $store = new SqliteStore(config('mapin.storage'));

    expect(mapinEdgeExists($store, 'documents', 'section:docs/architecture.md#conventions', 'table:authors'))->toBeTrue();
    expect(mapinEdgeExists($store, 'documents', 'section:docs/architecture.md#conventions', 'table:library_books'))->toBeTrue();
});

it('resolves a class mention in a second Markdown file against the same graph', function () {
    $store = new SqliteStore(config('mapin.storage'));

    expect(mapinEdgeExists($store, 'documents', 'section:docs/models.md#book', 'class:Fixture\\Models\\Book'))->toBeTrue();
    expect(mapinEdgeExists($store, 'documents', 'section:docs/models.md#book', 'table:library_books'))->toBeTrue();
});

it('records a same-file anchor link as a links_doc edge back to its own doc node', function () {
    $store = new SqliteStore(config('mapin.storage'));

    expect(mapinEdgeExists($store, 'links_doc', 'doc:docs/architecture.md', 'doc:docs/architecture.md'))->toBeTrue();
});

it('resolves a relative cross-file link in both directions', function () {
    $store = new SqliteStore(config('mapin.storage'));

    expect(mapinEdgeExists($store, 'links_doc', 'doc:docs/architecture.md', 'doc:docs/models.md'))->toBeTrue();
    expect(mapinEdgeExists($store, 'links_doc', 'doc:docs/models.md', 'doc:docs/architecture.md'))->toBeTrue();
});

it('records an ambiguous short name mention as unresolved instead of guessing between a route and a view', function () {
    $store = new SqliteStore(config('mapin.storage'));
    $stmt = $store->pdo()->prepare("SELECT candidates FROM unresolved WHERE kind = 'documents' AND expression = 'dashboard.index'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    expect($row)->not->toBeFalse();
    $candidates = json_decode((string) $row['candidates'], true);
    expect($candidates)->toContain('route:GET /dashboard');
    expect($candidates)->toContain('view:dashboard.index');
});

it('docs tool reports the sections that document a class, and the nodes a section documents', function () {
    $query = new Query(new SqliteStore(config('mapin.storage')), base_path());

    $forClass = $query->docs('class:Fixture\\Services\\Catalog');
    expect($forClass->found)->toBeTrue();
    expect($forClass->result['documented_by'])->toContain('section:docs/architecture.md#architecture');
    expect($forClass->result['documents'])->toBe([]);

    $forSection = $query->docs('section:docs/architecture.md#architecture');
    expect($forSection->found)->toBeTrue();
    expect($forSection->result['documents'])->toContain('class:Fixture\\Services\\Catalog');
    expect($forSection->result['documented_by'])->toBe([]);
});

it('impact groups a documenting section under docs', function () {
    Artisan::call('mapin:impact', [
        'key' => 'class:Fixture\\Services\\Catalog',
        '--depth' => 1,
        '--json' => true,
    ]);
    $result = json_decode(Artisan::output(), true);

    expect($result['found'])->toBeTrue();
    $docKeys = array_column($result['result']['docs'], 'key');
    expect($docKeys)->toContain('section:docs/architecture.md#architecture');
});

it('mapin:docs builds the graph and reports doc/section/edge counts', function () {
    Artisan::call('mapin:docs', ['--json' => true]);
    $result = json_decode(Artisan::output(), true);

    expect($result['docs'])->toBe(2);
    expect($result['sections'])->toBe(6);
    expect($result['documents_edges'])->toBeGreaterThan(0);
    expect($result['links_doc_edges'])->toBeGreaterThan(0);
    expect($result['unresolved'])->toBeGreaterThan(0);
});
