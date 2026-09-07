<?php

declare(strict_types=1);

namespace Mapin\Query;

/**
 * The data half of a tool's answer (SPEC.md section 6): whether the thing asked about exists, and
 * if so, what was found. Building the full response envelope - the "query"/"graph" framing around
 * this - is Query::envelope()'s job, not this class's, so the same QueryResult works whether it is
 * about to be rendered as a CLI table or serialized into an MCP tool response.
 */
final class QueryResult
{
    /** @param array<int, array{key: string, score: float}> $suggestions */
    private function __construct(
        public readonly bool $found,
        public readonly mixed $result,
        public readonly ?float $confidence,
        public readonly array $suggestions,
    ) {}

    public static function found(mixed $result, ?float $confidence = null): self
    {
        return new self(true, $result, $confidence, []);
    }

    /** @param array<int, array{key: string, score: float}> $suggestions */
    public static function notFound(array $suggestions = []): self
    {
        return new self(false, null, null, $suggestions);
    }
}
