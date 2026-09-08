<?php

declare(strict_types=1);

namespace Mapin\Analysis;

final class CommunityReport
{
    public function __construct(
        public readonly int $communityCount,
        public readonly int $nodeCount,
        public readonly int $hubCount,
    ) {}

    /** @return array<string,int> */
    public function toArray(): array
    {
        return [
            'community_count' => $this->communityCount,
            'node_count' => $this->nodeCount,
            'hub_count' => $this->hubCount,
        ];
    }
}
