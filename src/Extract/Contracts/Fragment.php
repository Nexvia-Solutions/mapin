<?php

declare(strict_types=1);

namespace Mapin\Extract\Contracts;

use Mapin\Graph\Edge;
use Mapin\Graph\Node;

/**
 * What a single Extractor call returns for one file: the nodes it declares and the edges that are
 * known without any cross-file information (declares, extends, implements, uses_trait). Edges that
 * need the complete SymbolIndex (calls, instantiates, renders, ...) are not produced here; see
 * SPEC.md section 2 for why resolution is a separate pipeline stage from extraction.
 */
final class Fragment
{
    /**
     * @param  Node[]  $nodes
     * @param  Edge[]  $edges
     * @param  string[]  $warnings
     */
    public function __construct(
        public readonly array $nodes = [],
        public readonly array $edges = [],
        public readonly array $warnings = [],
    ) {}
}
