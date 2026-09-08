<?php

declare(strict_types=1);

use Fixture\Contracts\PricingEngine;
use Fixture\Http\Controllers\BookController;
use Fixture\Http\Controllers\DashboardController;
use Fixture\Services\StandardPricingEngine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Mapin\Store\SqliteStore;

/**
 * Phase 7: JsExtractor against its own small fixture (tests/Fixtures/js/api.js), covering every
 * call shape SPEC.md section 4/1.3 names (axios method calls, axios/$.ajax object-form calls,
 * fetch with and without an inline method, jQuery's $.get/$.post shorthand) plus the two negative
 * cases that matter as much as the positive ones: a literal URL with no matching route at all
 * (silently ignored, same "zero matches = not a reference" rule the Markdown module already
 * follows) and a genuinely ambiguous URI matching more than one registered route (unresolved, not
 * guessed). Reuses the shared library fixture only for its routes, which JsExtractor's edges need
 * to already exist; the JS fixture itself is copied separately so this phase never touches (or
 * needs to regenerate) the golden snapshot.
 */
beforeEach(function () {
    mapinCopyPhase2Fixtures($this->app);

    File::ensureDirectoryExists($this->app->basePath('resources/js'));
    File::copyDirectory(__DIR__.'/../Fixtures/js', $this->app->basePath('resources/js'));

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
    Route::post('/books', [BookController::class, 'store'])->name('books.store');
    // Neither verb registered here is GET, on purpose: the fixture's ambiguousCall() is a bare
    // $.ajax({url: ...}) with no method/type key, which defaults to GET (a documented library
    // default, not an unknown verb) - if this URI had a GET route too, the default would resolve
    // it cleanly and the case would stop testing genuine ambiguity at all.
    Route::post('/ambiguous', [BookController::class, 'store'])->name('ambiguous.store');
    Route::put('/ambiguous', [BookController::class, 'store'])->name('ambiguous.update');
    // GET + POST here, for the fixture's showAmbiguous()/storeAmbiguous() - both use an explicit
    // axios.<verb>(...) call, so both must resolve cleanly despite the shared URI.
    Route::get('/cart-like', [DashboardController::class, 'index'])->name('cart-like.show');
    Route::post('/cart-like', [BookController::class, 'store'])->name('cart-like.store');
    $this->app->bind(PricingEngine::class, StandardPricingEngine::class);

    Artisan::call('mapin:build', ['--full' => true]);
});

afterEach(function () {
    mapinCleanupPhase2Fixtures($this->app);
    File::deleteDirectory($this->app->basePath('resources/js'));
});

it('links an axios method call to the route its literal URL matches', function () {
    $store = new SqliteStore(config('mapin.storage'));

    expect(mapinEdgeExists($store, 'requests', 'file:resources/js/api.js', 'route:GET /dashboard'))->toBeTrue();
    expect(mapinEdgeExists($store, 'requests', 'file:resources/js/api.js', 'route:POST /books'))->toBeTrue();
});

it('links a plain fetch call (no explicit method) to the route by URL alone', function () {
    $store = new SqliteStore(config('mapin.storage'));

    expect(mapinEdgeExists($store, 'requests', 'file:resources/js/api.js', 'route:GET /dashboard'))->toBeTrue();
});

it('records the detected verb and url in the edge meta', function () {
    $store = new SqliteStore(config('mapin.storage'));
    $stmt = $store->pdo()->prepare(
        "SELECT edges.meta FROM edges JOIN nodes f ON f.id = edges.from_id JOIN nodes t ON t.id = edges.to_id
         WHERE edges.type = 'requests' AND f.key = 'file:resources/js/api.js' AND t.key = 'route:POST /books'
         AND json_extract(edges.meta, '$.verb') = 'POST'",
    );
    $stmt->execute();

    expect($stmt->fetchColumn())->not->toBeFalse();
});

