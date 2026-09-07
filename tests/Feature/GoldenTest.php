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
use Mapin\Store\SqliteStore;

/**
 * SPEC.md section 12: every node key and every edge (type, from, to, resolution) the fixture app
 * must produce, diffed against a committed snapshot. Anything not covered by a more specific
 * assertion elsewhere is still covered here - a change to any extractor or resolver rule that
 * alters what the fixture produces shows up as a diff on this one file, reviewed like any other
 * change to tests/Golden/expected.json.
 *
 * Regenerate deliberately (never to make a red test green without understanding why it changed):
 *   MAPIN_UPDATE_GOLDEN=1 vendor/bin/pest tests/Feature/GoldenTest.php
 */
beforeEach(function () {
    mapinCopyPhase2Fixtures($this->app);

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
    Route::post('/books', [BookController::class, 'store'])->name('books.store');
    Route::get('/reports', ReportController::class)->name('reports.show')->middleware('ensure-tenant');
    $this->app->make('router')->aliasMiddleware('ensure-tenant', EnsureTenant::class);

    $this->app->bind(PricingEngine::class, StandardPricingEngine::class);
});

afterEach(function () {
    mapinCleanupPhase2Fixtures($this->app);
});

it('matches the committed snapshot of every node and edge the fixture app produces', function () {
    Artisan::call('mapin:build', ['--full' => true]);

    $store = new SqliteStore(config('mapin.storage'));
    $snapshot = mapinGoldenSnapshot($store);

    $goldenPath = __DIR__.'/../Golden/expected.json';

    if (getenv('MAPIN_UPDATE_GOLDEN') === '1') {
        file_put_contents($goldenPath, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    expect(file_exists($goldenPath))->toBeTrue('tests/Golden/expected.json does not exist - run with MAPIN_UPDATE_GOLDEN=1 once to create it, then review the diff before committing.');

    $expected = json_decode((string) file_get_contents($goldenPath), true);

    expect($snapshot['nodes'])->toBe($expected['nodes']);
    expect($snapshot['edges'])->toBe($expected['edges']);
});

/**
 * @return array{nodes: list<string>, edges: list<array{type: string, from: string, to: string, resolution: ?string}>}
 */
function mapinGoldenSnapshot(SqliteStore $store): array
{
    $pdo = $store->pdo();

    $nodes = $pdo->query('SELECT key FROM nodes ORDER BY key')->fetchAll(PDO::FETCH_COLUMN);

    $rows = $pdo->query(
        'SELECT edges.type, s.key AS from_key, t.key AS to_key, edges.resolution
         FROM edges JOIN nodes s ON s.id = edges.from_id JOIN nodes t ON t.id = edges.to_id
         ORDER BY edges.type, s.key, t.key, edges.resolution',
    )->fetchAll(PDO::FETCH_ASSOC);

    $edges = array_map(static fn (array $r): array => [
        'type' => $r['type'],
        'from' => $r['from_key'],
        'to' => $r['to_key'],
        'resolution' => $r['resolution'],
    ], $rows);

    return ['nodes' => $nodes, 'edges' => $edges];
}
