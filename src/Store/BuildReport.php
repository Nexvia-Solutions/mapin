<?php

declare(strict_types=1);

namespace Mapin\Store;

final class BuildReport
{
    /** @param string[] $warnings */
    public function __construct(
        public readonly string $mode,
        public readonly int $filesSeen,
        public readonly int $filesChanged,
        public readonly int $filesAffected,
        public readonly int $filesDeleted,
        public readonly int $nodes,
        public readonly int $edges,
        public readonly int $unresolved,
        public readonly float $durationSeconds,
        public readonly array $warnings,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'files_seen' => $this->filesSeen,
            'files_changed' => $this->filesChanged,
            'files_affected' => $this->filesAffected,
            'files_deleted' => $this->filesDeleted,
            'nodes' => $this->nodes,
            'edges' => $this->edges,
            'unresolved' => $this->unresolved,
            'duration_seconds' => round($this->durationSeconds, 3),
            'warnings' => $this->warnings,
        ];
    }
}