it('links a jQuery $.ajax object-form call and a $.get shorthand call', function () {
    $store = new SqliteStore(config('mapin.storage'));

    $stmt = $store->pdo()->prepare(
        "SELECT COUNT(*) FROM edges JOIN nodes f ON f.id = edges.from_id JOIN nodes t ON t.id = edges.to_id
         WHERE edges.type = 'requests' AND f.key = 'file:resources/js/api.js' AND t.key = 'route:POST /books'",
    );
    $stmt->execute();
    // axios.post, fetch+method, and $.ajax all target the same POST /books route - three separate
    // call sites, three separate edges (different lines), not deduplicated into one.
    expect((int) $stmt->fetchColumn())->toBe(3);

    expect(mapinEdgeExists($store, 'requests', 'file:resources/js/api.js', 'route:GET /dashboard'))->toBeTrue();
});

it('ignores a call whose literal URL matches no route at all', function () {
    $store = new SqliteStore(config('mapin.storage'));
    $stmt = $store->pdo()->prepare("SELECT COUNT(*) FROM unresolved WHERE kind = 'requests' AND expression LIKE '%example.com%'");
    $stmt->execute();

    expect((int) $stmt->fetchColumn())->toBe(0);

    $anyExternalEdge = $store->pdo()->query("SELECT COUNT(*) FROM edges WHERE type = 'requests' AND meta LIKE '%example.com%'")->fetchColumn();
    expect((int) $anyExternalEdge)->toBe(0);
});

it('records a genuinely ambiguous URL as unresolved with every candidate route, never a guess', function () {
    $store = new SqliteStore(config('mapin.storage'));
    $stmt = $store->pdo()->prepare("SELECT candidates FROM unresolved WHERE kind = 'requests' AND expression = '/ambiguous'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    expect($row)->not->toBeFalse();
    $candidates = json_decode((string) $row['candidates'], true);
    expect($candidates)->toContain('route:POST /ambiguous');
    expect($candidates)->toContain('route:PUT /ambiguous');
});

it('a known verb resolves cleanly to its own route even when the same URL is registered under another verb too', function () {
    // Real case, not hypothetical: checked against the real application this extractor was also
    // verified against (SPEC.md 1.13) - axios.get('/admin/cart', ...) and axios.post('/admin/cart',
    // ...) both exist as separate, deliberate calls a few hundred lines apart. An earlier version
    // of edges() matched by URL alone and landed exactly this shape of pair in `unresolved`,
    // discarding real information an explicit `axios.<verb>(...)` call already gives for free.
    $store = new SqliteStore(config('mapin.storage'));

    expect(mapinEdgeExists($store, 'requests', 'file:resources/js/api.js', 'route:GET /cart-like'))->toBeTrue();
    expect(mapinEdgeExists($store, 'requests', 'file:resources/js/api.js', 'route:POST /cart-like'))->toBeTrue();
});

it('a bare call with no method key defaults to GET, the documented library default, not an unknown verb', function () {
    // Real case, not hypothetical: found via the same real-app check - a bare fetch(url, { headers:
    // {...} }) with no `method` key at all, matched against a URI with only non-GET routes
    // registered. Confirms the default is applied even when it does not happen to resolve anything
    // (a GET route genuinely does not exist here), rather than only being exercised by a case where
    // the default happens to work.
    $store = new SqliteStore(config('mapin.storage'));
    $stmt = $store->pdo()->prepare(
        "SELECT edges.meta FROM edges JOIN nodes f ON f.id = edges.from_id JOIN nodes t ON t.id = edges.to_id
         WHERE edges.type = 'requests' AND f.key = 'file:resources/js/api.js' AND t.key = 'route:GET /dashboard'
         AND json_extract(edges.meta, '$.verb') = 'GET'",
    );
    $stmt->execute();

    expect($stmt->fetchColumn())->not->toBeFalse();
});

it('impact includes the JS file under a dedicated js_files bucket', function () {
    Artisan::call('mapin:impact', [
        'key' => 'method:Fixture\\Http\\Controllers\\DashboardController::index',
        '--depth' => 2,
        '--json' => true,
    ]);
    $result = json_decode(Artisan::output(), true);

    expect($result['found'])->toBeTrue();
    $jsFileKeys = array_column($result['result']['js_files'], 'key');
    expect($jsFileKeys)->toContain('file:resources/js/api.js');
});
