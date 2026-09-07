<?php

declare(strict_types=1);

namespace Mapin\Resolve;

use Mapin\Extract\Contracts\UnresolvedRow;
use Mapin\Extract\SymbolIndex;
use Mapin\Graph\Edge;
use Mapin\Graph\Node;
use PhpParser\Node as AstNode;
use PhpParser\NodeTraverser;

/**
 * Runs the second extraction pass (ReferenceVisitor) over one file's already-parsed, already
 * name-resolved AST, using the complete SymbolIndex built from every class in the build.
 */
final class Resolver
{
    /** @param array<string,string> $routeKeysByName route name => "route:METHOD /uri" */
    public function __construct(
        private readonly SymbolIndex $index,
        private readonly array $routeKeysByName = [],
    ) {}

    /**
     * @param  AstNode[]  $stmts
     * @return array{edges: Edge[], unresolved: UnresolvedRow[], extraNodes: Node[]}
     */
    public function resolveFile(string $relativePath, array $stmts): array
    {
        $visitor = new ReferenceVisitor($this->index, $relativePath, $this->routeKeysByName);
        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return [
            'edges' => $visitor->edges,
            'unresolved' => $visitor->unresolved,
            'extraNodes' => array_values($visitor->extraNodes),
        ];
    }
}
