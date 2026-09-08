<?php

declare(strict_types=1);

use Fixture\Contracts\PricingEngine;
use Fixture\Http\Controllers\BookController;
use Fixture\Http\Controllers\DashboardController;
use Fixture\Services\StandardPricingEngine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Mapin\Query\Query;
use Mapin\Store\SqliteStore;

/**
 * Phase 5: community detection and hubs against the same shared fixture GoldenTest.php snapshots -
 * mechanical correctness only (a run completes, is deterministic, external/vendor nodes are never
 * assigned a community, the two tools behave correctly before and after mapin:communities has run).
 * Whether the resulting communities line up with human-recognizable modules - SPEC.md section 14's
 * own acceptance gate for this phase - is not something a ~20-node invented fixture can answer
 * either way; that is checked separately, by hand, against a real application (see SPEC.md 1.12).
 */
beforeEach(function () {
    mapinCopyPhase2Fixtures($this->app);

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
    Route::post('/books', [BookController::class, 'store'])->name('books.store');
    $this->app->bind(PricingEngine::class, StandardPricingEngine::class);

    Artisan::call('mapin:build', ['--full' => true]);
});

afterEach(function () {
    mapinCleanupPhase2Fixtures($this->app);
});

it('communities tool reports not found before mapin:communities has run', function () {
    $query = new Query(new SqliteStore(config('mapin.storage')), base_path());
    $result = $query->communities();

    expect($result->found)->toBeFalse();
});

it('mapin:communities assigns communities, and node() surfaces membership through its own generic meta', function () {
    Artisan::call('mapin:communities', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report['community_count'])->toBeGreaterThan(0);
    expect($report['node_count'])->toBeGreaterThan(0);

    $query = new Query(new SqliteStore(config('mapin.storage')), base_path());
    $node = $query->node('class:Fixture\\Http\\Controllers\\BookController');

    expect($node->found)->toBeTrue();
    expect($node->result['meta'])->toHaveKey('community');
});

it('communities tool reports the summary once mapin:communities has run', function () {
    Artisan::call('mapin:communities');

    $query = new Query(new SqliteStore(config('mapin.storage')), base_path());
    $result = $query->communities();

    expect($result->found)->toBeTrue();
    expect($result->result['communities'])->not->toBeEmpty();
    expect($result->result['communities'][0])->toHaveKeys(['id', 'size', 'top_nodes', 'dominant_namespaces']);
});

it('produces the same community assignment across two separate mapin:communities runs', function () {
    Artisan::call('mapin:communities');
    $store = new SqliteStore(config('mapin.storage'));
    $first = $store->communityMembership();

    Artisan::call('mapin:communities');
    $second = $store->communityMembership();

    expect($second)->toBe($first);
});

it('never assigns a community to an external or vendor-indexed node', function () {
    Artisan::call('mapin:communities');
    $store = new SqliteStore(config('mapin.storage'));
    $membership = $store->communityMembership();

    $stmt = $store->pdo()->query("SELECT id FROM nodes WHERE type = 'external'");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $externalId) {
        expect($membership)->not->toHaveKey((int) $externalId);
    }
});

it('hubs tool reports degree always, and bridges only once communities have been computed', function () {
    $query = new Query(new SqliteStore(config('mapin.storage')), base_path());

    $before = $query->hubs(5);
    expect($before->found)->toBeTrue();
    expect($before->result)->not->toBeEmpty();
    expect($before->result[0]['bridges'])->toBeNull();

    Artisan::call('mapin:communities');
    $store = new SqliteStore(config('mapin.storage'));
    $after = (new Query($store, base_path()))->hubs(5);

    expect($after->result[0]['bridges'])->not->toBeNull();
});

it('hubs tool respects a types filter', function () {
    $query = new Query(new SqliteStore(config('mapin.storage')), base_path());
    $result = $query->hubs(50, ['route']);

    expect($result->found)->toBeTrue();
    foreach ($result->result as $item) {
        expect($item['type'])->toBe('route');
    }
});

it('drives hubs and communities through mapin:query exactly like the MCP server would', function () {
    Artisan::call('mapin:communities');

    Artisan::call('mapin:query', ['tool' => 'hubs', '--arg' => ['limit=3']]);
    $hubs = json_decode(Artisan::output(), true);
    expect($hubs['found'])->toBeTrue();
    expect($hubs['result'])->toHaveCount(3);

    Artisan::call('mapin:query', ['tool' => 'communities', '--arg' => []]);
    $communities = json_decode(Artisan::output(), true);
    expect($communities['found'])->toBeTrue();
});
