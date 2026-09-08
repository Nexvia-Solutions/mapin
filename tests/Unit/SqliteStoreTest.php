<?php

declare(strict_types=1);

use Mapin\Extract\Contracts\SourceFile;
use Mapin\Graph\Edge;
use Mapin\Graph\EdgeType;
use Mapin\Graph\Node;
use Mapin\Graph\NodeType;
use Mapin\Store\SqliteStore;

/**
 * Pure store-level tests, no Laravel app boot (same reasoning as LouvainTest/CommunityDetectorTest):
 * SqliteStore only ever needs a real file path.
 */
function sqliteStoreTestStore(): SqliteStore
{
    return new SqliteStore(sys_get_temp_dir().'/mapin-sqlite-store-test-'.bin2hex(random_bytes(6)).'.sqlite');
}

it('insertEdges counts every row SQLite\'s own null-distinct UNIQUE index actually keeps, not a PHP-side tuple dedup', function () {
    // Regression for a real bug (found 2026-09-08 verifying the middleware-count fix above against
    // a real application: the reported total came up 88 short of SqliteStore::counts()['edges']).
    // edges.line is nullable, and SQLite's own UNIQUE(type, from_id, to_id, file_id, line) treats
    // every NULL as distinct from every other NULL (standard SQL null-handling) - two Edge objects
    // sharing (type, from_id, to_id, file_id) with line: null are NOT the same row on conflict, they
    // are two real rows the table genuinely keeps. A first fix attempt built a PHP string key from
    // those same five columns and counted unique keys - correct for edges with a real line number,
    // silently wrong for line: null, because "null" concatenates the same way every time in PHP.
    $store = sqliteStoreTestStore();
    $store->upsertNodes([
        new Node(NodeType::ClassLike, 'App\A', 'class:App\A'),
        new Node(NodeType::ClassLike, 'App\B', 'class:App\B'),
    ], null);
    $ids = $store->nodeIds(['class:App\A', 'class:App\B']);

    // Same type, same from/to, no file (like a route or binding edge), no line - the exact shape
    // RouteExtractor's own uses_middleware edges take, and the shape that hid this in a real build.
    $edges = [
        new Edge(EdgeType::Calls, 'class:App\A', 'class:App\B', line: null),
        new Edge(EdgeType::Calls, 'class:App\A', 'class:App\B', line: null),
    ];

    $reported = $store->insertEdges($edges, $ids, null);

    $real = (int) $store->pdo()->query('SELECT COUNT(*) FROM edges')->fetchColumn();
    expect($reported)->toBe($real)
        ->and($real)->toBe(2); // SQLite really does keep both rows - this is not itself a bug to fix.
});

it('insertEdges still collapses a genuine duplicate edge (same file and line, both non-null) to the one row ON CONFLICT keeps', function () {
    // A real conflict only fires when every column in the UNIQUE tuple is non-null and equal - a
    // null file_id (the previous test) is already enough on its own for SQLite to treat two
    // otherwise-identical rows as distinct, regardless of line. This test needs a real file_id to
    // actually exercise the ON CONFLICT DO UPDATE path this method's own SQL declares.
    $store = sqliteStoreTestStore();
    $store->upsertNodes([
        new Node(NodeType::ClassLike, 'App\A', 'class:App\A'),
        new Node(NodeType::ClassLike, 'App\B', 'class:App\B'),
    ], null);
    $ids = $store->nodeIds(['class:App\A', 'class:App\B']);
    $fileId = $store->upsertFile(new SourceFile('app/A.php', '/tmp/app/A.php', 'php', 'deadbeef'), 100);

    $edges = [
        new Edge(EdgeType::Calls, 'class:App\A', 'class:App\B', line: 10),
        new Edge(EdgeType::Calls, 'class:App\A', 'class:App\B', line: 10),
    ];

    $reported = $store->insertEdges($edges, $ids, $fileId);

    $real = (int) $store->pdo()->query('SELECT COUNT(*) FROM edges')->fetchColumn();
    expect($reported)->toBe($real)
        ->and($real)->toBe(1);
});
