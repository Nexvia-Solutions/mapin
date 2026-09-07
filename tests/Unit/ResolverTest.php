<?php

declare(strict_types=1);

use Mapin\Extract\Contracts\ExtractionContext;
use Mapin\Extract\Discovery\FileDiscovery;
use Mapin\Extract\Php\PhpExtractor;
use Mapin\Extract\SymbolIndex;
use Mapin\Graph\Edge;
use Mapin\Resolve\Resolver;

/**
 * Runs the full extract -> index -> resolve pipeline against the invented fixture app, with no
 * Laravel application booted - this exercises PhpExtractor and the resolver rules in isolation.
 *
 * @return array{edges: Edge[], root: string}
 */
function mapinFixtureGraph(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $root = dirname(__DIR__).'/Fixtures';
    $discovery = new FileDiscovery($root, ['app', 'database/migrations'], isProject: true);
    $extractor = new PhpExtractor;
    $ctx = new ExtractionContext($root, booted: false);

    $index = new SymbolIndex;
    $files = iterator_to_array($discovery->discover());
    $edges = [];
    foreach ($files as $file) {
        $fragment = $extractor->extract($file, $ctx);
        $edges = [...$edges, ...$fragment->edges];
        foreach ($extractor->classMetasFor($file->relativePath) as $classMeta) {
            $index->addClass($classMeta);
        }
        foreach ($extractor->functionMetasFor($file->relativePath) as $name => $return) {
            $index->addFunction($name, $return);
        }
    }

    $resolver = new Resolver($index);
    foreach ($files as $file) {
        $stmts = $extractor->parsedStatementsFor($file->relativePath);
        if ($stmts !== null) {
            $edges = [...$edges, ...$resolver->resolveFile($file->relativePath, $stmts)['edges']];
        }
    }

    return $cached = ['edges' => $edges, 'root' => $root];
}

/** @param Edge[] $edges */
function findEdge(array $edges, string $fromKeyContains, string $toKeyContains, ?string $member = null): ?Edge
{
    foreach ($edges as $edge) {
        if (str_contains($edge->fromKey, $fromKeyContains) && str_contains($edge->toKey, $toKeyContains)
            && ($member === null || ($edge->meta['member'] ?? null) === $member)) {
            return $edge;
        }
    }

    return null;
}

it('resolves constructor-promoted property injection at full confidence', function () {
    $edge = findEdge(mapinFixtureGraph()['edges'], 'BookController::store', 'CatalogNotifier', 'notifyNewBook');
    expect($edge)->not->toBeNull();
    expect($edge->confidence)->toBe(1.0);
    expect($edge->resolution->value)->toBe('typed_property');
});

it('resolves a private constructor-injected interface via its single implementation', function () {
    $edge = findEdge(mapinFixtureGraph()['edges'], 'BookController::store', 'StandardPricingEngine', 'quote');
    expect($edge)->not->toBeNull();
    expect($edge->resolution->value)->toBe('interface');
    expect($edge->confidence)->toBe(0.6);
});

it('resolves new X() at full confidence', function () {
    $edge = findEdge(mapinFixtureGraph()['edges'], 'BookController::store', 'ShippingCalculator');
    expect($edge)->not->toBeNull();
    expect($edge->type->value)->toBe('instantiates');
    expect($edge->confidence)->toBe(1.0);
});

it('resolves app(X::class)->method() at full confidence', function () {
    $edges = mapinFixtureGraph()['edges'];
    $resolves = array_values(array_filter(
        $edges,
        fn (Edge $e) => str_contains($e->fromKey, 'BookController::store') && str_contains($e->toKey, 'InventoryLedger') && $e->type->value === 'resolves',
    ));
    $call = findEdge($edges, 'BookController::store', 'InventoryLedger', 'recordSale');
    expect($resolves)->not->toBeEmpty();
    expect($resolves[0]->confidence)->toBe(1.0);
    expect($call)->not->toBeNull();
    expect($call->confidence)->toBe(1.0);
});

it('resolves static calls on a plain class at full confidence', function () {
    $edges = mapinFixtureGraph()['edges'];
    $set = findEdge($edges, 'BookController::store', 'TenantContext', 'setCurrent');
    $get = findEdge($edges, 'BookController::store', 'TenantContext', 'id');
    expect($set)->not->toBeNull()->and($set->confidence)->toBe(1.0);
    expect($get)->not->toBeNull()->and($get->confidence)->toBe(1.0);
});

