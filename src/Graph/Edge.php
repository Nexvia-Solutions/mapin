<?php

declare(strict_types=1);

namespace Mapin\Graph;

final class Edge
{
    /**
     * @param  array<string,mixed>  $meta
     * @param  string[]  $dependsOn  lowercase symbol FQCNs (or "function:name") this edge's
     *                               resolution consulted - see SymbolIndex::startTracking() and SqliteStore's symbol_deps
     *                               table. Empty for edges that never needed to look anything up (e.g. a plain `new Foo()`).
     */
    public function __construct(
        public readonly EdgeType $type,
        public readonly string $fromKey,
        public readonly string $toKey,
        public readonly ?string $file = null,
        public readonly ?int $line = null,
        public readonly ?Resolution $resolution = null,
        public readonly ?float $confidence = null,
        public readonly array $meta = [],
        public readonly array $dependsOn = [],
    ) {}
}
