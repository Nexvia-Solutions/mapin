<?php

declare(strict_types=1);

namespace Mapin\Analysis;

/**
 * SPEC.md section 8: "degree and betweenness-lite (bridge count between communities)". Degree is
 * always available from the projection alone; the bridge count needs community membership, which
 * only exists once `mapin:communities` has run - `bridges` is `null` rather than computed as zero
 * until then, so a caller can tell "not computed yet" apart from "genuinely bridges nothing".
 */
final class Hubs
{
    /**
     * @param  array<int,string>  $communityByNodeId  node id => community key (SqliteStore::communityMembership()); empty when communities have never been computed
     * @return array<int, array{degree: float, bridges: ?int}>
     */
    public static function compute(WeightedGraph $graph, array $communityByNodeId = []): array
    {
        $result = [];
        foreach ($graph->nodeIds() as $id) {
            $bridges = null;
            if ($communityByNodeId !== []) {
                $own = $communityByNodeId[$id] ?? null;
                $neighborCommunities = [];
                foreach ($graph->neighbors($id) as $j => $weight) {
                    $c = $communityByNodeId[$j] ?? null;
                    if ($c !== null && $c !== $own) {
                        $neighborCommunities[$c] = true;
                    }
                }
                $bridges = count($neighborCommunities);
            }
            $result[$id] = ['degree' => $graph->degree($id), 'bridges' => $bridges];
        }

        return $result;
    }
}
