<?php

declare(strict_types=1);

namespace Mapin\Extract\Markdown\Semantic;

final class ConceptExtractionResult
{
    /**
     * @param  string[]  $concepts  concept names as the model returned them, not yet slugified
     * @param  array<int, array{0: string, 1: string}>  $relations  pairs of concept names the model says relate to each other
     */
    public function __construct(
        public readonly array $concepts,
        public readonly array $relations,
    ) {}

    public static function empty(): self
    {
        return new self([], []);
    }
}
