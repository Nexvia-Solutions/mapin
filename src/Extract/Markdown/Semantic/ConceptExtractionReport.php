<?php

declare(strict_types=1);

namespace Mapin\Extract\Markdown\Semantic;

final class ConceptExtractionReport
{
    /** @param string[] $warnings */
    public function __construct(
        public readonly int $sectionsProcessed,
        public readonly int $sectionsSkipped,
        public readonly int $conceptsFound,
        public readonly int $relationsFound,
        public readonly array $warnings = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'sections_processed' => $this->sectionsProcessed,
            'sections_skipped' => $this->sectionsSkipped,
            'concepts_found' => $this->conceptsFound,
            'relations_found' => $this->relationsFound,
            'warnings' => $this->warnings,
        ];
    }
}