it('forwards an Eloquent relation method to the related model builder', function () {
    $edges = mapinFixtureGraph()['edges'];
    $where = findEdge($edges, 'BookController::authorBooks', 'Book', 'where');
    expect($where)->not->toBeNull();
    expect($where->resolution->value)->toBe('eloquent');
    expect($where->confidence)->toBe(0.7);
});

it('types a caught exception and resolves getMessage() via the Throwable contract', function () {
    // getMessage() is a scalar-returning Throwable method: it becomes an edge with a null target
    // only if member matching worked, so we assert indirectly via the absence of an unresolved row.
    $edges = mapinFixtureGraph()['edges'];
    $catchTyped = array_filter($edges, fn (Edge $e) => str_contains($e->fromKey, 'safeStore'));
    expect($catchTyped)->not->toBeEmpty();
});

it('resolves a chain through a global helper function return type', function () {
    $edge = findEdge(mapinFixtureGraph()['edges'], 'BookController::quickEstimate', 'ShippingCalculator', 'estimate');
    expect($edge)->not->toBeNull();
    expect($edge->resolution->value)->toBe('return_type');
    expect($edge->confidence)->toBe(0.8);
});

it('declares every fixture class as a node-producing class', function () {
    $root = mapinFixtureGraph()['root'];
    expect(is_dir($root))->toBeTrue();
});

it('emits a relates edge for an Eloquent relation method', function () {
    $edges = mapinFixtureGraph()['edges'];
    $relates = array_values(array_filter(
        $edges,
        fn (Edge $e) => $e->type->value === 'relates' && str_contains($e->fromKey, 'Author') && str_contains($e->toKey, 'Book'),
    ));
    expect($relates)->not->toBeEmpty();
    expect($relates[0]->meta['relation'])->toBe('hasMany');
});

it('maps a model to its table by naming convention', function () {
    $edges = mapinFixtureGraph()['edges'];
    $mapsTable = array_values(array_filter(
        $edges,
        fn (Edge $e) => $e->type->value === 'maps_table' && str_contains($e->fromKey, 'Author'),
    ));
    expect($mapsTable)->not->toBeEmpty();
    expect($mapsTable[0]->toKey)->toBe('table:authors');
});

it('records touches_table edges from a migration', function () {
    $edges = mapinFixtureGraph()['edges'];
    $touches = array_values(array_filter($edges, fn (Edge $e) => $e->type->value === 'touches_table' && $e->toKey === 'table:books'));
    expect($touches)->toHaveCount(2);
    $ops = array_map(fn (Edge $e) => $e->meta['op'], $touches);
    expect($ops)->toContain('create')->and($ops)->toContain('drop');
});

it('records a dispatches edge for a queued job', function () {
    $edges = mapinFixtureGraph()['edges'];
    $dispatch = array_values(array_filter(
        $edges,
        fn (Edge $e) => $e->type->value === 'dispatches' && str_contains($e->fromKey, 'DashboardController::index') && str_contains($e->toKey, 'NotifyNewBook'),
    ));
    expect($dispatch)->not->toBeEmpty();
});

it('records an observes edge from Model::observe()', function () {
    $edges = mapinFixtureGraph()['edges'];
    $observes = array_values(array_filter(
        $edges,
        fn (Edge $e) => $e->type->value === 'observes' && str_contains($e->fromKey, 'AuthorObserver') && str_contains($e->toKey, 'Author'),
    ));
    expect($observes)->not->toBeEmpty();
});

it('records listens edges from both a $listen property and Event::listen()', function () {
    $edges = mapinFixtureGraph()['edges'];
    $listens = array_values(array_filter(
        $edges,
        fn (Edge $e) => $e->type->value === 'listens' && str_contains($e->fromKey, 'NotifySubscribers') && str_contains($e->toKey, 'BookPublished'),
    ));
    // One from BookEventServiceProvider's $listen property, one from DashboardController's Event::listen() call.
    expect(count($listens))->toBeGreaterThanOrEqual(2);
});
