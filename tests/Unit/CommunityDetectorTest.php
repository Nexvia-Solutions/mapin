<?php

declare(strict_types=1);

use Mapin\Analysis\CommunityDetector;
use Mapin\Extract\Contracts\SourceFile;
use Mapin\Graph\Edge;
use Mapin\Graph\EdgeType;
use Mapin\Graph\Node;
use Mapin\Graph\NodeType;
use Mapin\Store\SqliteStore;

/**
 * Pure store-level tests, no Laravel app boot (same reasoning as LouvainTest/ResolverTest):
 * CommunityDetector only ever needs a real SqliteStore, which takes a plain file path.
 */
function communityTestStore(): SqliteStore
{
    return new SqliteStore(sys_get_temp_dir().'/mapin-community-test-'.bin2hex(random_bytes(6)).'.sqlite');
}

it('excludes a clear hub from Louvain, splits the two triangles it was bridging, then reattaches it by strongest edge', function () {
    $store = communityTestStore();

    $classes = ['A1', 'A2', 'A3', 'B1', 'B2', 'B3', 'Hub'];
    $store->upsertNodes(array_map(
        static fn (string $c) => new Node(NodeType::ClassLike, "App\\{$c}", "class:App\\{$c}"),
        $classes,
    ), null);
    $ids = $store->nodeIds(array_map(static fn (string $c) => "class:App\\{$c}", $classes));

    $edges = [
        new Edge(EdgeType::Calls, 'class:App\A1', 'class:App\A2'),
        new Edge(EdgeType::Calls, 'class:App\A2', 'class:App\A3'),
        new Edge(EdgeType::Calls, 'class:App\A1', 'class:App\A3'),
        new Edge(EdgeType::Calls, 'class:App\B1', 'class:App\B2'),
        new Edge(EdgeType::Calls, 'class:App\B2', 'class:App\B3'),
        new Edge(EdgeType::Calls, 'class:App\B1', 'class:App\B3'),
        // Hub touches every node in both triangles, degree 6 against everyone else's 3 - the
        // clearest possible top-1%-of-7 hub. Two distinct edges (different lines) to A1
        // specifically make it unambiguously Hub's strongest neighbour, so reattachment has one
        // correct answer regardless of SQL row order or PHP array iteration order.
        new Edge(EdgeType::Calls, 'class:App\Hub', 'class:App\A1', line: 1),
        new Edge(EdgeType::Calls, 'class:App\Hub', 'class:App\A1', line: 2),
        new Edge(EdgeType::Calls, 'class:App\Hub', 'class:App\A2'),
        new Edge(EdgeType::Calls, 'class:App\Hub', 'class:App\A3'),
        new Edge(EdgeType::Calls, 'class:App\Hub', 'class:App\B1'),
        new Edge(EdgeType::Calls, 'class:App\Hub', 'class:App\B2'),
        new Edge(EdgeType::Calls, 'class:App\Hub', 'class:App\B3'),
    ];
    $store->insertEdges($edges, $ids, null);

    $report = (new CommunityDetector(resolution: 1.0, hubPercentile: 0.99))->run($store);

    expect($report->hubCount)->toBe(1);
    expect($report->communityCount)->toBe(2);
    expect($report->nodeCount)->toBe(7);

    $membership = $store->communityMembership();
    $byClass = [];
    foreach ($classes as $c) {
        $byClass[$c] = $membership[$ids["class:App\\{$c}"]];
    }

    expect($byClass['A1'])->toBe($byClass['A2'])->toBe($byClass['A3']);
    expect($byClass['B1'])->toBe($byClass['B2'])->toBe($byClass['B3']);
    expect($byClass['A1'])->not->toBe($byClass['B1']);
    // Hub's strongest edge (weight 2) is to A1, so it must land in A1's community, not B's.
    expect($byClass['Hub'])->toBe($byClass['A1']);
});

it('clears a stale community assignment from a node that no longer qualifies for the projection', function () {
    // Real bug, not hypothetical: an earlier version of GraphProjection's node filter let every
    // `table`/`middleware`/`doc`/`section`/`file`/`component` node into the graph as a permanently
    // isolated singleton, so they each got their own bogus community - found running this for real
    // against a live application (SPEC.md 1.12). mergeNodeMeta() only ever adds or updates a key,
    // so fixing the filter alone would not have removed what a previous, buggier run had already
    // written; this simulates exactly that stale state without needing the old buggy code back.
    $store = communityTestStore();
    $store->upsertNodes([new Node(NodeType::Table, 'orders', 'table:orders')], null);
    $tableId = $store->nodeIds(['table:orders'])['table:orders'];
    $store->mergeNodeMeta($tableId, ['community' => 'class:App\SomeStaleAssignment']);

    $store->upsertNodes([
        new Node(NodeType::ClassLike, 'App\A', 'class:App\A'),
        new Node(NodeType::ClassLike, 'App\B', 'class:App\B'),
    ], null);
    $ids = $store->nodeIds(['class:App\A', 'class:App\B']);
    $store->insertEdges([new Edge(EdgeType::Calls, 'class:App\A', 'class:App\B')], $ids, null);

    (new CommunityDetector)->run($store);

    expect($store->communityMembership())->not->toHaveKey($tableId);
});

