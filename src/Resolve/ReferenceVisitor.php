<?php

declare(strict_types=1);

namespace Mapin\Resolve;

use Illuminate\Support\Str;
use Mapin\Extract\Contracts\UnresolvedRow;
use Mapin\Extract\Php\TypeExpr;
use Mapin\Extract\SymbolIndex;
use Mapin\Graph\Edge;
use Mapin\Graph\EdgeType;
use Mapin\Graph\Key;
use Mapin\Graph\Node as GraphNode;
use Mapin\Graph\NodeType;
use Mapin\Graph\Resolution;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Second pass, run once the SymbolIndex holds every class in the build (fresh or hydrated - see
 * SymbolIndex). Walks one file's already-parsed AST, tracks the type of every local variable in
 * scope as it goes, and resolves each call/new/app() site against the index. Every rule here was
 * measured against a real Laravel application before being written; see SPEC.md section 4.1 and
 * 4.2, and the phase 0 spike this was ported from (mapin/spike/resolve.php).
 */
final class ReferenceVisitor extends NodeVisitorAbstract
{
    private const RELATION_MUTATORS = [
        'save', 'savemany', 'attach', 'detach', 'sync', 'syncwithoutdetaching',
        'togglewithoutdetaching', 'toggle', 'associate', 'dissociate',
        'updateexistingpivot', 'createmany', 'createmanyquietly',
    ];

    private const THROWABLE_SCALARS = ['getmessage', 'getcode', 'getfile', 'getline', 'gettraceasstring', '__tostring', 'gettrace'];

    /** Laravel's own default facade aliases, registered via config('app.aliases') at boot. */
    private const DEFAULT_FACADE_ALIASES = [
        'app' => 'Illuminate\Foundation\Application', 'artisan' => 'Illuminate\Support\Facades\Artisan',
        'auth' => 'Illuminate\Support\Facades\Auth', 'blade' => 'Illuminate\Support\Facades\Blade',
        'broadcast' => 'Illuminate\Support\Facades\Broadcast', 'bus' => 'Illuminate\Support\Facades\Bus',
        'cache' => 'Illuminate\Support\Facades\Cache', 'config' => 'Illuminate\Support\Facades\Config',
        'cookie' => 'Illuminate\Support\Facades\Cookie', 'crypt' => 'Illuminate\Support\Facades\Crypt',
        'date' => 'Illuminate\Support\Facades\Date', 'db' => 'Illuminate\Support\Facades\DB',
        'event' => 'Illuminate\Support\Facades\Event', 'file' => 'Illuminate\Support\Facades\File',
        'gate' => 'Illuminate\Support\Facades\Gate', 'hash' => 'Illuminate\Support\Facades\Hash',
        'http' => 'Illuminate\Support\Facades\Http', 'lang' => 'Illuminate\Support\Facades\Lang',
        'log' => 'Illuminate\Support\Facades\Log', 'mail' => 'Illuminate\Support\Facades\Mail',
        'notification' => 'Illuminate\Support\Facades\Notification', 'password' => 'Illuminate\Support\Facades\Password',
        'process' => 'Illuminate\Support\Facades\Process', 'queue' => 'Illuminate\Support\Facades\Queue',
        'ratelimiter' => 'Illuminate\Support\Facades\RateLimiter', 'redirect' => 'Illuminate\Support\Facades\Redirect',
        'redis' => 'Illuminate\Support\Facades\Redis', 'request' => 'Illuminate\Support\Facades\Request',
        'response' => 'Illuminate\Support\Facades\Response', 'route' => 'Illuminate\Support\Facades\Route',
        'schema' => 'Illuminate\Support\Facades\Schema', 'schedule' => 'Illuminate\Support\Facades\Schedule',
        'session' => 'Illuminate\Support\Facades\Session',
        'storage' => 'Illuminate\Support\Facades\Storage', 'str' => 'Illuminate\Support\Str',
        'arr' => 'Illuminate\Support\Arr', 'url' => 'Illuminate\Support\Facades\URL',
        'validator' => 'Illuminate\Support\Facades\Validator', 'view' => 'Illuminate\Support\Facades\View',
        'vite' => 'Illuminate\Support\Facades\Vite',
    ];

    /** A documented quirk: a manager implements a factory contract but forwards undeclared calls to another contract. */
    private const MANAGER_FORWARDS = [
        'illuminate\contracts\auth\factory' => 'Illuminate\Contracts\Auth\Guard',
    ];

    /** @var Edge[] */
    public array $edges = [];

    /** @var UnresolvedRow[] */
    public array $unresolved = [];

    /** @var GraphNode[] keyed by key, for classes referenced but not found in the index */
    public array $extraNodes = [];

    /** @var string[] */
    private array $classStack = [];

    private ?string $method = null;

    /** @var array<string, array{0:?string,1:string,2:float}> */
    private array $scope = [];

    private ?string $namespace = null;

    /** @var array<string,string> */
    private array $uses = [];

    private readonly PrettyPrinter $printer;

