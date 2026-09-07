<?php

declare(strict_types=1);

namespace Mapin\Extract\Php\Visitors;

use Mapin\Extract\ClassMeta;
use Mapin\Extract\Php\TypeExpr;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;

/**
 * First pass over one file's AST: what it declares. Ported from the phase 0 spike
 * (mapin/spike/resolve.php), which measured every rule below against a real Laravel application
 * before it was written here - see SPEC.md section 4.1 and 4.2.
 */
final class DeclarationVisitor extends NodeVisitorAbstract
{
    private const RELATION_KINDS = [
        'hasone' => 'one', 'belongsto' => 'one', 'morphone' => 'one', 'hasonethrough' => 'one',
        'hasmany' => 'many', 'belongstomany' => 'many', 'morphmany' => 'many',
        'morphtomany' => 'many', 'hasmanythrough' => 'many',
    ];

    private const DATE_CASTS = ['date', 'datetime', 'immutable_date', 'immutable_datetime', 'custom_datetime', 'immutable_custom_datetime'];

    /** @var ClassMeta[] */
    public array $classes = [];

    /** @var array<string, ?string> lowercase function name => return type */
    public array $functions = [];

    /** @var ClassMeta[] */
    private array $stack = [];

    private ?string $namespace = null;

    /** @var array<string,string> alias => FQCN */
    private array $uses = [];

