<?php

declare(strict_types=1);

use Fixture\Contracts\PricingEngine;
use Fixture\Http\Controllers\BookController;
use Fixture\Http\Controllers\DashboardController;
use Fixture\Services\StandardPricingEngine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Mapin\Store\SqliteStore;

// mapinCopyPhase2Fixtures()/mapinCleanupPhase2Fixtures() are declared in tests/Pest.php, shared
// with Phase3Test.php - see the comment there for why they live in the always-loaded file.

beforeEach(function () {
    mapinCopyPhase2Fixtures($this->app);
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
    Route::post('/books', [BookController::class, 'store'])->name('books.store');
    $this->app->bind(PricingEngine::class, StandardPricingEngine::class);
});

afterEach(function () {
    mapinCleanupPhase2Fixtures($this->app);
});

it('extracts every registered route', function () {
    Artisan::call('mapin:build', ['--full' => true]);

    $store = new SqliteStore(config('mapin.storage'));
    $registered = count($this->app->make('router')->getRoutes());
    $stored = (int) $store->pdo()->query("SELECT COUNT(*) FROM nodes WHERE type = 'route'")->fetchColumn();

    expect($stored)->toBe($registered);
});

it('links a route to its controller method', function () {
    Artisan::call('mapin:build', ['--full' => true]);

    Artisan::call('mapin:route', ['route' => 'dashboard.index', '--json' => true]);
    $result = json_decode(Artisan::output(), true);

    expect($result['found'])->toBeTrue();
    expect($result['result']['action'])->toBe(DashboardController::class.'@index');
});

it('resolves route() to the named route via the route name map', function () {
    Artisan::call('mapin:build', ['--full' => true]);

    $store = new SqliteStore(config('mapin.storage'));
    $stmt = $store->pdo()->prepare(
        "SELECT edges.* FROM edges JOIN nodes ON nodes.id = edges.from_id
         WHERE nodes.key = ? AND edges.type = 'links_route'",
    );
    $stmt->execute(['method:Fixture\\Http\\Controllers\\DashboardController::bookIndexLink']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    expect($rows)->not->toBeEmpty();
});

it('extracts a container binding and uses it to resolve an interface call', function () {
    Artisan::call('mapin:build', ['--full' => true]);

    $store = new SqliteStore(config('mapin.storage'));
    $binds = (int) $store->pdo()->query("SELECT COUNT(*) FROM edges WHERE type = 'binds'")->fetchColumn();
    expect($binds)->toBeGreaterThan(0);

    $stmt = $store->pdo()->prepare("SELECT resolution FROM edges JOIN nodes t ON t.id = edges.to_id WHERE t.key LIKE '%StandardPricingEngine%' AND edges.resolution = 'container'");
    $stmt->execute();
    expect($stmt->fetchAll())->not->toBeEmpty();
});

it('extracts view nodes and includes/component edges from Blade files', function () {
    Artisan::call('mapin:build', ['--full' => true]);

    Artisan::call('mapin:view', ['name' => 'dashboard.index', '--json' => true]);
    $result = json_decode(Artisan::output(), true);

    expect($result['found'])->toBeTrue();
    expect($result['result']['includes'])->toContain('view:layouts.app');
    expect($result['result']['includes'])->toContain('view:dashboard.partials.stats');
    expect($result['result']['components'])->toContain('component:alert');
    expect($result['result']['components'])->toContain('component:components.card');
});

it('shows a model summary with table and relations', function () {
    Artisan::call('mapin:build', ['--full' => true]);

    Artisan::call('mapin:model', ['name' => 'Author', '--json' => true]);
    $result = json_decode(Artisan::output(), true);

    expect($result['found'])->toBeTrue();
    expect($result['result']['table'])->toBe('table:authors');
    expect($result['result']['relations'])->not->toBeEmpty();
});

it('impact lists the route reaching a deeply-called service method', function () {
    Artisan::call('mapin:build', ['--full' => true]);

    Artisan::call('mapin:impact', [
        'key' => 'method:Fixture\\Services\\StandardPricingEngine::quote',
        '--depth' => 3,
        '--json' => true,
    ]);
    $result = json_decode(Artisan::output(), true);

    expect($result['found'])->toBeTrue();
    $routeKeys = array_column($result['result']['routes'], 'key');
    expect($routeKeys)->not->toBeEmpty();
});
