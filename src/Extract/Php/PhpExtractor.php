<?php

declare(strict_types=1);

namespace Mapin\Extract\Php;

use Mapin\Extract\ClassMeta;
use Mapin\Extract\Contracts\ExtractionContext;
use Mapin\Extract\Contracts\Extractor;
use Mapin\Extract\Contracts\Fragment;
use Mapin\Extract\Contracts\SourceFile;
use Mapin\Extract\Php\Visitors\DeclarationVisitor;
use Mapin\Graph\Edge;
use Mapin\Graph\EdgeType;
use Mapin\Graph\Key;
use Mapin\Graph\Node as GraphNode;
use Mapin\Graph\NodeType;
use PhpParser\Error;
use PhpParser\Node as AstNode;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * First pass of PHP extraction: what a file declares. Structural edges (declares, extends,
 * implements, uses_trait) are known from the AST alone and are emitted here; edges that need the
 * complete SymbolIndex (calls, instantiates, resolves) are produced by a later pass over the same
 * parsed AST - see Mapin\Resolve\Resolver::resolveReferences(), which reads $this->parsedStmts.
 */
final class PhpExtractor implements Extractor
{
    private readonly Parser $parser;

    /** @var array<string, AstNode[]> relativePath => parsed, name-resolved statements, held only for this build run */
    private array $parsedStmts = [];

    /** @var array<string, ClassMeta[]> relativePath => classes it declares, held only for this build run */
    private array $classMetas = [];

    /** @var array<string, array<string, ?string>> relativePath => function name => return type */
    private array $functionMetas = [];

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    public function supports(SourceFile $file): bool
    {
        return $file->lang === 'php';
    }

    public function requiresBoot(): bool
    {
        return false;
    }

    public function extract(SourceFile $file, ExtractionContext $ctx): Fragment
    {
        try {
            $stmts = $this->parser->parse($file->contents());
        } catch (Error $e) {
            return new Fragment(warnings: ["{$file->relativePath}: {$e->getMessage()}"]);
        }
        if ($stmts === null) {
            return new Fragment;
        }

        $declarations = new DeclarationVisitor($file->relativePath, $file->isProject);
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $traverser->addVisitor($declarations);
        $stmts = $traverser->traverse($stmts);

        $this->parsedStmts[$file->relativePath] = $stmts;
        $this->classMetas[$file->relativePath] = $declarations->classes;
        $this->functionMetas[$file->relativePath] = $declarations->functions;

        return $this->buildFragment($file, $declarations);
    }

    /** @return AstNode[]|null */
    public function parsedStatementsFor(string $relativePath): ?array
    {
        return $this->parsedStmts[$relativePath] ?? null;
    }

    /** @return ClassMeta[] */
    public function classMetasFor(string $relativePath): array
    {
        return $this->classMetas[$relativePath] ?? [];
    }

    /** @return array<string, ?string> */
    public function functionMetasFor(string $relativePath): array
    {
        return $this->functionMetas[$relativePath] ?? [];
    }

    public function forget(string $relativePath): void
    {
        unset($this->parsedStmts[$relativePath], $this->classMetas[$relativePath], $this->functionMetas[$relativePath]);
    }

    private function buildFragment(SourceFile $file, DeclarationVisitor $declarations): Fragment
    {
        $fileKey = Key::file($file->relativePath);
        $nodes = [new GraphNode(NodeType::File, $file->relativePath, $fileKey, $file->relativePath)];
        $edges = [];

        foreach ($declarations->classes as $class) {
            [$classNode, $classEdges, $methodNodes] = $this->buildClass($file, $fileKey, $class);
            $nodes[] = $classNode;
            $edges = [...$edges, ...$classEdges];
            $nodes = [...$nodes, ...$methodNodes];
        }

        foreach ($declarations->functions as $name => $return) {
            $key = Key::function_($name);
            $nodes[] = new GraphNode(NodeType::Function_, $name, $key, $file->relativePath, meta: ['return' => $return]);
            $edges[] = new Edge(EdgeType::Declares, $fileKey, $key);
        }

        return new Fragment($nodes, $edges);
    }

    /** @return array{0: GraphNode, 1: Edge[], 2: GraphNode[]} */
    private function buildClass(SourceFile $file, string $fileKey, ClassMeta $class): array
    {
        $classKey = Key::classLike($class->fqcn);
        $classNode = new GraphNode(NodeType::ClassLike, $class->fqcn, $classKey, $file->relativePath, $class->line, $class->toMeta());
        $edges = [new Edge(EdgeType::Declares, $fileKey, $classKey)];

        if ($class->parent !== null) {
            $edges[] = new Edge(EdgeType::Extends, $classKey, Key::classLike($class->parent));
        }
        foreach ($class->interfaces as $interface) {
            $edges[] = new Edge($class->kind === 'interface' ? EdgeType::Extends : EdgeType::Implements, $classKey, Key::classLike($interface));
        }
        foreach ($class->traits as $trait) {
            $edges[] = new Edge(EdgeType::UsesTrait, $classKey, Key::classLike($trait));
        }

        $methodNodes = [];
        if ($class->project) {
            foreach ($class->methods as $info) {
                $name = $info['name'];
                $methodKey = Key::method($class->fqcn, $name);
                $methodNodes[] = new GraphNode(NodeType::Method, $name, $methodKey, $file->relativePath, meta: $info);
                $edges[] = new Edge(EdgeType::Declares, $classKey, $methodKey);

                // The method body already told DeclarationVisitor which model an Eloquent relation
                // targets (SPEC.md 4.1 rule 11) - emitting the edge here needs no resolution pass.
                if ($info['return'] !== null && str_starts_with($info['return'], 'relation<')) {
                    [, $relationName, $target] = explode(',', substr($info['return'], 9, -1), 3);
                    $edges[] = new Edge(
                        EdgeType::Relates,
                        $classKey,
                        Key::classLike($target),
                        $file->relativePath,
                        meta: ['relation' => $relationName, 'method' => $name],
                    );
                }
            }
        }

        foreach ($class->listenMap as $event => $listeners) {
            foreach ($listeners as $listener) {
                $edges[] = new Edge(EdgeType::Listens, Key::classLike($listener), Key::classLike($event), $file->relativePath);
            }
        }

        return [$classNode, $edges, $methodNodes];
    }
}