    /** @param array<string,string> $routeKeysByName route name => "route:METHOD /uri", from RouteExtractor */
    public function __construct(
        private readonly SymbolIndex $index,
        private readonly string $file,
        private readonly array $routeKeysByName = [],
    ) {
        $this->printer = new PrettyPrinter;
    }

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
            $fqcn = isset($node->namespacedName)
                ? $node->namespacedName->toString()
                : 'anonymous@'.$this->file.':'.$node->getStartLine();
            $this->classStack[] = $fqcn;
            $this->emitTableMapping($fqcn, $node->getStartLine());
        }
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->method = $node->name->toString();
            $this->scope = [];
            $this->bindParams($node->params);
        }
        if (($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction)) {
            $this->bindParams($node->params);
        }
        if ($node instanceof Node\Stmt\Foreach_ && $node->valueVar instanceof Node\Expr\Variable && is_string($node->valueVar->name)) {
            [$type] = $this->typeOf($node->expr);
            if ($type !== null && str_starts_with($type, 'builder<')) {
                $this->scope[$node->valueVar->name] = [substr($type, 8, -1), 'eloquent', 0.7];
            } elseif ($type !== null && str_starts_with($type, 'collection<')) {
                $this->scope[$node->valueVar->name] = [substr($type, 11, -1), 'eloquent', 0.7];
            }
        }
        if ($node instanceof Node\Stmt\Catch_ && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
            foreach ($node->types as $type) {
                $resolved = $this->resolveClassName($type->toString());
                if ($resolved !== null) {
                    $this->scope[$node->var->name] = [$resolved, 'catch', 1.0];
                    break;
                }
            }
        }
        if ($node instanceof Node\Stmt\Expression) {
            $doc = $node->getDocComment()?->getText();
            if ($doc !== null && preg_match('/@var\s+([^\s]+)\s+\$(\w+)/', $doc, $match)) {
                $type = TypeExpr::fromDoc($match[1], $this->namespace, $this->uses);
                if ($type !== null) {
                    $this->scope[$match[2]] = [$this->selfish($type), 'docblock', 0.7];
                }
            }
        }
        if ($node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
            [$type, $resolution, $confidence] = $this->typeOf($node->expr);
            $this->scope[$node->var->name] = $type !== null
                ? [$type, $resolution === 'new' ? 'local_new' : 'local_assign', min($confidence, $resolution === 'new' ? 1.0 : 0.8)]
                : [null, 'unknown_assign', 0.0];
        }

        if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
            $this->visitMemberCall($node);
        } elseif ($node instanceof Node\Expr\StaticCall) {
            $this->visitStaticCall($node);
            $this->detectTableSideEffect($node);
            $this->detectDispatch($node);
            $this->detectObserver($node);
            $this->detectEventListen($node);
        } elseif ($node instanceof Node\Expr\New_) {
            $this->visitNew($node);
        } elseif ($node instanceof Node\Expr\FuncCall && $this->isContainerResolve($node)) {
            $this->visitContainerResolve($node);
        }
        if ($node instanceof Node\Expr\FuncCall) {
            $this->detectRenderOrRouteLink($node);
            $this->detectEventDispatch($node);
        }
        if (($node instanceof Node\Expr\StaticCall) && $node->name instanceof Node\Identifier
            && strtolower($node->name->toString()) === 'make') {
            $this->detectViewMake($node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->classStack);
        }
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->method = null;
            $this->scope = [];
        }

        return null;
    }

    /** @param Node\Param[] $params */
    private function bindParams(array $params): void
    {
        [$owner] = [$this->currentClass()];
        $methodInfo = ($this->method !== null && $owner !== null)
            ? $this->index->get($owner)?->methods[strtolower($this->method)] ?? null
            : null;
        foreach ($params as $param) {
            if (! ($param->var instanceof Node\Expr\Variable && is_string($param->var->name))) {
                continue;
            }
            $type = TypeExpr::fromNode($param->type) ?? ($methodInfo['params'][$param->var->name] ?? null);
            $this->scope[$param->var->name] = $type !== null
                ? [$this->selfish($type), 'param', 1.0]
                : [null, 'untyped_param', 0.0];
        }
    }

    private function currentClass(): ?string
    {
        return $this->classStack === [] ? null : end($this->classStack);
    }

    private function selfish(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }
        $lower = strtolower($type);
        if ($lower === 'self' || $lower === 'static') {
            return $this->currentClass();
        }
        if ($lower === 'parent') {
            return $this->index->get($this->currentClass())?->parent;
        }

        return $type;
    }

    private function resolveClassName(string $name): ?string
    {
        $resolved = $this->selfish($name);
        if ($resolved !== null && ! str_contains($resolved, '\\') && ! $this->index->has($resolved)) {
            $alias = self::DEFAULT_FACADE_ALIASES[strtolower($resolved)] ?? null;
            if ($alias !== null) {
                return $alias;
            }
        }

        return $resolved;
    }

    private function isContainerResolve(Node\Expr\FuncCall $node): bool
    {
        return $node->name instanceof Node\Name
            && in_array(strtolower($node->name->toString()), ['app', 'resolve'], true)
            && isset($node->args[0]) && $node->args[0] instanceof Node\Arg
            && $node->args[0]->value instanceof Node\Expr\ClassConstFetch
            && $node->args[0]->value->class instanceof Node\Name;
    }

    private function visitMemberCall(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $node): void
    {
        $member = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
        $direct = $node->var instanceof Node\Expr\Variable && $node->var->name === 'this';
        $this->index->startTracking();
        [$type, $resolution, $confidence] = $this->typeOf($node->var);
        $reads = $this->index->stopTracking();
        $this->record(EdgeType::Calls, $member, $type, $resolution, $confidence, $node->getStartLine(), $direct, $this->reason($node->var, $type), $node, $reads);
        if ($member !== null) {
            $this->detectScheduledCommand($node, $member, $type);
        }
    }

    private function visitStaticCall(Node\Expr\StaticCall $node): void
    {
        $member = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
        if (! $node->class instanceof Node\Name) {
            $this->record(EdgeType::Calls, $member, null, 'unresolved', 0.0, $node->getStartLine(), false, 'dynamic_class', $node);

            return;
        }
        $special = in_array(strtolower($node->class->toString()), ['self', 'static', 'parent'], true);
        $this->index->startTracking();
        $class = $this->resolveClassName($node->class->toString());
        $reads = $this->index->stopTracking();
        $this->record(EdgeType::Calls, $member, $class, $special ? strtolower($node->class->toString()) : 'static', 1.0, $node->getStartLine(), false, null, $node, $reads);
        if ($member !== null) {
            $this->detectScheduledCommand($node, $member, $class);
        }
    }

    private function visitNew(Node\Expr\New_ $node): void
    {
        if ($node->class instanceof Node\Name) {
            $this->index->startTracking();
            $class = $this->resolveClassName($node->class->toString());
            $reads = $this->index->stopTracking();
            $this->record(EdgeType::Instantiates, null, $class, 'new', 1.0, $node->getStartLine(), false, null, $node, $reads);
        } elseif (! $node->class instanceof Node\Stmt\Class_) {
            $this->record(EdgeType::Instantiates, null, null, 'unresolved', 0.0, $node->getStartLine(), false, 'dynamic_class', $node);
        }
    }

    private function visitContainerResolve(Node\Expr\FuncCall $node): void
    {
        /** @var Node\Expr\ClassConstFetch $target */
        $target = $node->args[0]->value;
        /** @var Node\Name $class */
        $class = $target->class;
        $this->index->startTracking();
        $resolved = $this->resolveClassName($class->toString());
        $reads = $this->index->stopTracking();
        $this->record(EdgeType::Resolves, null, $resolved, 'container', 1.0, $node->getStartLine(), false, null, $node, $reads);
    }

    /** @return array{0:?string,1:string,2:float} */
    private function typeOf(Node\Expr $expr, int $depth = 0): array
    {
        if ($depth > 8) {
            return [null, 'unresolved', 0.0];
        }
        if ($expr instanceof Node\Expr\Variable) {
            if ($expr->name === 'this') {
                return [$this->currentClass(), 'this', 1.0];
            }

            return is_string($expr->name) ? ($this->scope[$expr->name] ?? [null, 'unresolved', 0.0]) : [null, 'unresolved', 0.0];
        }
        if ($expr instanceof Node\Expr\PropertyFetch || $expr instanceof Node\Expr\NullsafePropertyFetch) {
            return $this->typeOfPropertyFetch($expr, $depth);
        }
        if ($expr instanceof Node\Expr\New_) {
            return $expr->class instanceof Node\Name ? [$this->resolveClassName($expr->class->toString()), 'new', 1.0] : [null, 'unresolved', 0.0];
        }
        if ($expr instanceof Node\Expr\StaticCall) {
            if (! ($expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier)) {
                return [null, 'unresolved', 0.0];
            }

            return $this->returnOf($this->resolveClassName($expr->class->toString()), $expr->name->toString(), 1.0, true);
        }
        if ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\NullsafeMethodCall) {
            if (! $expr->name instanceof Node\Identifier) {
                return [null, 'unresolved', 0.0];
            }
            [$receiverType, , $receiverConf] = $this->typeOf($expr->var, $depth + 1);

            return $receiverType === null ? [null, 'unresolved', 0.0] : $this->returnOf($receiverType, $expr->name->toString(), $receiverConf, false);
        }
        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name) {
            return $this->typeOfFuncCall($expr, $depth);
        }
        if ($expr instanceof Node\Expr\Ternary) {
            $branch = $this->typeOf($expr->if ?? $expr->cond, $depth + 1);

            return $branch[0] !== null ? $branch : $this->typeOf($expr->else, $depth + 1);
        }
        if ($expr instanceof Node\Expr\BinaryOp\Coalesce) {
            $left = $this->typeOf($expr->left, $depth + 1);

            return $left[0] !== null ? $left : $this->typeOf($expr->right, $depth + 1);
        }
        if ($expr instanceof Node\Expr\Assign) {
            return $this->typeOf($expr->expr, $depth + 1);
        }
        if ($expr instanceof Node\Expr\Cast\Object_) {
            return ['stdClass', 'cast', 1.0];
        }
        if ($expr instanceof Node\Expr\Clone_) {
            return $this->typeOf($expr->expr, $depth + 1);
        }

        return [null, 'unresolved', 0.0];
    }

    /** @return array{0:?string,1:string,2:float} */
    private function typeOfPropertyFetch(Node\Expr\PropertyFetch|Node\Expr\NullsafePropertyFetch $expr, int $depth): array
    {
        if (! $expr->name instanceof Node\Identifier) {
            return [null, 'unresolved', 0.0];
        }
        [$ownerType, , $ownerConf] = $this->typeOf($expr->var, $depth + 1);
        if ($ownerType === null) {
            return [null, 'unresolved', 0.0];
        }
        $prop = $this->index->findProp($ownerType, $expr->name->toString());
        if ($prop === null || $prop['type'] === null) {
            // Model relation accessed as property ($order->items): Eloquent's __get resolves this
            // to the related model or a collection of them, never to the Relation object itself.
            if ($this->index->isModel($ownerType)) {
                $method = $this->index->findMethod($ownerType, $expr->name->toString());
                if ($method !== null && $method[1]['return'] !== null && str_starts_with($method[1]['return'], 'relation<')) {
                    [$kind, , $target] = self::parseRelationMarker($method[1]['return']);

                    return [$kind === 'many' ? 'collection<'.$target.'>' : $target, 'eloquent', min(0.7, $ownerConf)];
                }
            }

            return [null, 'unresolved', 0.0];
        }
        $confidence = match ($prop['source']) {
            'typed_property', 'promoted_property', 'cast' => 1.0,
            'constructor_assignment' => 0.8,
            'docblock' => 0.7,
            default => 0.0,
        };

        return [$this->selfish($prop['type']), $prop['source'], min($confidence, $ownerConf)];
    }

    /** @return array{0:?string,1:string,2:float} */
    private function typeOfFuncCall(Node\Expr\FuncCall $expr, int $depth): array
    {
        /** @var Node\Name $name */
        $name = $expr->name;
        $function = strtolower($name->toString());
        if (in_array($function, ['app', 'resolve'], true)) {
            if (isset($expr->args[0]) && $expr->args[0] instanceof Node\Arg
                && $expr->args[0]->value instanceof Node\Expr\ClassConstFetch
                && $expr->args[0]->value->class instanceof Node\Name) {
                return [$this->resolveClassName($expr->args[0]->value->class->toString()), 'container', 1.0];
            }
            if (! isset($expr->args[0])) {
                return ['Illuminate\Foundation\Application', 'container', 1.0];
            }

            return [null, 'unresolved', 0.0];
        }
        $return = $this->index->functionReturn($function);

        return $return !== null ? [$return, 'return_type', 0.8] : [null, 'unresolved', 0.0];
    }

    /**
     * Return type of $cls::$m or $cls->$m, with Eloquent, relation and facade rules.
     *
     * @return array{0:?string,1:string,2:float}
     */
    private function returnOf(?string $cls, string $member, float $inConfidence, bool $static): array
    {
        if ($cls === null) {
            return [null, 'unresolved', 0.0];
        }

        // A call through an interface resolves to the booted container's own binding when there is
        // one (confidence 1.0, it is not a guess); otherwise, if the project has exactly one
        // implementation, that (confidence 0.6) - SPEC.md 4.1 rule 4, in that preference order. This
        // applies even when the interface itself declares the member, since a graph edge naming the
        // concrete class is more useful than one naming the contract everyone shares. Applied here,
        // around every resolution path below, rather than duplicated in each one.
        $interfaceOverride = null;
        $interfaceResolutionKind = 'interface';
        if ($this->index->isInterface($cls)) {
            $bound = $this->index->binding($cls);
            if ($bound !== null) {
                $cls = $bound;
                $interfaceOverride = min($inConfidence, 1.0);
                $interfaceResolutionKind = 'container';
            } else {
                $implementation = $this->index->singleImplementation($cls);
                if ($implementation !== null) {
                    $cls = $implementation;
                    $interfaceOverride = min($inConfidence, 0.6);
                }
            }
        }

        $result = $this->resolveMember($cls, $member, $inConfidence, $static);
        if ($interfaceOverride !== null && $result[0] !== null) {
            return [$result[0], $interfaceResolutionKind, min($result[2], $interfaceOverride)];
        }

        return $result;
    }

    /** @return array{0:?string,1:string,2:float} */
    private function resolveMember(string $cls, string $member, float $inConfidence, bool $static): array
    {
        $lower = strtolower($member);

        // Eloquent relation methods forward undeclared calls to the related model's builder
        // (Illuminate's ForwardsCalls trait), except for a short list of relation-only mutators.
        if (str_starts_with($cls, 'relation<')) {
            [, , $target] = self::parseRelationMarker($cls);
            if (in_array($lower, self::RELATION_MUTATORS, true)) {
                return [null, 'scalar', 0.0];
            }
            $cls = 'builder<'.$target.'>';
        }

        if (str_starts_with($cls, 'builder<')) {
            return $this->returnOfBuilder(substr($cls, 8, -1), $lower, $inConfidence);
        }
        if (str_starts_with($cls, 'collection<')) {
            return $this->returnOfCollection(substr($cls, 11, -1), $lower, $inConfidence);
        }

        $found = $this->index->findMethod($cls, $member);
        if ($found !== null) {
            return $this->returnOfFoundMethod($cls, $found, $lower, $inConfidence);
        }
        if ($this->index->isModel($cls)) {
            return $this->returnOfModelForward($cls, $lower, $inConfidence);
        }

        $class = $this->index->get($cls);
        if ($class !== null && $static && isset($class->facadeMethods[$lower])) {
            return [$this->selfish($class->facadeMethods[$lower]), 'facade', min($inConfidence, 0.8)];
        }
        // PHP's Throwable interface guarantees these methods on any caught exception, indexed or not.
        if (! $static && in_array($lower, self::THROWABLE_SCALARS, true)) {
            return [null, 'scalar', 0.0];
        }
        if (! $static && $lower === 'getprevious') {
            return ['Throwable', 'throwable', min($inConfidence, 1.0)];
        }
        $forward = self::MANAGER_FORWARDS[strtolower($cls)] ?? null;
        if ($forward !== null) {
            $result = $this->returnOf($forward, $member, $inConfidence, $static);
            if ($result[0] !== null || $result[1] === 'scalar') {
                return [$result[0], $result[1], min($result[2], 0.8)];
            }
        }
        if ($class !== null && ! $class->project) {
            return [null, 'external_unknown', 0.0];
        }

        return [null, 'unresolved', 0.0];
    }

    /** @return array{0:?string,1:string,2:float} */
    private function returnOfBuilder(string $model, string $member, float $inConfidence): array
    {
        if (in_array($member, ['first', 'firstorfail', 'find', 'findorfail', 'create', 'firstorcreate', 'updateorcreate', 'firstornew', 'make', 'sole', 'forcecreate', 'firstwhere'], true)) {
            return [$model, 'eloquent', min($inConfidence, 0.7)];
        }
        if (in_array($member, ['get', 'all', 'cursor', 'lazy'], true)) {
            return ['collection<'.$model.'>', 'eloquent', min($inConfidence, 0.7)];
        }
        if (in_array($member, ['paginate', 'simplepaginate', 'cursorpaginate'], true)) {
            return ['Illuminate\Contracts\Pagination\LengthAwarePaginator', 'eloquent', min($inConfidence, 0.7)];
        }
        if (in_array($member, ['count', 'sum', 'max', 'min', 'avg', 'exists', 'doesntexist', 'value', 'tosql', 'update', 'delete', 'insert', 'pluck', 'chunk', 'each'], true)) {
            return [null, 'scalar', 0.0];
        }

        return ['builder<'.$model.'>', 'eloquent', min($inConfidence, 0.7)];
    }

    /** @return array{0:?string,1:string,2:float} */
    private function returnOfCollection(string $model, string $member, float $inConfidence): array
    {
        if (in_array($member, ['first', 'last', 'firstwhere', 'pop', 'shift', 'find', 'sole', 'firstorfail', 'random'], true)) {
            return [$model, 'eloquent', min($inConfidence, 0.7)];
        }
        if (in_array($member, ['filter', 'where', 'wherein', 'sortby', 'sortbydesc', 'reject', 'unique', 'values', 'take', 'skip', 'each', 'load', 'reverse', 'whereinstanceof', 'wherenotnull', 'slice', 'merge'], true)) {
            return ['collection<'.$model.'>', 'eloquent', min($inConfidence, 0.7)];
        }

        return ['Illuminate\Support\Collection', 'eloquent', min($inConfidence, 0.7)];
    }

    /**
     * @param  array{0:string,1:array{name:string,return:?string,static:bool,params:array<string,?string>}}  $found
     * @return array{0:?string,1:string,2:float}
     */
    private function returnOfFoundMethod(string $cls, array $found, string $member, float $inConfidence): array
    {
        [$owner, $methodInfo] = $found;
        $return = $methodInfo['return'];
        if ($return === null) {
            return $this->index->isModel($cls) && str_starts_with($member, 'scope')
                ? ['builder<'.$cls.'>', 'eloquent', min($inConfidence, 0.7)]
                : [null, 'unresolved', 0.0];
        }
        // The method's own return is our relation<kind,Target> marker (SPEC.md 4.1 rule 11): calling
        // it, or using it as the receiver of a further call, is Eloquent-family confidence, not a
        // plain declared-return-type hop - it still needs unwrapping wherever it is used next.
        if (str_starts_with($return, 'relation<')) {
            return [$return, 'eloquent', min($inConfidence, 0.7)];
        }
        $lower = strtolower($return);
        if (in_array($lower, ['static', 'self', '$this'], true)) {
            $return = $cls;
        } elseif ($lower === 'parent') {
            $return = $this->index->get($owner)?->parent;
        }
        if ($return !== null && $this->index->isModel($cls)
            && in_array(strtolower($return), ['illuminate\database\eloquent\builder', 'illuminate\database\eloquent\relations\relation'], true)) {
            return ['builder<'.$cls.'>', 'eloquent', min($inConfidence, 0.7)];
        }

        return [$return, 'return_type', min($inConfidence, 0.8)];
    }

    /** @return array{0:?string,1:string,2:float} */
    private function returnOfModelForward(string $cls, string $member, float $inConfidence): array
    {
        if (in_array($member, ['first', 'find', 'findorfail', 'firstorfail', 'create', 'firstorcreate', 'updateorcreate', 'make', 'fresh', 'refresh', 'load', 'loadmissing', 'replicate', 'setrelation', 'fill', 'forcefill', 'newinstance', 'findornew', 'firstornew'], true)) {
            return [$cls, 'eloquent', min($inConfidence, 0.7)];
        }
        if (in_array($member, ['get', 'all', 'paginate', 'pluck', 'count', 'sum', 'exists', 'save', 'delete', 'update', 'tojson', 'toarray', 'getkey', 'getattribute', 'setattribute', 'increment', 'decrement', 'touch', 'push', 'saveorfail', 'forcedelete', 'restore', 'trashed'], true)) {
            return [null, 'scalar', 0.0];
        }

        return ['builder<'.$cls.'>', 'eloquent', min($inConfidence, 0.7)];
    }

    private function reason(Node\Expr $receiver, ?string $type): ?string
    {
        if ($type !== null) {
            return null;
        }
        if ($receiver instanceof Node\Expr\Variable) {
            if (! is_string($receiver->name)) {
                return 'dynamic_variable';
            }
            $scope = $this->scope[$receiver->name] ?? null;

            return $scope === null ? 'unknown_variable' : ($scope[1] === 'untyped_param' ? 'untyped_param' : 'untyped_local');
        }
        if ($receiver instanceof Node\Expr\PropertyFetch || $receiver instanceof Node\Expr\NullsafePropertyFetch) {
            return ($receiver->var instanceof Node\Expr\Variable && $receiver->var->name === 'this') ? 'untyped_property' : 'property_chain';
        }
        if ($receiver instanceof Node\Expr\MethodCall || $receiver instanceof Node\Expr\NullsafeMethodCall) {
            return 'chain_unknown_return';
        }
        if ($receiver instanceof Node\Expr\StaticCall) {
            return 'static_unknown_return';
        }
        if ($receiver instanceof Node\Expr\FuncCall) {
            return 'function_unknown_return';
        }
        if ($receiver instanceof Node\Expr\ArrayDimFetch) {
            return 'array_element';
        }

        return 'other:'.$receiver->getType();
    }

    /** @param string[] $reads symbols already consulted by the caller while computing $type */
    private function record(
        EdgeType $edgeType,
        ?string $member,
        ?string $type,
        string $resolutionKind,
        float $confidence,
        int $line,
        bool $direct,
        ?string $reason,
        Node\Expr $receiver,
        array $reads = [],
    ): void {
        if ($type === null) {
            if (! $direct) {
                $this->unresolved[] = new UnresolvedRow(
                    $this->file,
                    $line,
                    $edgeType->value,
                    $this->printer->prettyPrintExpr($receiver),
                    null,
                    $member,
                    dependsOn: array_values(array_unique($reads)),
                );
            }

            return;
        }

        $base = $type;
        if (str_starts_with($type, 'builder<') || str_starts_with($type, 'collection<')) {
            $base = substr($type, strpos($type, '<') + 1, -1);
        } elseif (str_starts_with($type, 'relation<')) {
            $base = self::parseRelationMarker($type)[2];
        }

        $this->index->startTracking();

        // Same substitution as resolveMember(), applied here too: this path records the edge for
        // the call itself (visitMemberCall/visitStaticCall/visitNew/visitContainerResolve pass
        // straight through typeOf() without going through returnOf()), so an interface-typed
        // receiver needs its own check, not just the one further chaining goes through.
        if ($this->index->isInterface($base)) {
            $bound = $this->index->binding($base);
            if ($bound !== null) {
                $base = $bound;
                $resolutionKind = 'container';
                $confidence = min($confidence, 1.0);
            } else {
                $implementation = $this->index->singleImplementation($base);
                if ($implementation !== null) {
                    $base = $implementation;
                    $resolutionKind = 'interface';
                    $confidence = min($confidence, 0.6);
                }
            }
        }

        $targetKey = $this->targetKey($edgeType, $base, $member);
        $reads = [...$reads, ...$this->index->stopTracking()];
        if ($targetKey === null) {
            return;
        }

        $resolution = self::resolutionFromString($resolutionKind);
        $this->edges[] = new Edge(
            $edgeType,
            $this->currentTargetKey(),
            $targetKey,
            $this->file,
            $line,
            $resolution,
            $resolution !== null ? min($confidence, $resolution->baseConfidence()) : $confidence,
            $member !== null ? ['member' => $member] : [],
            array_values(array_unique($reads)),
        );
    }

    private function currentTargetKey(): string
    {
        $class = $this->currentClass();
        if ($class === null) {
            return Key::file($this->file);
        }

        return $this->method !== null ? Key::method($class, $this->method) : Key::classLike($class);
    }

    private function targetKey(EdgeType $edgeType, string $base, ?string $member): ?string
    {
        if ($edgeType !== EdgeType::Calls || $member === null) {
            return $this->classKeyFor($base);
        }
        $found = $this->index->findMethod($base, $member);
        if ($found !== null && $this->index->isProject($found[0])) {
            // Use the method's own declared casing, not how the call site happened to write it -
            // PHP method names are case-insensitive, but the node key must exactly match the one
            // PhpExtractor gave the declared method.
            return Key::method($found[0], $found[1]['name']);
        }

        return $this->classKeyFor($base);
    }

    private function classKeyFor(string $fqcn): ?string
    {
        if ($fqcn === '') {
            return null;
        }
        if ($this->index->has($fqcn)) {
            return Key::classLike($fqcn);
        }
        $key = Key::external($fqcn);
        if (! isset($this->extraNodes[$key])) {
            $this->extraNodes[$key] = new GraphNode(NodeType::External, $fqcn, $key);
        }

        return $key;
    }

    /**
     * Emits a maps_table edge for an Eloquent model, checked here (not at declaration time)
     * because it needs isModel()'s full parent-chain walk against the complete index, not just the
     * immediate `extends` clause a single file can see on its own. The table name is the model's
     * own declared $table when there is one, otherwise the same snake_case-plural-of-the-class-name
     * convention Eloquent's own Model::getTable() computes, so this is never a guess.
     */
    private function emitTableMapping(string $fqcn, int $line): void
    {
        if (! $this->index->isModel($fqcn)) {
            return;
        }
        $class = $this->index->get($fqcn);
        $table = $class?->tableOverride ?? Str::snake(Str::pluralStudly(class_basename($fqcn)));

        $this->edges[] = new Edge(
            EdgeType::MapsTable,
            Key::classLike($fqcn),
            $this->ensureTableNode($table),
            $this->file,
            $line,
            meta: ['source' => $class?->tableOverride !== null ? 'declared' : 'convention'],
        );
    }

    private function ensureTableNode(string $table): string
    {
        $key = Key::table($table);
        if (! isset($this->extraNodes[$key])) {
            $this->extraNodes[$key] = new GraphNode(NodeType::Table, $table, $key);
        }

        return $key;
    }

    private function ensureViewNode(string $view): string
    {
        $key = Key::view($view);
        if (! isset($this->extraNodes[$key])) {
            $this->extraNodes[$key] = new GraphNode(NodeType::View, $view, $key);
        }

        return $key;
    }

    /** @param Node\Arg[] $args */
    private function firstStringArg(array $args): ?string
    {
        $first = $args[0] ?? null;

        return ($first instanceof Node\Arg && $first->value instanceof Node\Scalar\String_) ? $first->value->value : null;
    }

    private const SCHEMA_TABLE_METHODS = ['create', 'table', 'drop', 'dropifexists', 'rename'];

    /**
     * Schema::create/table/drop/dropIfExists/rename('name', ...) and DB::table('name') - the table
     * name is always the migration or query's own literal string, never inferred, so a dynamic
     * table name (built from a variable) is simply not captured rather than guessed at.
     */
    private function detectTableSideEffect(Node\Expr\StaticCall $node): void
    {
        if (! $node->name instanceof Node\Identifier || ! $node->class instanceof Node\Name) {
            return;
        }
        $member = strtolower($node->name->toString());
        $facade = $this->resolveClassName($node->class->toString());
        $table = $this->firstStringArg($node->args);
        if ($table === null) {
            return;
        }

        if ($facade === 'Illuminate\Support\Facades\Schema' && in_array($member, self::SCHEMA_TABLE_METHODS, true)) {
            $op = $member === 'dropifexists' ? 'drop' : $member;
            $this->edges[] = new Edge(EdgeType::TouchesTable, $this->currentTargetKey(), $this->ensureTableNode($table), $this->file, $node->getStartLine(), meta: ['op' => $op]);
        } elseif ($facade === 'Illuminate\Support\Facades\DB' && $member === 'table') {
            $this->edges[] = new Edge(EdgeType::TouchesTable, $this->currentTargetKey(), $this->ensureTableNode($table), $this->file, $node->getStartLine(), meta: ['op' => 'query']);
        }
    }

    private const DISPATCH_METHODS = ['dispatch', 'dispatchif', 'dispatchunless', 'dispatchsync', 'dispatchnow', 'dispatchafterresponse'];

    /**
     * X::dispatch(...) dispatches X itself; Event::dispatch(new X()) and Bus::dispatch(new X())
     * dispatch their argument instead - two different shapes of the same underlying fact.
     */
    private function detectDispatch(Node\Expr\StaticCall $node): void
    {
        if (! $node->name instanceof Node\Identifier || ! $node->class instanceof Node\Name) {
            return;
        }
        $member = strtolower($node->name->toString());
        if (! in_array($member, self::DISPATCH_METHODS, true)) {
            return;
        }
        $facade = $this->resolveClassName($node->class->toString());

        $target = null;
        if (in_array($facade, ['Illuminate\Support\Facades\Event', 'Illuminate\Support\Facades\Bus'], true)) {
            $arg = $node->args[0] ?? null;
            if ($arg instanceof Node\Arg && $arg->value instanceof Node\Expr\New_ && $arg->value->class instanceof Node\Name) {
                $target = $this->resolveClassName($arg->value->class->toString());
            }
        } else {
            $target = $facade;
        }
        if ($target === null) {
            return;
        }
        $targetKey = $this->classKeyFor($target);
        if ($targetKey === null) {
            return;
        }
        $this->edges[] = new Edge(EdgeType::Dispatches, $this->currentTargetKey(), $targetKey, $this->file, $node->getStartLine(), meta: ['via' => $member]);
    }

    /** event(new X()) - the Event facade's function-helper form. */
    private function detectEventDispatch(Node\Expr\FuncCall $node): void
    {
        if (! ($node->name instanceof Node\Name && strtolower($node->name->toString()) === 'event')) {
            return;
        }
        $arg = $node->args[0] ?? null;
        if (! ($arg instanceof Node\Arg && $arg->value instanceof Node\Expr\New_ && $arg->value->class instanceof Node\Name)) {
            return;
        }
        $target = $this->resolveClassName($arg->value->class->toString());
        $targetKey = $target !== null ? $this->classKeyFor($target) : null;
        if ($targetKey === null) {
            return;
        }
        $this->edges[] = new Edge(EdgeType::Dispatches, $this->currentTargetKey(), $targetKey, $this->file, $node->getStartLine(), meta: ['via' => 'event']);
    }

    /** view('name', ...), route('name', ...) and to_route('name', ...). */
    private function detectRenderOrRouteLink(Node\Expr\FuncCall $node): void
    {
        if (! $node->name instanceof Node\Name) {
            return;
        }
        $fn = strtolower($node->name->toString());
        if (! in_array($fn, ['view', 'route', 'to_route'], true)) {
            return;
        }
        $literal = $this->firstStringArg($node->args);
        if ($literal === null) {
            if (isset($node->args[0]) && $node->args[0] instanceof Node\Arg) {
                $this->unresolved[] = new UnresolvedRow($this->file, $node->getStartLine(), $fn, $this->printer->prettyPrintExpr($node), null, null);
            }

            return;
        }
        if ($fn === 'view') {
            $this->edges[] = new Edge(EdgeType::Renders, $this->currentTargetKey(), $this->ensureViewNode($literal), $this->file, $node->getStartLine());
        } else {
            $this->emitLinksRoute($literal, $node->getStartLine());
        }
    }

    /** View::make('name', ...). */
    private function detectViewMake(Node\Expr\StaticCall $node): void
    {
        if (! $node->class instanceof Node\Name || $this->resolveClassName($node->class->toString()) !== 'Illuminate\Support\Facades\View') {
            return;
        }
        $literal = $this->firstStringArg($node->args);
        if ($literal === null) {
            return;
        }
        $this->edges[] = new Edge(EdgeType::Renders, $this->currentTargetKey(), $this->ensureViewNode($literal), $this->file, $node->getStartLine());
    }

    private function emitLinksRoute(string $name, int $line): void
    {
        $routeKey = $this->routeKeysByName[$name] ?? null;
        if ($routeKey === null) {
            $this->unresolved[] = new UnresolvedRow($this->file, $line, 'links_route', $name, null, null, ["route name '{$name}' not found"]);

            return;
        }
        $this->edges[] = new Edge(EdgeType::LinksRoute, $this->currentTargetKey(), $routeKey, $this->file, $line);
    }

    /**
     * Model::observe(SomeObserver::class) - or an array of observers. The edge direction is
     * observer -> model (SPEC.md 3.2: "observes | class -> class (model)"), the opposite of the
     * static call's own receiver/argument order.
     */
    private function detectObserver(Node\Expr\StaticCall $node): void
    {
        if (! ($node->name instanceof Node\Identifier && strtolower($node->name->toString()) === 'observe')) {
            return;
        }
        if (! $node->class instanceof Node\Name) {
            return;
        }
        $model = $this->resolveClassName($node->class->toString());
        $modelKey = $model !== null ? $this->classKeyFor($model) : null;
        if ($modelKey === null || ! isset($node->args[0]) || ! $node->args[0] instanceof Node\Arg) {
            return;
        }
        foreach ($this->classConstTargets($node->args[0]->value) as $observer) {
            $observerKey = $this->classKeyFor($observer);
            if ($observerKey !== null) {
                $this->edges[] = new Edge(EdgeType::Observes, $observerKey, $modelKey, $this->file, $node->getStartLine());
            }
        }
    }

    /** Event::listen(SomeEvent::class, SomeListener::class) - or [SomeListener::class, 'handle']. */
    private function detectEventListen(Node\Expr\StaticCall $node): void
    {
        if (! ($node->name instanceof Node\Identifier && strtolower($node->name->toString()) === 'listen')) {
            return;
        }
        if (! $node->class instanceof Node\Name || $this->resolveClassName($node->class->toString()) !== 'Illuminate\Support\Facades\Event') {
            return;
        }
        $eventArg = $node->args[0] ?? null;
        $listenerArg = $node->args[1] ?? null;
        if (! $eventArg instanceof Node\Arg || ! $listenerArg instanceof Node\Arg) {
            return;
        }
        $events = $this->classConstTargets($eventArg->value);
        $listeners = $this->classConstTargets($listenerArg->value);
        foreach ($events as $event) {
            $eventKey = $this->classKeyFor($event);
            if ($eventKey === null) {
                continue;
            }
            foreach ($listeners as $listener) {
                $listenerKey = $this->classKeyFor($listener);
                if ($listenerKey !== null) {
                    $this->edges[] = new Edge(EdgeType::Listens, $listenerKey, $eventKey, $this->file, $node->getStartLine());
                }
            }
        }
    }

    /**
     * Resolves Foo::class, or an array literal of Foo::class / [Foo::class, 'method'] entries, to
     * the class names referenced - the two shapes Model::observe(), Event::listen() and a
     * $listen property all accept for "one or more classes".
     *
     * @return string[]
     */
    private function classConstTargets(Node\Expr $expr): array
    {
        if ($expr instanceof Node\Expr\ClassConstFetch && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier && strtolower($expr->name->toString()) === 'class') {
            $resolved = $this->resolveClassName($expr->class->toString());

            return $resolved !== null ? [$resolved] : [];
        }
        if ($expr instanceof Node\Expr\Array_) {
            $targets = [];
            foreach ($expr->items as $item) {
                if ($item === null) { // @phpstan-ignore identical.alwaysFalse
                    continue;
                }
                $value = $item->value;
                if ($value instanceof Node\Expr\Array_ && isset($value->items[0]) && $value->items[0] !== null) {
                    $value = $value->items[0]->value;
                }
                $targets = [...$targets, ...$this->classConstTargets($value)];
            }

            return $targets;
        }

        return [];
    }

    private const SCHEDULE_RECEIVERS = ['illuminate\console\scheduling\schedule', 'illuminate\support\facades\schedule'];

    /**
     * $schedule->command(SendEmails::class)->daily() and Schedule::job(new X())->hourly() - only
     * the class-reference and new-instance forms resolve to a real target. A literal artisan
     * signature string ('emails:send {user}') cannot be mapped to the command class that handles
     * it without a command registry Mapin does not build, so that form is left unresolved rather
     * than guessed at.
     */
    private function detectScheduledCommand(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall|Node\Expr\StaticCall $node, string $member, ?string $receiverType): void
    {
        if (! in_array(strtolower($member), ['command', 'job'], true)) {
            return;
        }
        if ($receiverType === null || ! in_array(strtolower($receiverType), self::SCHEDULE_RECEIVERS, true)) {
            return;
        }
        $arg = $node->args[0] ?? null;
        if (! $arg instanceof Node\Arg) {
            return;
        }

        $target = null;
        if ($arg->value instanceof Node\Expr\ClassConstFetch && $arg->value->class instanceof Node\Name
            && $arg->value->name instanceof Node\Identifier && strtolower($arg->value->name->toString()) === 'class') {
            $target = $this->resolveClassName($arg->value->class->toString());
        } elseif ($arg->value instanceof Node\Expr\New_ && $arg->value->class instanceof Node\Name) {
            $target = $this->resolveClassName($arg->value->class->toString());
        }
        if ($target === null) {
            return;
        }
        $targetKey = $this->classKeyFor($target);
        if ($targetKey === null) {
            return;
        }
        $this->edges[] = new Edge(EdgeType::Schedules, $this->currentTargetKey(), $targetKey, $this->file, $node->getStartLine(), meta: ['via' => strtolower($member)]);
    }

    /**
     * Unpacks the synthetic "relation<one|many,hasMany,App\Models\Book>" marker DeclarationVisitor
     * writes as a relation method's return type (SPEC.md 4.1 rule 11).
     *
     * @return array{0:string,1:string,2:string} [one|many, declared relation method name, target FQCN]
     */
    private static function parseRelationMarker(string $marker): array
    {
        return explode(',', substr($marker, 9, -1), 3);
    }

    private static function resolutionFromString(string $kind): ?Resolution
    {
        return match ($kind) {
            'static' => Resolution::StaticCall,
            'new' => Resolution::NewInstance,
            'self' => Resolution::SelfRef,
            'parent' => Resolution::ParentRef,
            'this' => Resolution::ThisRef,
            'typed_property', 'promoted_property' => Resolution::TypedProperty,
            'param' => Resolution::Param,
            'container' => Resolution::Container,
            'constructor_assignment' => Resolution::ConstructorAssignment,
            'local_assign', 'local_new' => Resolution::LocalAssign,
            'return_type' => Resolution::ReturnType,
            'facade' => Resolution::Facade,
            'cast' => Resolution::Cast,
            'catch' => Resolution::Catch_,
            'eloquent' => Resolution::Eloquent,
            'docblock' => Resolution::Docblock,
            'interface' => Resolution::Interface_,
            default => null,
        };
    }
}
