<?php

declare(strict_types=1);

use Fixture\Contracts\PricingEngine;
use Fixture\Http\Controllers\BookController;
use Fixture\Http\Controllers\DashboardController;
use Fixture\Services\StandardPricingEngine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\Fluent\AssertableJson;
use Mapin\Mcp\Adapters\FindToolAdapter;
use Mapin\Mcp\Adapters\ImpactToolAdapter;
use Mapin\Mcp\Adapters\RouteToolAdapter;
use Mapin\Mcp\Adapters\StatsToolAdapter;
use Mapin\Mcp\MapinServer;

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

it('answers a real MCP tools/call for find, with the response envelope', function () {
    $response = MapinServer::tool(FindToolAdapter::class, ['name' => 'BookController'])->assertOk();

    $response->assertStructuredContent(fn (AssertableJson $c) => $c
        ->where('found', true)
        ->where('query.tool', 'find')
        ->has('result')
        ->has('graph', fn (AssertableJson $graph) => $graph
            ->hasAll(['built_commit', 'head_commit', 'files_changed_since_build', 'built_at']))
        ->etc());
});

it('answers a real MCP tools/call for route, resolving the handler', function () {
    $response = MapinServer::tool(RouteToolAdapter::class, ['route' => 'dashboard.index'])->assertOk();

    $response->assertStructuredContent(fn (AssertableJson $c) => $c
        ->where('found', true)
        ->where('result.action', DashboardController::class.'@index')
        ->etc());
});

it('answers a real MCP tools/call for impact', function () {
    $response = MapinServer::tool(ImpactToolAdapter::class, [
        'key' => 'method:Fixture\\Services\\StandardPricingEngine::quote',
        'depth' => 3,
    ])->assertOk();

    $response->assertStructuredContent(fn (AssertableJson $c) => $c
        ->where('found', true)
        ->has('result.routes.0')
        ->etc());
});

it('answers a real MCP tools/call for stats with no arguments', function () {
    $response = MapinServer::tool(StatsToolAdapter::class, [])->assertOk();

    $response->assertStructuredContent(fn (AssertableJson $c) => $c
        ->where('found', true)
        ->where('result.counts.nodes', fn (int $nodes) => $nodes > 0)
        ->etc());
});

it('returns found: false with labelled suggestions, never a guess, for a node that does not exist', function () {
    $response = MapinServer::tool(FindToolAdapter::class, ['name' => 'TotallyMadeUpClassName'])->assertOk();

    $response->assertStructuredContent(fn (AssertableJson $c) => $c
        ->where('found', false)
        ->missing('result')
        ->has('suggestions')
        ->etc());
});

it('resolves a class by short name through find without fuzzy, and rejects a real substring without it', function () {
    $exact = MapinServer::tool(FindToolAdapter::class, ['name' => 'BookController'])->assertOk();
    $exact->assertStructuredContent(fn (AssertableJson $c) => $c->where('found', true)->etc());

    // "Contro" is a genuine substring of "BookController", but not any node's own short name (that
    // is "BookController" itself) - exactly the "look-alike, not the real thing" case fuzzy exists for.
    $partial = MapinServer::tool(FindToolAdapter::class, ['name' => 'Contro'])->assertOk();
    $partial->assertStructuredContent(fn (AssertableJson $c) => $c->where('found', false)->etc());

    $fuzzy = MapinServer::tool(FindToolAdapter::class, ['name' => 'Contro', 'fuzzy' => true])->assertOk();
    $fuzzy->assertStructuredContent(fn (AssertableJson $c) => $c->where('found', true)->etc());
});

it('drives the exact same tool through mapin:query as the MCP server does', function () {
    Artisan::call('mapin:query', ['tool' => 'stats', '--arg' => []]);
    $result = json_decode(Artisan::output(), true);

    expect($result['found'])->toBeTrue();
    expect($result['query']['tool'])->toBe('stats');
    expect($result['result']['counts']['nodes'])->toBeGreaterThan(0);
});

it('passes typed arguments through mapin:query', function () {
    Artisan::call('mapin:query', ['tool' => 'find', '--arg' => ['name=BookController', 'fuzzy=true']]);
    $result = json_decode(Artisan::output(), true);

    expect($result['found'])->toBeTrue();
});

it('reports a clean bill of health via mapin:doctor after a build', function () {
    Artisan::call('mapin:doctor', ['--json' => true]);
    $result = json_decode(Artisan::output(), true);

    expect($result['checks']['graph_exists']['ok'])->toBeTrue();
    expect($result['checks']['last_build']['ok'])->toBeTrue();
});
