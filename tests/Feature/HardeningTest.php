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
 * Named, independent regression tests for patterns the golden snapshot (GoldenTest.php) also
 * covers, but only implicitly - each of these was a real gap found auditing the fixture against
 * SPEC.md section 12's list, not a hypothetical: routes_to previously went unresolved for every
 * invokable controller (a real, existing Laravel pattern - fixed alongside adding this test, see
 * SPEC.md 1.6/1.7), and $table override, Schedule detection and middleware alias resolution had
 * code behind them but zero test coverage before now.
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

it('resolves routes_to for an invokable controller registered with no @method', function () {
    $store = new SqliteStore(config('mapin.storage'));
    $stmt = $store->pdo()->prepare(
        "SELECT t.key FROM edges JOIN nodes s ON s.id = edges.from_id JOIN nodes t ON t.id = edges.to_id
         WHERE edges.type = 'routes_to' AND s.key = 'route:GET /reports'",
    );
    $stmt->execute();

    expect($stmt->fetchColumn())->toBe('method:Fixture\\Http\\Controllers\\ReportController::__invoke');
});

it('prefers a model\'s declared $table over the naming convention', function () {
    $store = new SqliteStore(config('mapin.storage'));
    $stmt = $store->pdo()->prepare(
        'SELECT t.key FROM edges JOIN nodes s ON s.id = edges.from_id JOIN nodes t ON t.id = edges.to_id
         WHERE edges.type = ? AND s.key = ?',
    );
    $stmt->execute(['maps_table', 'class:Fixture\\Models\\Book']);

    expect($stmt->fetchColumn())->toBe('table:library_books');
});

it('records a schedules edge from Schedule->job()', function () {
    $store = new SqliteStore(config('mapin.storage'));
    $stmt = $store->pdo()->prepare(
        'SELECT t.key FROM edges JOIN nodes s ON s.id = edges.from_id JOIN nodes t ON t.id = edges.to_id
         WHERE edges.type = ? AND s.key = ?',
    );
    $stmt->execute(['schedules', 'method:Fixture\\Providers\\BookScheduleProvider::schedule']);

    expect($stmt->fetchColumn())->toBe('class:Fixture\\Jobs\\NotifyNewBook');
});

it('resolves a middleware alias to its registered class', function () {
    $store = new SqliteStore(config('mapin.storage'));
    $row = $store->pdo()->query("SELECT meta FROM nodes WHERE key = 'middleware:ensure-tenant'")->fetch(PDO::FETCH_ASSOC);

    expect($row)->not->toBeFalse();
    $meta = json_decode((string) $row['meta'], true);
    expect($meta['class'])->toBe(EnsureTenant::class);
});

it('model() reports not found rather than guessing when a search term matches several classes, none exactly', function () {
    // Found against real, large-scale data (not a fixture-only hypothetical): searching for a real
    // Eloquent model by its short name returned an unrelated console command whose class name
    // happened to contain the same substring instead, because model() picked findNodes()'s first
    // LIKE-ranked row with no exact-match check at all - see Query::exactMatches()'s docblock.
    // "Controller" here is a
    // substring of BookController/CatalogController/DashboardController/ReportController, none of
    // which has "Controller" as its own short name, so it reproduces the same ambiguity in the
    // fixture: several plausible matches, none of them exact.
    $query = new Query(new SqliteStore(config('mapin.storage')), base_path());
    $result = $query->model('Controller');

    expect($result->found)->toBeFalse();
    expect($result->suggestions)->not->toBeEmpty();
});
