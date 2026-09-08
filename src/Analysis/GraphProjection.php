<?php

declare(strict_types=1);

namespace Mapin\Analysis;

use Mapin\Store\SqliteStore;

/**
 * Builds the undirected weighted projection SPEC.md section 8 describes for Louvain, also reused
 * as-is by the `hubs` tool, which needs the identical graph to report a meaningful degree.
 *
 * Node inclusion is an allowlist, not a denylist, and that distinction is load-bearing: only
 * `class`, `method`, `route`, `view` and `function` nodes can ever be an endpoint of one of
 * EDGE_TYPES (cross-checked against every row in SPEC.md section 3.2's own edge table). A first
 * version excluded only `external` nodes and vendor-backed classes, which let every `table`,
 * `middleware`, `doc`, `section`, `concept` and `component` node into the graph as a permanently
 * isolated singleton - structurally incapable of ever having a qualifying edge, since their own
 * edge types (`maps_table`, `uses_middleware`, `documents`, `mentions`, `uses_component`, ...) are
 * not in EDGE_TYPES at all. Against the real application this was checked against, that alone
 * accounted for the large majority of a wildly inflated community count (roughly 10,000 of ~15,700
 * "communities" were lone Markdown section/doc nodes that could never have merged with anything) -
 * a real bug, not a hypothetical one, found by that same real-app check rather than invented in
 * advance. "External nodes and facades are excluded" is still true under the allowlist: `external`
 * is not one of the five allowed types, and neither is a vendor-backed `class`/`method` (Laravel's
 * own facade classes live entirely inside the framework source `config('mapin.vendor_paths')`
 * indexes for type resolution only, never as "your code" - config/mapin.php's own docblock).
 */
final class GraphProjection
{
    /** @var string[] SPEC.md section 8's own edge type list for the community/hub projection. */
    public const EDGE_TYPES = [
        'calls', 'injects', 'instantiates', 'routes_to', 'renders',
        'includes', 'relates', 'dispatches', 'listens',
    ];

    /** @var string[] Node types that can ever be an endpoint of one of EDGE_TYPES - see class docblock. */
    private const ELIGIBLE_NODE_TYPES = ['class', 'method', 'route', 'view', 'function'];

    public static function build(SqliteStore $store): WeightedGraph
    {
        $graph = new WeightedGraph;
        $pdo = $store->pdo();

        $includedIds = self::projectNodeIds($pdo);
        foreach ($includedIds as $id) {
            $graph->addNode($id);
        }
        $included = array_fill_keys($includedIds, true);

        $placeholders = implode(',', array_fill(0, count(self::EDGE_TYPES), '?'));
        $stmt = $pdo->prepare(
            "SELECT from_id, to_id, COUNT(*) AS weight FROM edges
             WHERE type IN ({$placeholders}) AND from_id != to_id
             GROUP BY from_id, to_id",
        );
        $stmt->execute(self::EDGE_TYPES);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $from = (int) $row['from_id'];
            $to = (int) $row['to_id'];
            if (isset($included[$from], $included[$to])) {
                $graph->addEdge($from, $to, (float) $row['weight']);
            }
        }

        return $graph;
    }

    /** @return int[] */
    private static function projectNodeIds(\PDO $pdo): array
    {
        $placeholders = implode(',', array_fill(0, count(self::ELIGIBLE_NODE_TYPES), '?'));
        $stmt = $pdo->prepare(
            "SELECT n.id FROM nodes n LEFT JOIN files f ON f.id = n.file_id
             WHERE n.type IN ({$placeholders})
                AND (n.type NOT IN ('class', 'method') OR COALESCE(f.is_project, 1) = 1)",
        );
        $stmt->execute(self::ELIGIBLE_NODE_TYPES);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
