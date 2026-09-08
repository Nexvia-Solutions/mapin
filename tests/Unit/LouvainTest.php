<?php

declare(strict_types=1);

use Mapin\Analysis\Louvain;
use Mapin\Analysis\WeightedGraph;

/**
 * Pure algorithm tests, no store/app boot at all (same reasoning as ResolverTest.php) - hand-built
 * graphs with a known correct answer, not the real codebase graph, so a wrong community assignment
 * is unambiguous rather than a matter of interpretation.
 */
it('splits two triangles joined by a single bridge edge into two communities', function () {
    $graph = new WeightedGraph;
    $graph->addEdge(1, 2);
    $graph->addEdge(2, 3);
    $graph->addEdge(1, 3);
    $graph->addEdge(4, 5);
    $graph->addEdge(5, 6);
    $graph->addEdge(4, 6);
    $graph->addEdge(3, 4);

    $result = (new Louvain(1.0))->run($graph);

    expect($result[1])->toBe($result[2])->toBe($result[3]);
    expect($result[4])->toBe($result[5])->toBe($result[6]);
    expect($result[1])->not->toBe($result[4]);
});

it('produces the same result on the same graph across repeated runs', function () {
    $graph = new WeightedGraph;
    $graph->addEdge(1, 2);
    $graph->addEdge(2, 3);
    $graph->addEdge(1, 3);
    $graph->addEdge(4, 5);
    $graph->addEdge(5, 6);
    $graph->addEdge(4, 6);
    $graph->addEdge(3, 4);

    $louvain = new Louvain(1.0);
    expect($louvain->run($graph))->toBe($louvain->run($graph));
});

it('assigns each disconnected component its own community', function () {
    $graph = new WeightedGraph;
    $graph->addEdge(10, 11);
    $graph->addEdge(20, 21);
    $graph->addEdge(30, 31);

    $result = (new Louvain(1.0))->run($graph);

    expect($result[10])->toBe($result[11]);
    expect($result[20])->toBe($result[21]);
    expect($result[30])->toBe($result[31]);
    expect(count(array_unique($result)))->toBe(3);
});

it('gives an isolated node its own community', function () {
    $graph = new WeightedGraph;
    $graph->addNode(99);

    expect((new Louvain(1.0))->run($graph))->toBe([99 => 99]);
});

it('relabels every community by its own lowest member id, not an aggregation artifact', function () {
    $graph = new WeightedGraph;
    $graph->addEdge(5, 9);
    $graph->addEdge(9, 12);
    $graph->addEdge(5, 12);

    $result = (new Louvain(1.0))->run($graph);

    expect($result)->toBe([5 => 5, 9 => 5, 12 => 5]);
});

it('weighted graph counts a self-loop twice toward its own node degree', function () {
    $graph = new WeightedGraph;
    $graph->addEdge(1, 2, 3.0);
    $graph->addEdge(1, 1, 2.0);

    expect($graph->degree(1))->toBe(3.0 + 4.0);
    expect($graph->totalWeight())->toBe(3.0 + 2.0);
});

it('weighted graph sums weight on repeated edges between the same pair instead of overwriting', function () {
    $graph = new WeightedGraph;
    $graph->addEdge(1, 2, 1.0);
    $graph->addEdge(1, 2, 4.0);

    expect($graph->neighbors(1))->toBe([2 => 5.0]);
});