    public function __construct(private readonly string $relativePath, private readonly bool $isProject) {}

    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = $node->name?->toString();
            $this->uses = [];
        }
        if ($node instanceof Node\Stmt\Use_ && $node->type === Node\Stmt\Use_::TYPE_NORMAL) {
            foreach ($node->uses as $use) {
                $this->uses[$use->getAlias()->toString()] = $use->name->toString();
            }
        }
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->enterClassLike($node);
        }
        if ($node instanceof Node\Stmt\Property && $this->stack !== []) {
            $this->visitProperty($node);
        }
        if ($node instanceof Node\Stmt\ClassMethod && $this->stack !== []) {
            $this->visitMethod($node);
        }
        if ($node instanceof Node\Stmt\Function_) {
            $this->visitFunction($node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->stack);
        }

        return null;
    }

    private function enterClassLike(Node\Stmt\ClassLike $node): void
    {
        $fqcn = isset($node->namespacedName) ? $node->namespacedName->toString() : $this->anonymousName($node);
        $kind = match (true) {
            $node instanceof Node\Stmt\Interface_ => 'interface',
            $node instanceof Node\Stmt\Trait_ => 'trait',
            $node instanceof Node\Stmt\Enum_ => 'enum',
            default => 'class',
        };
        $parent = ($node instanceof Node\Stmt\Class_ && $node->extends !== null) ? $node->extends->toString() : null;
        $class = new ClassMeta($fqcn, $kind, $this->relativePath, $node->getStartLine(), $parent, $this->isProject);

        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Enum_) {
            foreach ($node->implements as $interface) {
                $class->interfaces[] = $interface->toString();
            }
        } elseif ($node instanceof Node\Stmt\Interface_) {
            foreach ($node->extends as $interface) {
                $class->interfaces[] = $interface->toString();
            }
        }
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $class->traits[] = $trait->toString();
                }
            }
        }

        $doc = $node->getDocComment()?->getText();
        if ($doc !== null && preg_match_all('/@method\s+static\s+([^\s]+)\s+(\w+)\s*\(/', $doc, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $return = TypeExpr::fromDoc($match[1], $this->namespace, $this->uses);
                if ($return !== null) {
                    $class->facadeMethods[strtolower($match[2])] = $return;
                }
            }
        }

        $this->classes[] = $class;
        $this->stack[] = $class;
    }

    private function anonymousName(Node\Stmt\ClassLike $node): string
    {
        return 'anonymous@'.$this->relativePath.':'.$node->getStartLine();
    }

    private function visitProperty(Node\Stmt\Property $node): void
    {
        $class = end($this->stack);
        $type = TypeExpr::fromNode($node->type);
        $source = $type !== null ? 'typed_property' : 'untyped';
        if ($type === null) {
            $doc = $node->getDocComment()?->getText();
            if ($doc !== null && preg_match('/@var\s+([^\s]+)/', $doc, $match)) {
                $type = TypeExpr::fromDoc($match[1], $this->namespace, $this->uses);
                $source = $type !== null ? 'docblock' : 'untyped';
            }
        }

        foreach ($node->props as $prop) {
            $name = $prop->name->toString();
            if (! isset($class->props[$name]) || $class->props[$name]['type'] === null) {
                $class->props[$name] = ['type' => $type, 'source' => $source];
            }
            if (($name === 'casts' || $name === 'dates') && $prop->default instanceof Node\Expr\Array_) {
                $this->readDateCasts($class, $name, $prop->default);
            }
            if ($name === 'table' && $prop->default instanceof Node\Scalar\String_) {
                $class->tableOverride = $prop->default->value;
            }
            if ($name === 'listen' && $prop->default instanceof Node\Expr\Array_) {
                $this->readListenMap($class, $prop->default);
            }
        }
    }

    /**
     * protected $listen = [Event::class => [Listener::class, ...], ...] - EventServiceProvider's
     * declarative event-to-listener map (SPEC.md 3.2 "listens", the $listen source).
     */
    private function readListenMap(ClassMeta $class, Node\Expr\Array_ $array): void
    {
        foreach ($array->items as $item) {
            if ($item === null || $item->key === null) { // @phpstan-ignore identical.alwaysFalse
                continue;
            }
            $event = $this->classConstName($item->key);
            if ($event === null || ! $item->value instanceof Node\Expr\Array_) {
                continue;
            }
            foreach ($item->value->items as $listenerItem) {
                $listener = $listenerItem !== null ? $this->classConstName($listenerItem->value) : null; // @phpstan-ignore ternary.elseUnreachable
                if ($listener !== null) {
                    $class->listenMap[$event][] = $listener;
                }
            }
        }
    }

    private function classConstName(Node\Expr $expr): ?string
    {
        return ($expr instanceof Node\Expr\ClassConstFetch && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier && strtolower($expr->name->toString()) === 'class')
            ? $this->resolveName($expr->class)
            : null;
    }

    /**
     * NameResolver, registered ahead of this visitor in the same NodeTraverser, only resolves a
     * Name node to its FQCN when the traverser's own recursion reaches it. Every caller of this
     * method reads a Name out of order - via NodeFinder, or straight off a property/array literal
     * inside enterNode() - before that recursion gets there, so the node is still exactly as
     * written in source (e.g. "Book" for a `use`-imported class). This applies the same
     * uses/namespace resolution NameResolver would have, using the import table this visitor
     * already tracks for TypeExpr::fromDoc().
     */
    private function resolveName(Node\Name $name): string
    {
        if ($name instanceof Node\Name\FullyQualified) {
            return $name->toString();
        }
        $raw = $name->toString();
        $first = explode('\\', $raw)[0];
        if (isset($this->uses[$first])) {
            return $this->uses[$first].substr($raw, strlen($first));
        }

        return $this->namespace !== null ? $this->namespace.'\\'.$raw : $raw;
    }

    private function readDateCasts(ClassMeta $class, string $propertyName, Node\Expr\Array_ $array): void
    {
        foreach ($array->items as $item) {
            // ArrayItem entries are only null for a skipped slot in list() destructuring, which an
            // array literal default value never is - php-parser's own type does not rule it out.
            if ($item === null) { // @phpstan-ignore identical.alwaysFalse
                continue;
            }
            if ($propertyName === 'dates' && $item->value instanceof Node\Scalar\String_) {
                $class->props[$item->value->value] = ['type' => 'Carbon\Carbon', 'source' => 'cast'];

                continue;
            }
            if ($propertyName === 'casts' && $item->key instanceof Node\Scalar\String_ && $item->value instanceof Node\Scalar\String_) {
                $castType = explode(':', $item->value->value, 2)[0];
                if (in_array($castType, self::DATE_CASTS, true)) {
                    $isImmutable = str_starts_with($castType, 'immutable_');
                    $class->props[$item->key->value] = ['type' => $isImmutable ? 'Carbon\CarbonImmutable' : 'Carbon\Carbon', 'source' => 'cast'];
                }
            }
        }
    }

    private function visitMethod(Node\Stmt\ClassMethod $node): void
    {
        $class = end($this->stack);
        $params = [];
        foreach ($node->params as $param) {
            $paramName = ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) ? $param->var->name : null;
            $paramType = TypeExpr::fromNode($param->type);
            if ($paramType === null && $paramName !== null) {
                $doc = $node->getDocComment()?->getText();
                if ($doc !== null && preg_match('/@param\s+([^\s]+)\s+\$'.preg_quote($paramName, '/').'\b/', $doc, $match)) {
                    $paramType = TypeExpr::fromDoc($match[1], $this->namespace, $this->uses);
                }
            }
            if ($paramName !== null) {
                $params[$paramName] = $paramType;
                if ($param->flags !== 0) {
                    $class->props[$paramName] = ['type' => $paramType, 'source' => $paramType !== null ? 'promoted_property' : 'untyped'];
                }
            }
        }

        $return = TypeExpr::fromNode($node->returnType);
        if ($return === null) {
            $doc = $node->getDocComment()?->getText();
            if ($doc !== null && ($expr = TypeExpr::extractReturnExpr($doc)) !== null) {
                $return = TypeExpr::fromDoc($expr, $this->namespace, $this->uses);
            }
        }

        if ($node->stmts !== null) {
            $finder = new NodeFinder;
            foreach ($finder->findInstanceOf($node->stmts, Node\Stmt\Return_::class) as $return_) {
                if ($return_->expr === null) {
                    continue;
                }
                $relation = $this->findRelationCall($return_->expr);
                if ($relation !== null) {
                    $return = 'relation<'.$relation[0].','.$relation[1].','.$relation[2].'>';
                    break;
                }
            }
        }

        $class->addMethod($node->name->toString(), [
            'return' => $return,
            'static' => $node->isStatic(),
            'params' => $params,
        ]);

        if (strtolower($node->name->toString()) === '__construct' && $node->stmts !== null) {
            $this->scanConstructorAssignments($class, $node->stmts, $params);
        }
    }

    /**
     * @param  Node\Stmt[]  $stmts
     * @param  array<string,?string>  $params
     */
    private function scanConstructorAssignments(ClassMeta $class, array $stmts, array $params): void
    {
        $finder = new NodeFinder;
        foreach ($finder->findInstanceOf($stmts, Node\Expr\Assign::class) as $assign) {
            if (! ($assign->var instanceof Node\Expr\PropertyFetch
                && $assign->var->var instanceof Node\Expr\Variable && $assign->var->var->name === 'this'
                && $assign->var->name instanceof Node\Identifier)) {
                continue;
            }
            $propName = $assign->var->name->toString();
            $rhs = $assign->expr;
            $type = null;
            if ($rhs instanceof Node\Expr\Variable && is_string($rhs->name) && ($params[$rhs->name] ?? null) !== null) {
                $type = $params[$rhs->name];
            } elseif ($rhs instanceof Node\Expr\New_ && $rhs->class instanceof Node\Name) {
                $type = $this->resolveName($rhs->class);
            } elseif ($rhs instanceof Node\Expr\FuncCall && $rhs->name instanceof Node\Name
                && in_array(strtolower($rhs->name->toString()), ['app', 'resolve'], true)
                && isset($rhs->args[0]) && $rhs->args[0] instanceof Node\Arg
                && $rhs->args[0]->value instanceof Node\Expr\ClassConstFetch
                && $rhs->args[0]->value->class instanceof Node\Name) {
                $type = $this->resolveName($rhs->args[0]->value->class);
            }
            if ($type !== null && (! isset($class->props[$propName]) || $class->props[$propName]['type'] === null)) {
                $class->props[$propName] = ['type' => $type, 'source' => 'constructor_assignment'];
            }
        }
    }

    /**
     * Descends a chain such as $this->hasMany(Item::class)->withDefault() looking for the
     * innermost call to a relation method on $this with a Foo::class first argument.
     *
     * @return array{0:string,1:string}|null
     */
    /** @return array{0:string,1:string,2:string}|null [one|many, declared relation method name, target FQCN] */
    private function findRelationCall(Node\Expr $expr): ?array
    {
        while ($expr instanceof Node\Expr\MethodCall) {
            if ($expr->var instanceof Node\Expr\Variable && $expr->var->name === 'this'
                && $expr->name instanceof Node\Identifier
                && isset(self::RELATION_KINDS[strtolower($expr->name->toString())])
                && isset($expr->args[0]) && $expr->args[0] instanceof Node\Arg
                && $expr->args[0]->value instanceof Node\Expr\ClassConstFetch
                && $expr->args[0]->value->class instanceof Node\Name) {
                return [
                    self::RELATION_KINDS[strtolower($expr->name->toString())],
                    $expr->name->toString(),
                    $this->resolveName($expr->args[0]->value->class),
                ];
            }
            $expr = $expr->var;
        }

        return null;
    }

    private function visitFunction(Node\Stmt\Function_ $node): void
    {
        $name = isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
        $return = TypeExpr::fromNode($node->returnType);
        if ($return === null) {
            $doc = $node->getDocComment()?->getText();
            if ($doc !== null && ($expr = TypeExpr::extractReturnExpr($doc)) !== null) {
                $return = TypeExpr::fromDoc($expr, $this->namespace, $this->uses);
            }
        }
        $this->functions[strtolower($name)] = $return;
    }
}