it('never assigns a community to an external node or a vendor-indexed class', function () {
    $store = communityTestStore();

    $vendorFileId = $store->upsertFile(new SourceFile('vendor/laravel/framework/src/Illuminate/Support/Facades/DB.php', '/tmp/does-not-matter', 'php', 'h1', isProject: false), 10);

    $store->upsertNodes([
        new Node(NodeType::ClassLike, 'App\Service', 'class:App\Service'),
        new Node(NodeType::ClassLike, 'App\Other', 'class:App\Other'),
    ], null);
    $store->upsertNodes([
        new Node(NodeType::ClassLike, 'Illuminate\Support\Facades\DB', 'class:Illuminate\Support\Facades\DB'),
    ], $vendorFileId);
    $store->upsertNodes([
        new Node(NodeType::External, 'Some\Unindexed\Thing', 'external:Some\Unindexed\Thing'),
    ], null);

    $ids = $store->nodeIds([
        'class:App\Service', 'class:App\Other',
        'class:Illuminate\Support\Facades\DB', 'external:Some\Unindexed\Thing',
    ]);
    $store->insertEdges([
        new Edge(EdgeType::Calls, 'class:App\Service', 'class:App\Other'),
        new Edge(EdgeType::Calls, 'class:App\Service', 'class:Illuminate\Support\Facades\DB'),
        new Edge(EdgeType::Calls, 'class:App\Service', 'external:Some\Unindexed\Thing'),
    ], $ids, null);

    (new CommunityDetector)->run($store);

    $membership = $store->communityMembership();
    expect($membership)->toHaveKey($ids['class:App\Service']);
    expect($membership)->toHaveKey($ids['class:App\Other']);
    expect($membership)->not->toHaveKey($ids['class:Illuminate\Support\Facades\DB']);
    expect($membership)->not->toHaveKey($ids['external:Some\Unindexed\Thing']);
});

it('produces the same community assignment and summary across repeated runs', function () {
    $store = communityTestStore();
    $store->upsertNodes([
        new Node(NodeType::ClassLike, 'App\X1', 'class:App\X1'),
        new Node(NodeType::ClassLike, 'App\X2', 'class:App\X2'),
        new Node(NodeType::ClassLike, 'App\X3', 'class:App\X3'),
    ], null);
    $ids = $store->nodeIds(['class:App\X1', 'class:App\X2', 'class:App\X3']);
    $store->insertEdges([
        new Edge(EdgeType::Calls, 'class:App\X1', 'class:App\X2'),
        new Edge(EdgeType::Calls, 'class:App\X2', 'class:App\X3'),
    ], $ids, null);

    (new CommunityDetector)->run($store);
    $first = $store->communityMembership();
    $firstSummary = $store->getMeta('communities_summary');

    (new CommunityDetector)->run($store);
    $second = $store->communityMembership();
    $secondSummary = $store->getMeta('communities_summary');

    expect($second)->toBe($first);
    expect($secondSummary)->toBe($firstSummary);
});

it('writes a community summary with size, top nodes and dominant namespaces', function () {
    $store = communityTestStore();
    $store->upsertNodes([
        new Node(NodeType::ClassLike, 'App\Billing\Invoice', 'class:App\Billing\Invoice'),
        new Node(NodeType::ClassLike, 'App\Billing\Payment', 'class:App\Billing\Payment'),
    ], null);
    $ids = $store->nodeIds(['class:App\Billing\Invoice', 'class:App\Billing\Payment']);
    $store->insertEdges([
        new Edge(EdgeType::Calls, 'class:App\Billing\Invoice', 'class:App\Billing\Payment'),
    ], $ids, null);

    (new CommunityDetector)->run($store);

    $summary = json_decode((string) $store->getMeta('communities_summary'), true);
    expect($summary)->toHaveCount(1);
    expect($summary[0]['size'])->toBe(2);
    expect($summary[0]['dominant_namespaces'])->toContain('App\Billing');
    expect($summary[0]['top_nodes'])->toContain('class:App\Billing\Invoice');
});
