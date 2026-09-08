<?php

declare(strict_types=1);

namespace Mapin\Analysis;

/**
 * A plain undirected weighted graph over integer node ids - no dependency on SqliteStore or any
 * Mapin-specific concept, so Louvain itself can be unit-tested against a hand-built graph with a
 * known correct answer, independent of how a real codebase graph gets projected into one.
 *
 * Self-loops are tracked separately from ordinary edges (`$selfLoop`, not `$adjacency`) rather than
 * as `adjacency[i][i]`, because they need different arithmetic everywhere they are used: a
 * self-loop of weight w contributes w once to the graph's total weight but 2w to its own node's
 * degree (the standard convention - a self-loop has two stubs, both attached to the same node).
 * Louvain's own aggregation step is the reason this class needs to support them at all: collapsing
 * a community into one node folds every edge that used to be internal to that community into a
 * self-loop on the resulting meta-node, which the next level's modularity computation must still
 * count correctly, or every level after the first would silently be wrong.
 */
final class WeightedGraph
{
    /** @var array<int, array<int, float>> node id => (neighbor id => summed edge weight), excludes self-loops */
    private array $adjacency = [];

    /** @var array<int, float> node id => summed self-loop weight */
    private array $selfLoop = [];

    public function addNode(int $id): void
    {
        $this->adjacency[$id] ??= [];
    }

    /** Undirected: adds both directions for $a !== $b, or accumulates a self-loop when $a === $b. Repeated calls for the same pair sum weight rather than overwrite. */
    public function addEdge(int $a, int $b, float $weight = 1.0): void
    {
        if ($weight <= 0.0) {
            return;
        }
        $this->addNode($a);
        if ($a === $b) {
            $this->selfLoop[$a] = ($this->selfLoop[$a] ?? 0.0) + $weight;

            return;
        }
        $this->addNode($b);
        $this->adjacency[$a][$b] = ($this->adjacency[$a][$b] ?? 0.0) + $weight;
        $this->adjacency[$b][$a] = ($this->adjacency[$b][$a] ?? 0.0) + $weight;
    }

    /** @return array<int, float> neighbor id => weight, excludes self-loops - see selfLoop() */
    public function neighbors(int $id): array
    {
        return $this->adjacency[$id] ?? [];
    }

    public function selfLoop(int $id): float
    {
        return $this->selfLoop[$id] ?? 0.0;
    }

    /** Weighted degree, self-loop counted twice per the standard convention - see class docblock. */
    public function degree(int $id): float
    {
        return array_sum($this->adjacency[$id] ?? []) + 2.0 * $this->selfLoop($id);
    }

    /** @return int[] every node id currently in the graph, in insertion order */
    public function nodeIds(): array
    {
        return array_keys($this->adjacency);
    }

    public function nodeCount(): int
    {
        return count($this->adjacency);
    }

    /** Sum of all edge weights (each ordinary edge counted once, each self-loop counted once) - Louvain's "m". */
    public function totalWeight(): float
    {
        $ordinary = 0.0;
        foreach ($this->adjacency as $neighbors) {
            $ordinary += array_sum($neighbors);
        }

        // $ordinary double-counts every ordinary edge (stored both a->b and b->a), self-loops are
        // stored once already - so only the ordinary half needs dividing.
        return $ordinary / 2.0 + array_sum($this->selfLoop);
    }
}
