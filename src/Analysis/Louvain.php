<?php

declare(strict_types=1);

namespace Mapin\Analysis;

/**
 * Standard Louvain modularity optimization (Blondel et al. 2008), two repeating phases: local
 * moving (each node joins whichever neighboring community gives the best modularity gain, or
 * stays put) until no node moves, then aggregation (each community becomes one node in a smaller
 * graph, carrying its internal edges forward as a self-loop - see WeightedGraph's own docblock for
 * why that needs first-class support) - repeated on the smaller graph until it stops shrinking.
 *
 * Deterministic by construction, not just by convention: every node iteration order is the node
 * ids sorted ascending, and a tied modularity gain between two candidate communities always
 * prefers the lower community id - SPEC.md section 8's own requirement ("two runs on the same
 * graph give the same result") holds regardless of PHP's array iteration order or floating-point
 * evaluation order, both of which are otherwise not guaranteed stable across runs.
 */
final class Louvain
{
    private const EPSILON = 1e-9;

    public function __construct(private readonly float $resolution = 1.0) {}

    /**
     * @return array<int,int> original node id => final community id (the lowest original node id
     *                        belonging to that community, so ids are stable and meaningful without a separate relabeling
     *                        pass)
     */
    public function run(WeightedGraph $graph): array
    {
        $mapping = [];
        foreach ($graph->nodeIds() as $id) {
            $mapping[$id] = $id;
        }

        $current = $graph;
        while (true) {
            $communities = $this->localMove($current);

            foreach ($mapping as $original => $representative) {
                $mapping[$original] = $communities[$representative];
            }

            $aggregated = $this->aggregate($current, $communities);
            if ($aggregated->nodeCount() >= $current->nodeCount()) {
                break;
            }
            $current = $aggregated;
        }

        return $this->relabelByLowestMember($mapping);
    }

    /** @return array<int,int> node id (in $graph) => community id (one of $graph's own node ids) */
    private function localMove(WeightedGraph $graph): array
    {
        $nodeIds = $graph->nodeIds();
        sort($nodeIds);

        $community = [];
        $sigmaTot = [];
        foreach ($nodeIds as $id) {
            $community[$id] = $id;
            $sigmaTot[$id] = $graph->degree($id);
        }

        $m = $graph->totalWeight();
        if ($m <= 0.0) {
            return $community;
        }

        $improved = true;
        while ($improved) {
            $improved = false;
            foreach ($nodeIds as $i) {
                $ki = $graph->degree($i);
                $home = $community[$i];

                /** @var array<int,float> $weightToCommunity community id => weight from $i to it */
                $weightToCommunity = [];
                foreach ($graph->neighbors($i) as $j => $weight) {
                    $c = $community[$j];
                    $weightToCommunity[$c] = ($weightToCommunity[$c] ?? 0.0) + $weight;
                }

                // Pull $i out of its own community before scoring any move, including staying -
                // otherwise $i's own degree would count towards sigmaTot[home] twice.
                $sigmaTot[$home] -= $ki;

                $best = $home;
                $bestGain = $this->gain($weightToCommunity[$home] ?? 0.0, $sigmaTot[$home], $ki, $m);

                foreach ($weightToCommunity as $c => $kiIn) {
                    if ($c === $home) {
                        continue;
                    }
                    $gain = $this->gain($kiIn, $sigmaTot[$c], $ki, $m);
                    if ($gain > $bestGain + self::EPSILON || (abs($gain - $bestGain) <= self::EPSILON && $c < $best)) {
                        $bestGain = $gain;
                        $best = $c;
                    }
                }

                $sigmaTot[$best] += $ki;
                if ($best !== $home) {
                    $community[$i] = $best;
                    $improved = true;
                }
            }
        }

        return $community;
    }

    /** Blondel et al.'s simplified gain formula for moving an isolated node into a community - valid for comparing candidates against each other, not a standalone absolute delta-Q. */
    private function gain(float $kiIn, float $sigmaTot, float $ki, float $m): float
    {
        return $kiIn / $m - $this->resolution * $sigmaTot * $ki / (2.0 * $m * $m);
    }

    /** @param array<int,int> $community node id in $graph => community id */
    private function aggregate(WeightedGraph $graph, array $community): WeightedGraph
    {
        $aggregated = new WeightedGraph;
        foreach (array_unique($community) as $c) {
            $aggregated->addNode($c);
        }

        $nodeIds = $graph->nodeIds();
        sort($nodeIds);
        foreach ($nodeIds as $i) {
            $ci = $community[$i];
            $aggregated->addEdge($ci, $ci, $graph->selfLoop($i));
            foreach ($graph->neighbors($i) as $j => $weight) {
                if ($j <= $i) {
                    // Each undirected edge is stored both ways in $graph - only fold it in once.
                    continue;
                }
                $cj = $community[$j];
                if ($ci === $cj) {
                    // An edge internal to one community becomes part of that meta-node's own
                    // self-loop, not an edge to itself in the usual sense.
                    $aggregated->addEdge($ci, $ci, $weight);
                } else {
                    $aggregated->addEdge($ci, $cj, $weight);
                }
            }
        }

        return $aggregated;
    }

    /**
     * Relabels community ids from arbitrary survivors of repeated aggregation to the lowest
     * original node id in each community - stable and meaningful (a lone class always anchors its
     * own community id at that class's own node id) rather than an aggregation artifact.
     *
     * @param  array<int,int>  $mapping
     * @return array<int,int>
     */
    private function relabelByLowestMember(array $mapping): array
    {
        $lowest = [];
        foreach ($mapping as $node => $community) {
            if (! isset($lowest[$community]) || $node < $lowest[$community]) {
                $lowest[$community] = $node;
            }
        }

        $relabeled = [];
        foreach ($mapping as $node => $community) {
            $relabeled[$node] = $lowest[$community];
        }

        return $relabeled;
    }
}
