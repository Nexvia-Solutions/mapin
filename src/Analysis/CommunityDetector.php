<?php

declare(strict_types=1);

namespace Mapin\Analysis;

use Mapin\Store\SqliteStore;

/**
 * SPEC.md section 8's Communities step, run only by `mapin:communities` - never during a plain
 * build (section 8: "Runs on the stored graph, never during extraction"). Orchestrates, in order:
 * project the graph (GraphProjection), pull out nodes above the hub degree percentile so a single
 * god-class or heavily-injected service cannot dominate the modularity computation, run Louvain on
 * what remains, then reattach every excluded hub to whichever of its neighbours already has a
 * community - the same idea as any other "exclude the extreme, decide it afterward from context"
 * step, not a Louvain concept of its own.
 */
final class CommunityDetector
{
    public function __construct(
        private readonly float $resolution = 1.0,
        private readonly float $hubPercentile = 0.99,
    ) {}

    public function run(SqliteStore $store): CommunityReport
    {
        $graph = GraphProjection::build($store);
        $allIds = $graph->nodeIds();
        sort($allIds);

        $hubIds = $this->identifyHubs($graph, $allIds);
        $hubSet = array_fill_keys($hubIds, true);
        $reducedIds = array_values(array_diff($allIds, $hubIds));

        $reduced = new WeightedGraph;
        foreach ($reducedIds as $id) {
            $reduced->addNode($id);
        }
        $reducedSet = array_fill_keys($reducedIds, true);
        foreach ($reducedIds as $id) {
            foreach ($graph->neighbors($id) as $j => $weight) {
                if ($j > $id && isset($reducedSet[$j])) {
                    $reduced->addEdge($id, $j, $weight);
                }
            }
        }

        $communities = $reducedIds !== [] ? (new Louvain($this->resolution))->run($reduced) : [];

        // Reattach hubs in a fixed order (ascending id) so an already-reattached hub can serve as
        // another hub's own strongest neighbour, rather than every hub only ever looking at
        // non-hub neighbours.
        foreach ($hubIds as $hub) {
            $bestNeighbor = null;
            $bestWeight = -1.0;
            foreach ($graph->neighbors($hub) as $j => $weight) {
                if (isset($communities[$j]) && $weight > $bestWeight) {
                    $bestWeight = $weight;
                    $bestNeighbor = $j;
                }
            }
            $communities[$hub] = $bestNeighbor !== null ? $communities[$bestNeighbor] : $hub;
        }

        $this->write($store, $graph, $communities, $hubSet);

        return new CommunityReport(count(array_unique($communities)), count($communities), count($hubIds));
    }

    /**
     * @param  int[]  $allIds
     * @return int[]
     */
    private function identifyHubs(WeightedGraph $graph, array $allIds): array
    {
        $n = count($allIds);
        if ($n === 0) {
            return [];
        }

        $degrees = [];
        foreach ($allIds as $id) {
            $degrees[$id] = $graph->degree($id);
        }
        $sortedValues = array_values($degrees);
        sort($sortedValues);
        $thresholdIndex = (int) floor($this->hubPercentile * $n);
        if ($thresholdIndex >= $n) {
            // A percentile so high (or a graph so small) that nothing qualifies - not an error,
            // just nothing to exclude this run.
            return [];
        }
        $threshold = $sortedValues[$thresholdIndex];

        $hubs = [];
        foreach ($degrees as $id => $degree) {
            // > 0 guards a graph with no edges at all, where every degree is 0 and the threshold
            // itself is 0 - without it, every node would qualify as its own "hub".
            if ($degree > 0.0 && $degree >= $threshold) {
                $hubs[] = $id;
            }
        }
        sort($hubs);

        return $hubs;
    }

    /**
     * @param  array<int,int>  $communities  node id => representative node id (Louvain's own convention: the lowest original member id)
     * @param  array<int,true>  $hubSet
     */
    private function write(SqliteStore $store, WeightedGraph $graph, array $communities, array $hubSet): void
    {
        $nodeRows = $store->nodesByIds(array_merge(array_keys($communities), array_values($communities)));
        $keyFor = static fn (int $id): string => (string) ($nodeRows[$id]['key'] ?? "node:{$id}");

        /** @var array<int, int[]> $byRepresentative representative id => member node ids */
        $byRepresentative = [];
        foreach ($communities as $nodeId => $repId) {
            $byRepresentative[$repId][] = $nodeId;
        }

        $summaries = [];
        foreach ($byRepresentative as $repId => $memberIds) {
            sort($memberIds);
            $namespaceCounts = [];
            $members = [];
            foreach ($memberIds as $id) {
                $row = $nodeRows[$id] ?? null;
                if ($row === null) {
                    continue;
                }
                $members[] = ['key' => (string) $row['key'], 'degree' => $graph->degree($id)];
                if ($row['type'] === 'class' && str_contains((string) $row['name'], '\\')) {
                    $namespace = substr((string) $row['name'], 0, (int) strrpos((string) $row['name'], '\\'));
                    $namespaceCounts[$namespace] = ($namespaceCounts[$namespace] ?? 0) + 1;
                }
            }
            usort($members, static fn (array $a, array $b): int => ($b['degree'] <=> $a['degree']) ?: ($a['key'] <=> $b['key']));
            arsort($namespaceCounts);

            $summaries[] = [
                'id' => $keyFor($repId),
                'size' => count($memberIds),
                'top_nodes' => array_map(static fn (array $m) => $m['key'], array_slice($members, 0, 5)),
                'dominant_namespaces' => array_slice(array_keys($namespaceCounts), 0, 3),
            ];
        }
        usort($summaries, static fn (array $a, array $b): int => ($b['size'] <=> $a['size']) ?: ($a['id'] <=> $b['id']));

        $store->transaction(function () use ($store, $communities, $keyFor): void {
            $store->clearCommunityMeta();
            foreach ($communities as $nodeId => $repId) {
                $store->mergeNodeMeta($nodeId, ['community' => $keyFor($repId)]);
            }
        });

        $store->setMeta('communities_summary', (string) json_encode($summaries, JSON_UNESCAPED_SLASHES));
        $store->setMeta('communities_computed_at', gmdate('c'));
        $store->setMeta('communities_resolution', (string) $this->resolution);
    }
}
