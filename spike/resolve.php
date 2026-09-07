<?php

declare(strict_types=1);

/**
 * Mapin phase 0 spike.
 *
 * Measures how many member calls in a Laravel app/ directory can be resolved to a receiver type
 * using nikic/php-parser plus a small set of Laravel-aware rules (see SPEC.md section 4.1).
 *
 * Usage (inside the host app container, where vendor/ is available):
 *   php resolve.php <project-root> [--vendor] [--out=/tmp/mapin-spike.json] [--check=Foo,Bar]
 *
 * --vendor  also indexes declarations (signatures, docblock return types, facade @method lines)
 *           from vendor/laravel/framework/src so chains through framework objects resolve.
 * --check   short class names to report incoming edges for (who injects / instantiates / calls them).
 *
 * This is a throwaway measurement script. It is not the package code.
 */

$root = $argv[1] ?? null;
if ($root === null || !is_dir($root)) {
    fwrite(STDERR, "usage: php resolve.php <project-root> [--vendor] [--out=file] [--check=A,B]\n");
    exit(1);
}
$root = rtrim($root, '/');
$opts = ['vendor' => false, 'out' => null, 'check' => [], 'dumpFunctions' => [], 'dumpClass' => null, 'extraVendor' => []];
foreach (array_slice($argv, 2) as $arg) {
    if ($arg === '--vendor') {
        $opts['vendor'] = true;
    } elseif (str_starts_with($arg, '--out=')) {
        $opts['out'] = substr($arg, 6);
    } elseif (str_starts_with($arg, '--check=')) {
        $opts['check'] = array_filter(explode(',', substr($arg, 8)));
    } elseif (str_starts_with($arg, '--dump-functions=')) {
        $opts['dumpFunctions'] = array_filter(explode(',', substr($arg, 17)));
    } elseif (str_starts_with($arg, '--dump-class=')) {
        $opts['dumpClass'] = substr($arg, 13);
    } elseif (str_starts_with($arg, '--extra-vendor=')) {
        $opts['extraVendor'] = array_filter(explode(',', substr($arg, 15)));
    }
}

require $root . '/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

// ---------------------------------------------------------------------------------------------
// Type helpers
// ---------------------------------------------------------------------------------------------

final class T
{
    /** Convert a php-parser type node into a class-like FQCN string, or null for builtins/unknown. */
    public static function fromNode(?Node $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof Node\NullableType) {
            return self::fromNode($type->type);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $t) {
                $r = self::fromNode($t);
                if ($r !== null) {
                    return $r;
                }
            }
            return null;
        }
        if ($type instanceof Node\Name) {
            $s = $type->toString();
            return in_array(strtolower($s), ['self', 'static', 'parent'], true) ? strtolower($s) : ltrim($s, '\\');
        }
        if ($type instanceof Node\Identifier) {
            $s = strtolower($type->toString());
            return in_array($s, ['self', 'static'], true) ? $s : null; // builtins yield null
        }
        return null;
    }

    /**
     * Extract the @return expression text from a docblock, unwrapping PHPStan's conditional
     * return type syntax used pervasively across the Laravel framework:
     *   @return ($key is null ? \Illuminate\Http\Request : mixed)
     * The "is null" true-branch is taken because that is overwhelmingly the branch used when the
     * helper or method is called with no arguments, which is the common no-arg chaining case
     * (request()->..., $request->route()->..., session()->..., and the same idiom throughout
     * Illuminate). This is a Laravel/PHPStan-specific convention, not a generic parser feature.
     */
    public static function extractReturnExpr(string $doc): ?string
    {
        if (preg_match('/@return\s*\(\s*\$\w+\s+is\s+null\s*\?\s*([^\s:]+)/', $doc, $m)) {
            return $m[1];
        }
        if (preg_match('/@return\s+([^\s]+)/', $doc, $m)) {
            return $m[1];
        }
        return null;
    }

    /** First class-like type from a docblock type expression such as "\Foo|null" or "Foo[]". */
    public static function fromDoc(?string $expr, ?string $ns = null, array $uses = []): ?string
    {
        if ($expr === null) {
            return null;
        }
        foreach (explode('|', $expr) as $part) {
            $part = trim($part);
            if ($part === '' || str_ends_with($part, '[]')) {
                continue;
            }
            $part = ltrim($part, '?');
            // Strip a PHPStan/Psalm generic parameter, e.g. "Builder<static>" or "Collection<int, User>".
            // The base class is what we resolve; the type parameter is not modelled by the spike.
            $part = preg_replace('/<.*>$/', '', $part);
            if (preg_match('/^(array|string|int|float|bool|boolean|integer|mixed|void|null|callable|iterable|object|resource|false|true|\$this|static|self|never)$/i', $part)) {
                if (strtolower($part) === 'static' || strtolower($part) === 'self' || $part === '$this') {
                    return 'static';
                }
                continue;
            }
            if (preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $part) !== 1) {
                continue;
            }
            if (str_starts_with($part, '\\')) {
                return ltrim($part, '\\');
            }
            $first = explode('\\', $part)[0];
            if (isset($uses[$first])) {
                return $uses[$first] . substr($part, strlen($first));
            }
            return $ns ? $ns . '\\' . $part : $part;
        }
        return null;
    }
}

final class ClassInfo
{
    /** @var array<string, array{type:?string, source:string}> */
    public array $props = [];
    /** @var array<string, array{return:?string, static:bool, params:array<string,?string>}> */
    public array $methods = [];
    /** @var array<string, string> facade static method name => return type */
    public array $facadeMethods = [];
    /** @var string[] */
    public array $interfaces = [];
    /** @var string[] */
    public array $traits = [];

    public function __construct(
        public string $fqcn,
        public string $kind,
        public string $file,
        public int $line,
        public ?string $parent,
        public bool $project,
    ) {
    }
}

final class Index
{
    /** @var array<string, ClassInfo> keyed by lowercase FQCN */
    public array $classes = [];
    /** @var array<string, ?string> lowercase function name => return type */
    public array $functions = [];
    /** @var array<string, string[]> lowercase interface => implementing FQCNs (project only) */
    public array $implementations = [];

    public function get(?string $fqcn): ?ClassInfo
    {
        return $fqcn === null ? null : ($this->classes[strtolower($fqcn)] ?? null);
    }

    /** Find a method walking the parent and trait chain. Returns [ownerFqcn, info] or null. */
    public function findMethod(?string $fqcn, string $name, int $depth = 0): ?array
    {
        $ci = $this->get($fqcn);
        if ($ci === null || $depth > 15) {
            return null;
        }
        $l = strtolower($name);
        if (isset($ci->methods[$l])) {
            return [$ci->fqcn, $ci->methods[$l]];
        }
        foreach ($ci->traits as $t) {
            if ($r = $this->findMethod($t, $name, $depth + 1)) {
                return $r;
            }
        }
        if ($ci->parent !== null && ($r = $this->findMethod($ci->parent, $name, $depth + 1))) {
            return $r;
        }
        foreach ($ci->interfaces as $i) {
            if ($r = $this->findMethod($i, $name, $depth + 1)) {
                return $r;
            }
        }
        return null;
    }

    /** Find a property walking parents and traits. */
    public function findProp(?string $fqcn, string $name, int $depth = 0): ?array
    {
        $ci = $this->get($fqcn);
        if ($ci === null || $depth > 15) {
            return null;
        }
        if (isset($ci->props[$name])) {
            return $ci->props[$name];
        }
        foreach ($ci->traits as $t) {
            if ($r = $this->findProp($t, $name, $depth + 1)) {
                return $r;
            }
        }
        if ($ci->parent !== null && ($r = $this->findProp($ci->parent, $name, $depth + 1))) {
            return $r;
        }
        // Eloquent casts created_at/updated_at to Carbon by default on every model, and deleted_at
        // when the model uses SoftDeletes, even with no explicit $casts entry. Checked only at the
        // original call (depth 0) since it depends on the concrete model, not each ancestor visited.
        if ($depth === 0 && $this->isModel($fqcn)) {
            if (in_array($name, ['created_at', 'updated_at'], true)) {
                return ['type' => 'Carbon\Carbon', 'source' => 'cast'];
            }
            if ($name === 'deleted_at' && $this->usesSoftDeletes($fqcn)) {
                return ['type' => 'Carbon\Carbon', 'source' => 'cast'];
            }
        }
        return null;
    }

    public function isModel(?string $fqcn, int $depth = 0): bool
    {
        if ($fqcn === null || $depth > 15) {
            return false;
        }
        $l = strtolower($fqcn);
        if (in_array($l, [
            'illuminate\database\eloquent\model',
            'illuminate\foundation\auth\user',
            'illuminate\database\eloquent\relations\pivot',
        ], true)) {
            return true;
        }
        $ci = $this->get($fqcn);
        return $ci !== null && $ci->parent !== null && $this->isModel($ci->parent, $depth + 1);
    }

    public function usesSoftDeletes(?string $fqcn, int $depth = 0): bool
    {
        if ($fqcn === null || $depth > 15) {
            return false;
        }
        $ci = $this->get($fqcn);
        if ($ci === null) {
            return false;
        }
        foreach ($ci->traits as $t) {
            if (strtolower($t) === 'illuminate\database\eloquent\softdeletes' || str_ends_with(strtolower($t), '\softdeletes')) {
                return true;
            }
        }
        return $ci->parent !== null && $this->usesSoftDeletes($ci->parent, $depth + 1);
    }

    public function isInterface(?string $fqcn): bool
    {
        $ci = $this->get($fqcn);
        return $ci !== null && $ci->kind === 'interface';
    }

    public function isProject(?string $fqcn): bool
    {
        $ci = $this->get($fqcn);
        return $ci !== null && $ci->project;
    }
}

// ---------------------------------------------------------------------------------------------
// Pass 1: declarations
// ---------------------------------------------------------------------------------------------

final class DeclarationVisitor extends NodeVisitorAbstract
{
    /** @var ClassInfo[] */
    private array $stack = [];
    private ?string $ns = null;
    /** @var array<string,string> alias => FQCN */
    private array $uses = [];

    public function __construct(private Index $index, private string $file, private bool $project)
    {
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->ns = $node->name?->toString();
            $this->uses = [];
        }
        if ($node instanceof Node\Stmt\Use_ && $node->type === Node\Stmt\Use_::TYPE_NORMAL) {
            foreach ($node->uses as $u) {
                $this->uses[$u->getAlias()->toString()] = $u->name->toString();
            }
        }
        if ($node instanceof Node\Stmt\ClassLike) {
            $fqcn = isset($node->namespacedName) ? $node->namespacedName->toString() : null;
            if ($fqcn === null) {
                $fqcn = 'anonymous@' . $this->file . ':' . $node->getStartLine();
            }
            $kind = match (true) {
                $node instanceof Node\Stmt\Interface_ => 'interface',
                $node instanceof Node\Stmt\Trait_ => 'trait',
                $node instanceof Node\Stmt\Enum_ => 'enum',
                default => 'class',
            };
            $parent = null;
            if ($node instanceof Node\Stmt\Class_ && $node->extends !== null) {
                $parent = $node->extends->toString();
            }
            $ci = new ClassInfo($fqcn, $kind, $this->file, $node->getStartLine(), $parent, $this->project);
            if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Enum_) {
                foreach ($node->implements as $i) {
                    $ci->interfaces[] = $i->toString();
                    if ($this->project) {
                        $this->index->implementations[strtolower($i->toString())][] = $fqcn;
                    }
                }
            } elseif ($node instanceof Node\Stmt\Interface_) {
                foreach ($node->extends as $i) {
                    $ci->interfaces[] = $i->toString();
                }
            }
            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\TraitUse) {
                    foreach ($stmt->traits as $t) {
                        $ci->traits[] = $t->toString();
                    }
                }
            }
            // Facade docblocks: @method static ReturnType name(...)
            $doc = $node->getDocComment()?->getText();
            if ($doc !== null && preg_match_all('/@method\s+static\s+([^\s]+)\s+(\w+)\s*\(/', $doc, $m, PREG_SET_ORDER)) {
                foreach ($m as $mm) {
                    $rt = T::fromDoc($mm[1], $this->ns, $this->uses);
                    if ($rt !== null) {
                        $ci->facadeMethods[strtolower($mm[2])] = $rt;
                    }
                }
            }
            $this->index->classes[strtolower($fqcn)] = $ci;
            $this->stack[] = $ci;
        }
        if ($node instanceof Node\Stmt\Property && $this->stack) {
            $ci = end($this->stack);
            $type = T::fromNode($node->type);
            $source = $type !== null ? 'typed_property' : 'untyped';
            if ($type === null) {
                $doc = $node->getDocComment()?->getText();
                if ($doc !== null && preg_match('/@var\s+([^\s]+)/', $doc, $m)) {
                    $type = T::fromDoc($m[1], $this->ns, $this->uses);
                    $source = $type !== null ? 'docblock' : 'untyped';
                }
            }
            foreach ($node->props as $p) {
                $name = $p->name->toString();
                if (!isset($ci->props[$name]) || $ci->props[$name]['type'] === null) {
                    $ci->props[$name] = ['type' => $type, 'source' => $source];
                }
                // $casts and $dates declare which magic attributes Eloquent casts to Carbon.
                // These are runtime attributes, never real PHP properties, so this is the only
                // place their type can come from.
                if (($name === 'casts' || $name === 'dates') && $p->default instanceof Node\Expr\Array_) {
                    static $dateCasts = ['date', 'datetime', 'immutable_date', 'immutable_datetime', 'custom_datetime', 'immutable_custom_datetime'];
                    foreach ($p->default->items as $item) {
                        if ($item === null) {
                            continue;
                        }
                        if ($name === 'dates' && $item->value instanceof Node\Scalar\String_) {
                            $ci->props[$item->value->value] = ['type' => 'Carbon\Carbon', 'source' => 'cast'];
                            continue;
                        }
                        if ($name === 'casts' && $item->key instanceof Node\Scalar\String_ && $item->value instanceof Node\Scalar\String_) {
                            $castType = explode(':', $item->value->value, 2)[0];
                            if (in_array($castType, $dateCasts, true)) {
                                $isImmutable = str_starts_with($castType, 'immutable_');
                                $ci->props[$item->key->value] = ['type' => $isImmutable ? 'Carbon\CarbonImmutable' : 'Carbon\Carbon', 'source' => 'cast'];
                            }
                        }
                    }
                }
            }
        }
        if ($node instanceof Node\Stmt\ClassMethod && $this->stack) {
            $ci = end($this->stack);
            $params = [];
            foreach ($node->params as $p) {
                $pname = $p->var instanceof Node\Expr\Variable && is_string($p->var->name) ? $p->var->name : null;
                $ptype = T::fromNode($p->type);
                if ($ptype === null) {
                    $doc = $node->getDocComment()?->getText();
                    if ($doc !== null && $pname !== null && preg_match('/@param\s+([^\s]+)\s+\$' . preg_quote($pname, '/') . '\b/', $doc, $m)) {
                        $ptype = T::fromDoc($m[1], $this->ns, $this->uses);
                    }
                }
                if ($pname !== null) {
                    $params[$pname] = $ptype;
                    if ($p->flags !== 0) { // promoted constructor property
                        $ci->props[$pname] = ['type' => $ptype, 'source' => $ptype !== null ? 'promoted_property' : 'untyped'];
                    }
                }
            }
            $return = T::fromNode($node->returnType);
            if ($return === null) {
                $doc = $node->getDocComment()?->getText();
                if ($doc !== null && ($expr = T::extractReturnExpr($doc)) !== null) {
                    $return = T::fromDoc($expr, $this->ns, $this->uses);
                }
            }
            // Eloquent relation methods declare a return type like HasMany, which names the
            // relation shape but not the related model. The method body always calls the
            // relation builder with the related model as its first argument
            // (return $this->hasMany(Item::class)), possibly followed by further chaining
            // (->withDefault(), ->withTrashed()). Reading that argument gives the actual model,
            // which is what a caller chaining .where()/.get()/etc on this method actually needs.
            if ($node->stmts !== null) {
                static $relationKinds = [
                    'hasone' => 'one', 'belongsto' => 'one', 'morphone' => 'one', 'hasonethrough' => 'one',
                    'hasmany' => 'many', 'belongstomany' => 'many', 'morphmany' => 'many',
                    'morphtomany' => 'many', 'hasmanythrough' => 'many',
                ];
                $finder ??= new \PhpParser\NodeFinder();
                foreach ($finder->findInstanceOf($node->stmts, Node\Stmt\Return_::class) as $ret) {
                    if ($ret->expr === null) {
                        continue;
                    }
                    $rel = $this->findRelationCall($ret->expr, $relationKinds);
                    if ($rel !== null) {
                        $return = 'relation<' . $rel[0] . ',' . $rel[1] . '>';
                        break;
                    }
                }
            }
            $ci->methods[strtolower($node->name->toString())] = [
                'return' => $return,
                'static' => $node->isStatic(),
                'params' => $params,
            ];
            // Untyped property assigned from a typed constructor parameter: $this->x = $param;
            if (strtolower($node->name->toString()) === '__construct' && $node->stmts !== null) {
                $finder = new \PhpParser\NodeFinder();
                foreach ($finder->findInstanceOf($node->stmts, Node\Expr\Assign::class) as $as) {
                    if ($as->var instanceof Node\Expr\PropertyFetch
                        && $as->var->var instanceof Node\Expr\Variable && $as->var->var->name === 'this'
                        && $as->var->name instanceof Node\Identifier) {
                        $pn = $as->var->name->toString();
                        $rhs = $as->expr;
                        $t = null;
                        $src = null;
                        if ($rhs instanceof Node\Expr\Variable && is_string($rhs->name) && ($params[$rhs->name] ?? null) !== null) {
                            $t = $params[$rhs->name];
                            $src = 'constructor_assignment';
                        } elseif ($rhs instanceof Node\Expr\New_ && $rhs->class instanceof Node\Name) {
                            $t = $rhs->class->toString();
                            $src = 'constructor_assignment';
                        } elseif ($rhs instanceof Node\Expr\FuncCall && $rhs->name instanceof Node\Name
                            && in_array(strtolower($rhs->name->toString()), ['app', 'resolve'], true)
                            && isset($rhs->args[0]) && $rhs->args[0] instanceof Node\Arg
                            && $rhs->args[0]->value instanceof Node\Expr\ClassConstFetch
                            && $rhs->args[0]->value->class instanceof Node\Name) {
                            $t = $rhs->args[0]->value->class->toString();
                            $src = 'constructor_assignment';
                        }
                        if ($t !== null && (!isset($ci->props[$pn]) || $ci->props[$pn]['type'] === null)) {
                            $ci->props[$pn] = ['type' => $t, 'source' => $src];
                        }
                    }
                }
            }
        }
        if ($node instanceof Node\Stmt\Function_) {
            $name = isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
            $return = T::fromNode($node->returnType);
            if ($return === null) {
                $doc = $node->getDocComment()?->getText();
                if ($doc !== null && ($expr = T::extractReturnExpr($doc)) !== null) {
                    $return = T::fromDoc($expr, $this->ns, $this->uses);
                }
            }
            $this->index->functions[strtolower($name)] = $return;
        }
        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->stack);
        }
        return null;
    }

    /**
     * Descend a chain such as $this->hasMany(Item::class)->withDefault() looking for the
     * innermost call to one of the given relation methods on $this with a Foo::class first
     * argument. Returns [kind, targetFqcn] or null.
     *
     * @param array<string,string> $kinds
     * @return array{0:string,1:string}|null
     */
    private function findRelationCall(Node\Expr $e, array $kinds): ?array
    {
        while ($e instanceof Node\Expr\MethodCall) {
            if ($e->var instanceof Node\Expr\Variable && $e->var->name === 'this'
                && $e->name instanceof Node\Identifier
                && isset($kinds[strtolower($e->name->toString())])
                && isset($e->args[0]) && $e->args[0] instanceof Node\Arg
                && $e->args[0]->value instanceof Node\Expr\ClassConstFetch
                && $e->args[0]->value->class instanceof Node\Name) {
                return [$kinds[strtolower($e->name->toString())], $e->args[0]->value->class->toString()];
            }
            $e = $e->var;
        }
        return null;
    }
}

// ---------------------------------------------------------------------------------------------
// Pass 2: references
// ---------------------------------------------------------------------------------------------

final class ReferenceVisitor extends NodeVisitorAbstract
{
    /** @var string[] */
    private array $classStack = [];
    private ?string $method = null;
    /** @var array<string, array{0:?string,1:string,2:float}> */
    private array $scope = [];
    private ?string $ns = null;
    /** @var array<string,string> */
    private array $uses = [];
    public array $edges = [];

    public function __construct(private Index $index, private string $file)
    {
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->ns = $node->name?->toString();
            $this->uses = [];
        }
        if ($node instanceof Node\Stmt\Use_ && $node->type === Node\Stmt\Use_::TYPE_NORMAL) {
            foreach ($node->uses as $u) {
                $this->uses[$u->getAlias()->toString()] = $u->name->toString();
            }
        }
        if ($node instanceof Node\Stmt\ClassLike) {
            $fqcn = isset($node->namespacedName) ? $node->namespacedName->toString()
                : 'anonymous@' . $this->file . ':' . $node->getStartLine();
            $this->classStack[] = $fqcn;
        }
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->method = $node->name->toString();
            $this->scope = [];
            $this->addParams($node->params);
        }
        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $this->addParams($node->params);
        }
        if ($node instanceof Node\Stmt\Foreach_ && $node->valueVar instanceof Node\Expr\Variable && is_string($node->valueVar->name)) {
            // Iterating a builder/collection of a model yields the model (Laravel-aware, confidence 0.7).
            [$t] = $this->typeOf($node->expr);
            if ($t !== null && str_starts_with($t, 'builder<')) {
                $this->scope[$node->valueVar->name] = [substr($t, 8, -1), 'eloquent', 0.7];
            } elseif ($t !== null && str_starts_with($t, 'collection<')) {
                $this->scope[$node->valueVar->name] = [substr($t, 11, -1), 'eloquent', 0.7];
            }
        }
        if ($node instanceof Node\Stmt\Catch_ && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
            // catch (SomeException $e) types $e for the duration of the catch block.
            foreach ($node->types as $t) {
                $type = $this->resolveClassName($t->toString());
                if ($type !== null) {
                    $this->scope[$node->var->name] = [$type, 'catch', 1.0];
                    break;
                }
            }
        }
        if ($node instanceof Node\Stmt\Expression) {
            $doc = $node->getDocComment()?->getText();
            if ($doc !== null && preg_match('/@var\s+([^\s]+)\s+\$(\w+)/', $doc, $m)) {
                $t = T::fromDoc($m[1], $this->ns, $this->uses);
                if ($t !== null) {
                    $this->scope[$m[2]] = [$this->selfish($t), 'docblock', 0.7];
                }
            }
        }
        if ($node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
            [$t, $r, $c] = $this->typeOf($node->expr);
            if ($t !== null) {
                $this->scope[$node->var->name] = [$t, $r === 'new' ? 'local_new' : 'local_assign', min($c, $r === 'new' ? 1.0 : 0.8)];
            } elseif (!isset($this->scope[$node->var->name]) || true) {
                $this->scope[$node->var->name] = [null, 'unknown_assign', 0.0];
            }
        }

        if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
            $member = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
            $direct = $node->var instanceof Node\Expr\Variable && $node->var->name === 'this';
            [$t, $r, $c] = $this->typeOf($node->var);
            $this->record('call', $member, $t, $r, $c, $node->getStartLine(), $direct, $this->reason($node->var, $t));
        } elseif ($node instanceof Node\Expr\StaticCall) {
            $member = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
            if ($node->class instanceof Node\Name) {
                $cls = $this->resolveClassName($node->class->toString());
                $special = in_array(strtolower($node->class->toString()), ['self', 'static', 'parent'], true);
                $this->record('static', $member, $cls, $special ? strtolower($node->class->toString()) : 'static', 1.0, $node->getStartLine(), false, null);
            } else {
                $this->record('static', $member, null, 'unresolved', 0.0, $node->getStartLine(), false, 'dynamic_class');
            }
        } elseif ($node instanceof Node\Expr\New_) {
            if ($node->class instanceof Node\Name) {
                $this->record('new', null, $this->resolveClassName($node->class->toString()), 'new', 1.0, $node->getStartLine(), false, null);
            } elseif ($node->class instanceof Node\Stmt\Class_) {
                // anonymous class, handled as a declaration
            } else {
                $this->record('new', null, null, 'unresolved', 0.0, $node->getStartLine(), false, 'dynamic_class');
            }
        } elseif ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
            && in_array(strtolower($node->name->toString()), ['app', 'resolve'], true)
            && isset($node->args[0]) && $node->args[0] instanceof Node\Arg
            && $node->args[0]->value instanceof Node\Expr\ClassConstFetch
            && $node->args[0]->value->class instanceof Node\Name) {
            $this->record('resolve', null, $this->resolveClassName($node->args[0]->value->class->toString()), 'container', 1.0, $node->getStartLine(), false, null);
        }
        return null;
    }

    public function leaveNode(Node $node)
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

    private function addParams(array $params): void
    {
        $ci = $this->index->get($this->currentClass());
        $mi = $this->method !== null && $ci !== null ? ($ci->methods[strtolower($this->method)] ?? null) : null;
        foreach ($params as $p) {
            if ($p->var instanceof Node\Expr\Variable && is_string($p->var->name)) {
                $t = T::fromNode($p->type) ?? ($mi['params'][$p->var->name] ?? null);
                $this->scope[$p->var->name] = [$t !== null ? $this->selfish($t) : null, $t !== null ? 'param' : 'untyped_param', $t !== null ? 1.0 : 0.0];
            }
        }
    }

    private function currentClass(): ?string
    {
        return $this->classStack ? end($this->classStack) : null;
    }

    private function selfish(?string $t): ?string
    {
        if ($t === null) {
            return null;
        }
        $l = strtolower($t);
        if ($l === 'self' || $l === 'static') {
            return $this->currentClass();
        }
        if ($l === 'parent') {
            return $this->index->get($this->currentClass())?->parent;
        }
        return $t;
    }

    private function resolveClassName(string $name): ?string
    {
        $t = $this->selfish($name);
        // Laravel registers its built-in facades as global-namespace class aliases
        // (config/app.php `aliases`, loaded by Illuminate\Foundation\AliasLoader). Code written as
        // \DB::table(...) or a bare DB::table(...) with no `use` import and no App\DB class relies
        // on that runtime alias, which no static analysis of imports alone can see. This is a fixed,
        // documented list of Laravel's own default aliases, not a guess at project-specific naming.
        if ($t !== null && !str_contains($t, '\\') && $this->index->get($t) === null) {
            static $aliases = [
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
                'schema' => 'Illuminate\Support\Facades\Schema', 'session' => 'Illuminate\Support\Facades\Session',
                'storage' => 'Illuminate\Support\Facades\Storage', 'str' => 'Illuminate\Support\Str',
                'arr' => 'Illuminate\Support\Arr', 'url' => 'Illuminate\Support\Facades\URL',
                'validator' => 'Illuminate\Support\Facades\Validator', 'view' => 'Illuminate\Support\Facades\View',
                'vite' => 'Illuminate\Support\Facades\Vite',
            ];
            $alias = $aliases[strtolower($t)] ?? null;
            if ($alias !== null) {
                return $alias;
            }
        }
        return $t;
    }

    /** @return array{0:?string,1:string,2:float} [type, resolution, confidence] */
    private function typeOf(Node $e, int $depth = 0): array
    {
        if ($depth > 8) {
            return [null, 'unresolved', 0.0];
        }
        if ($e instanceof Node\Expr\Variable) {
            if ($e->name === 'this') {
                return [$this->currentClass(), 'this', 1.0];
            }
            if (is_string($e->name) && isset($this->scope[$e->name])) {
                return $this->scope[$e->name];
            }
            return [null, 'unresolved', 0.0];
        }
        if ($e instanceof Node\Expr\PropertyFetch || $e instanceof Node\Expr\NullsafePropertyFetch) {
            if (!$e->name instanceof Node\Identifier) {
                return [null, 'unresolved', 0.0];
            }
            [$ot, , $oc] = $this->typeOf($e->var, $depth + 1);
            if ($ot === null) {
                return [null, 'unresolved', 0.0];
            }
            $prop = $this->index->findProp($ot, $e->name->toString());
            if ($prop === null || $prop['type'] === null) {
                // Model relation accessed as property ($order->items): Eloquent's __get resolves
                // this to the loaded related model (belongsTo/hasOne/...) or collection
                // (hasMany/belongsToMany/...), never to the Relation/Builder object itself.
                if ($this->index->isModel($ot)) {
                    $m = $this->index->findMethod($ot, $e->name->toString());
                    if ($m !== null && $m[1]['return'] !== null && str_starts_with($m[1]['return'], 'relation<')) {
                        [$kind, $target] = explode(',', substr($m[1]['return'], 9, -1), 2);
                        return [$kind === 'many' ? 'collection<' . $target . '>' : $target, 'eloquent', min(0.7, $oc)];
                    }
                }
                return [null, 'unresolved', 0.0];
            }
            $conf = match ($prop['source']) {
                'typed_property', 'promoted_property', 'cast' => 1.0,
                'constructor_assignment' => 0.8,
                'docblock' => 0.7,
                default => 0.0,
            };
            return [$this->selfish($prop['type']), $prop['source'], min($conf, $oc)];
        }
        if ($e instanceof Node\Expr\New_) {
            if ($e->class instanceof Node\Name) {
                return [$this->resolveClassName($e->class->toString()), 'new', 1.0];
            }
            return [null, 'unresolved', 0.0];
        }
        if ($e instanceof Node\Expr\StaticCall) {
            if (!$e->class instanceof Node\Name || !$e->name instanceof Node\Identifier) {
                return [null, 'unresolved', 0.0];
            }
            $cls = $this->resolveClassName($e->class->toString());
            $m = $e->name->toString();
            return $this->returnOf($cls, $m, 1.0, true);
        }
        if ($e instanceof Node\Expr\MethodCall || $e instanceof Node\Expr\NullsafeMethodCall) {
            if (!$e->name instanceof Node\Identifier) {
                return [null, 'unresolved', 0.0];
            }
            [$rt, , $rc] = $this->typeOf($e->var, $depth + 1);
            if ($rt === null) {
                return [null, 'unresolved', 0.0];
            }
            return $this->returnOf($rt, $e->name->toString(), $rc, false);
        }
        if ($e instanceof Node\Expr\FuncCall && $e->name instanceof Node\Name) {
            $fn = strtolower($e->name->toString());
            if (in_array($fn, ['app', 'resolve'], true)) {
                if (isset($e->args[0]) && $e->args[0] instanceof Node\Arg && $e->args[0]->value instanceof Node\Expr\ClassConstFetch
                    && $e->args[0]->value->class instanceof Node\Name) {
                    return [$this->resolveClassName($e->args[0]->value->class->toString()), 'container', 1.0];
                }
                if (!isset($e->args[0])) {
                    return ['Illuminate\Foundation\Application', 'container', 1.0];
                }
                return [null, 'unresolved', 0.0];
            }
            $rt = $this->index->functions[$fn] ?? null;
            return $rt !== null ? [$rt, 'return_type', 0.8] : [null, 'unresolved', 0.0];
        }
        if ($e instanceof Node\Expr\Ternary) {
            $a = $this->typeOf($e->if ?? $e->cond, $depth + 1);
            return $a[0] !== null ? $a : $this->typeOf($e->else, $depth + 1);
        }
        if ($e instanceof Node\Expr\BinaryOp\Coalesce) {
            $a = $this->typeOf($e->left, $depth + 1);
            return $a[0] !== null ? $a : $this->typeOf($e->right, $depth + 1);
        }
        if ($e instanceof Node\Expr\Assign) {
            return $this->typeOf($e->expr, $depth + 1);
        }
        if ($e instanceof Node\Expr\Cast\Object_) {
            return ['stdClass', 'cast', 1.0];
        }
        if ($e instanceof Node\Expr\Clone_) {
            return $this->typeOf($e->expr, $depth + 1);
        }
        return [null, 'unresolved', 0.0];
    }

    /** Return type of $cls::$m or $cls->$m, with Eloquent and facade rules. */
    private function returnOf(?string $cls, string $m, float $inConf, bool $static): array
    {
        if ($cls === null) {
            return [null, 'unresolved', 0.0];
        }
        $lm = strtolower($m);
        // Eloquent relation methods (hasMany/belongsTo/...) return a Relation subclass that uses
        // ForwardsCalls to proxy any undefined method to the query builder of the related model
        // (documented Eloquent behaviour, e.g. $order->items()->where(...)). A short list of
        // relation-specific mutators (attach, sync, associate...) do not return a builder at all.
        if (str_starts_with($cls, 'relation<')) {
            [$relKind, $relTarget] = explode(',', substr($cls, 9, -1), 2);
            static $relationMutators = [
                'save', 'savemany', 'attach', 'detach', 'sync', 'syncwithoutdetaching',
                'togglewithoutdetaching', 'toggle', 'associate', 'dissociate',
                'updateexistingpivot', 'createmany', 'createmanyquietly',
            ];
            if (in_array($lm, $relationMutators, true)) {
                return [null, 'scalar', 0.0];
            }
            $cls = 'builder<' . $relTarget . '>';
        }
        // Eloquent builder chains
        if (str_starts_with($cls, 'builder<')) {
            $model = substr($cls, 8, -1);
            if (in_array($lm, ['first', 'firstorfail', 'find', 'findorfail', 'create', 'firstorcreate', 'updateorcreate', 'firstornew', 'make', 'sole', 'findornew', 'forcecreate', 'findorfail', 'firstwhere', 'latest', 'oldest'], true)) {
                // latest/oldest return builder; keep them in the builder branch below
                if (!in_array($lm, ['latest', 'oldest'], true)) {
                    return [$model, 'eloquent', min($inConf, 0.7)];
                }
            }
            if (in_array($lm, ['get', 'all', 'cursor', 'lazy', 'paginate', 'simplepaginate', 'cursorpaginate', 'pluck', 'chunk', 'each'], true)) {
                return [in_array($lm, ['get', 'all', 'cursor', 'lazy'], true) ? 'collection<' . $model . '>' : 'Illuminate\Contracts\Pagination\LengthAwarePaginator', 'eloquent', min($inConf, 0.7)];
            }
            if (in_array($lm, ['count', 'sum', 'max', 'min', 'avg', 'exists', 'doesntexist', 'value', 'tosql', 'update', 'delete', 'insert'], true)) {
                return [null, 'scalar', 0.0];
            }
            return [$cls, 'eloquent', min($inConf, 0.7)];
        }
        if (str_starts_with($cls, 'collection<')) {
            $model = substr($cls, 11, -1);
            if (in_array($lm, ['first', 'last', 'firstwhere', 'pop', 'shift', 'find', 'sole', 'firstorfail', 'random'], true)) {
                return [$model, 'eloquent', min($inConf, 0.7)];
            }
            if (in_array($lm, ['filter', 'where', 'wherein', 'sortby', 'sortbydesc', 'reject', 'unique', 'values', 'take', 'skip', 'each', 'load', 'reverse', 'whereinstanceof', 'wherenotnull', 'slice', 'merge'], true)) {
                return [$cls, 'eloquent', min($inConf, 0.7)];
            }
            return ['Illuminate\Support\Collection', 'eloquent', min($inConf, 0.7)];
        }
        $found = $this->index->findMethod($cls, $m);
        if ($found !== null) {
            [$owner, $mi] = $found;
            $rt = $mi['return'];
            if ($rt !== null) {
                $l = strtolower($rt);
                if ($l === 'static' || $l === 'self' || $l === '$this') {
                    $rt = $cls;
                } elseif ($l === 'parent') {
                    $rt = $this->index->get($owner)?->parent;
                }
                if ($rt !== null && $this->index->isModel($cls) && in_array(strtolower($rt), ['illuminate\database\eloquent\builder', 'illuminate\database\eloquent\relations\relation'], true)) {
                    return ['builder<' . $cls . '>', 'eloquent', min($inConf, 0.7)];
                }
                return [$rt, 'return_type', min($inConf, 0.8)];
            }
            if ($this->index->isModel($cls) && str_starts_with($lm, 'scope')) {
                return ['builder<' . $cls . '>', 'eloquent', min($inConf, 0.7)];
            }
            return [null, 'unresolved', 0.0];
        }
        if ($this->index->isModel($cls)) {
            // scope call ($model->active() / Model::active()) or forwarded builder method (where, query, with...)
            if ($this->index->findMethod($cls, 'scope' . $m) !== null || true) {
                if (in_array($lm, ['first', 'find', 'findorfail', 'firstorfail', 'create', 'firstorcreate', 'updateorcreate', 'make', 'fresh', 'refresh', 'load', 'loadmissing', 'replicate', 'setrelation', 'fill', 'forcefill', 'newinstance', 'findornew', 'firstornew'], true)) {
                    return [$cls, 'eloquent', min($inConf, 0.7)];
                }
                if (in_array($lm, ['get', 'all', 'paginate', 'pluck', 'count', 'sum', 'exists', 'save', 'delete', 'update', 'tojson', 'toarray', 'getkey', 'getattribute', 'setattribute', 'increment', 'decrement', 'touch', 'push', 'saveorfail', 'forcedelete', 'restore', 'trashed'], true)) {
                    return [null, 'scalar', 0.0];
                }
                return ['builder<' . $cls . '>', 'eloquent', min($inConf, 0.7)];
            }
        }
        $ci = $this->index->get($cls);
        if ($ci !== null && $static && isset($ci->facadeMethods[$lm])) {
            return [$this->selfish($ci->facadeMethods[$lm]), 'facade', min($inConf, 0.8)];
        }
        // PHP's Throwable interface guarantees these methods on any caught exception, whether or
        // not the concrete exception class is indexed (built-in SPL classes have no source to parse).
        static $throwableScalars = ['getmessage', 'getcode', 'getfile', 'getline', 'gettraceasstring', '__tostring', 'gettrace'];
        if (!$static && in_array($lm, $throwableScalars, true)) {
            return [null, 'scalar', 0.0];
        }
        if (!$static && $lm === 'getprevious') {
            return ['Throwable', 'throwable', min($inConf, 1.0)];
        }
        // Documented Laravel-specific quirk: a handful of manager/factory classes forward
        // undeclared method calls via __call() to a runtime-resolved sub-contract. This is not
        // a generic heuristic, it is the known behaviour of these specific framework classes.
        static $forwards = [
            'illuminate\contracts\auth\factory' => 'Illuminate\Contracts\Auth\Guard',
        ];
        $forward = $forwards[strtolower($cls)] ?? null;
        if ($forward !== null) {
            $r = $this->returnOf($forward, $m, $inConf, $static);
            if ($r[0] !== null || $r[1] === 'scalar') {
                return [$r[0], $r[1] === 'return_type' ? 'return_type' : $r[1], min($r[2], 0.8)];
            }
        }
        if ($ci !== null && !$ci->project) {
            return [null, 'external_unknown', 0.0];
        }
        return [null, 'unresolved', 0.0];
    }

    private function reason(Node $receiver, ?string $t): ?string
    {
        if ($t !== null) {
            return null;
        }
        if ($receiver instanceof Node\Expr\Variable) {
            if (!is_string($receiver->name)) {
                return 'dynamic_variable';
            }
            $s = $this->scope[$receiver->name] ?? null;
            return $s === null ? 'unknown_variable' : ($s[1] === 'untyped_param' ? 'untyped_param' : 'untyped_local');
        }
        if ($receiver instanceof Node\Expr\PropertyFetch || $receiver instanceof Node\Expr\NullsafePropertyFetch) {
            if ($receiver->var instanceof Node\Expr\Variable && $receiver->var->name === 'this') {
                $name = $receiver->name instanceof Node\Identifier ? $receiver->name->toString() : null;
                $p = $name !== null ? $this->index->findProp($this->currentClass(), $name) : null;
                return $p === null ? 'unknown_property' : 'untyped_property';
            }
            return 'property_chain';
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
        return 'other:' . $receiver->getType();
    }

    private function record(string $kind, ?string $member, ?string $type, string $resolution, float $conf, int $line, bool $direct, ?string $reason): void
    {
        $scopeOf = null;
        if ($type !== null) {
            $base = $type;
            if (str_starts_with($type, 'builder<') || str_starts_with($type, 'collection<')) {
                $base = substr($type, strpos($type, '<') + 1, -1);
            } elseif (str_starts_with($type, 'relation<')) {
                $base = explode(',', substr($type, 9, -1), 2)[1] ?? '';
            }
            $scopeOf = $this->index->isProject($base) ? 'project' : ($this->index->get($base) !== null ? 'external' : 'unknown_class');
        }
        $this->edges[] = [
            'file' => $this->file,
            'line' => $line,
            'from' => ($this->currentClass() ?? '(none)') . ($this->method !== null ? '::' . $this->method : ''),
            'kind' => $kind,
            'member' => $member,
            'type' => $type,
            'resolution' => $resolution,
            'confidence' => $conf,
            'scope' => $scopeOf,
            'direct_this' => $direct,
            'reason' => $reason ?? ($member === null && $kind === 'call' ? 'dynamic_member' : null),
        ];
    }
}

// ---------------------------------------------------------------------------------------------
// Driver
// ---------------------------------------------------------------------------------------------

function phpFiles(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $out[] = $f->getPathname();
        }
    }
    sort($out);
    return $out;
}

$parser = (new ParserFactory())->createForNewestSupportedVersion();
$index = new Index();
$t0 = microtime(true);
$parseErrors = [];

$sets = [['dir' => $root . '/app', 'project' => true]];
if ($opts['vendor']) {
    $sets[] = ['dir' => $root . '/vendor/laravel/framework/src/Illuminate', 'project' => false];
    // Carbon return types are almost entirely fluent ("static"), and date chains
    // (now()->startOfDay()->...) are extremely common across a Laravel codebase.
    if (is_dir($root . '/vendor/nesbot/carbon/src')) {
        $sets[] = ['dir' => $root . '/vendor/nesbot/carbon/src', 'project' => false];
    }
}
foreach ($opts['extraVendor'] as $rel) {
    $sets[] = ['dir' => $root . '/' . trim($rel, '/'), 'project' => false];
}
$asts = [];
foreach ($sets as $set) {
    foreach (phpFiles($set['dir']) as $path) {
        $rel = substr($path, strlen($root) + 1);
        try {
            $stmts = $parser->parse((string) file_get_contents($path));
        } catch (\PhpParser\Error $e) {
            $parseErrors[] = $rel . ': ' . $e->getMessage();
            continue;
        }
        if ($stmts === null) {
            continue;
        }
        $tr = new NodeTraverser();
        $tr->addVisitor(new NameResolver());
        $tr->addVisitor(new DeclarationVisitor($index, $rel, $set['project']));
        $stmts = $tr->traverse($stmts);
        if ($set['project']) {
            $asts[$rel] = $stmts;
        }
    }
}
$t1 = microtime(true);

$edges = [];
foreach ($asts as $rel => $stmts) {
    $v = new ReferenceVisitor($index, $rel);
    $tr = new NodeTraverser();
    $tr->addVisitor($v);
    $tr->traverse($stmts);
    foreach ($v->edges as $e) {
        $edges[] = $e;
    }
}
$t2 = microtime(true);

// ---------------------------------------------------------------------------------------------
// Metrics
// ---------------------------------------------------------------------------------------------

$calls = array_values(array_filter($edges, fn ($e) => $e['kind'] === 'call'));
$hard = array_values(array_filter($calls, fn ($e) => !$e['direct_this']));
$resolvedHi = array_filter($hard, fn ($e) => $e['type'] !== null && $e['confidence'] >= 0.8);
$resolvedMid = array_filter($hard, fn ($e) => $e['type'] !== null && $e['confidence'] >= 0.6 && $e['confidence'] < 0.8);
$unres = array_filter($hard, fn ($e) => $e['type'] === null);
$byRes = [];
foreach ($hard as $e) {
    $byRes[$e['resolution']] = ($byRes[$e['resolution']] ?? 0) + 1;
}
arsort($byRes);
$byScope = [];
foreach ($hard as $e) {
    $k = $e['type'] === null ? 'unresolved' : $e['scope'];
    $byScope[$k] = ($byScope[$k] ?? 0) + 1;
}
$reasons = [];
foreach ($unres as $e) {
    $reasons[$e['reason'] ?? 'unknown'] = ($reasons[$e['reason'] ?? 'unknown'] ?? 0) + 1;
}
arsort($reasons);
$unresMembers = [];
foreach ($unres as $e) {
    $unresMembers[$e['member'] ?? '(dynamic)'] = ($unresMembers[$e['member'] ?? '(dynamic)'] ?? 0) + 1;
}
arsort($unresMembers);

$projectCallsHi = array_filter($hard, fn ($e) => $e['scope'] === 'project' && $e['confidence'] >= 0.8);
$projectCallsAll = array_filter($hard, fn ($e) => $e['scope'] === 'project');

$statics = array_filter($edges, fn ($e) => $e['kind'] === 'static');
$news = array_filter($edges, fn ($e) => $e['kind'] === 'new');
$resolves = array_filter($edges, fn ($e) => $e['kind'] === 'resolve');

$checks = [];
foreach ($opts['check'] as $short) {
    $hits = [];
    foreach ($edges as $e) {
        $t = $e['type'];
        if ($t === null) {
            continue;
        }
        $base = $t;
        if (str_starts_with($t, 'builder<') || str_starts_with($t, 'collection<')) {
            $base = substr($t, strpos($t, '<') + 1, -1);
        }
        if (strtolower(substr($base, -strlen($short) - 1)) === strtolower('\\' . $short) || strtolower($base) === strtolower($short)) {
            $hits[] = ['from' => $e['from'], 'kind' => $e['kind'], 'member' => $e['member'], 'resolution' => $e['resolution'], 'confidence' => $e['confidence'], 'line' => $e['file'] . ':' . $e['line']];
        }
    }
    // Constructor injections: classes whose props/params carry the type
    $injections = [];
    foreach ($index->classes as $ci) {
        if (!$ci->project) {
            continue;
        }
        foreach ($ci->props as $pn => $p) {
            if ($p['type'] !== null && (strtolower(substr($p['type'], -strlen($short) - 1)) === strtolower('\\' . $short))) {
                $injections[] = ['class' => $ci->fqcn, 'property' => $pn, 'source' => $p['source']];
            }
        }
        foreach ($ci->methods as $mn => $mi) {
            foreach ($mi['params'] as $pn => $pt) {
                if ($pt !== null && strtolower(substr($pt, -strlen($short) - 1)) === strtolower('\\' . $short)) {
                    $injections[] = ['class' => $ci->fqcn, 'method' => $mn, 'param' => $pn, 'kind' => $mn === '__construct' ? 'constructor_injection' : 'method_injection'];
                }
            }
        }
    }
    $checks[$short] = ['edges' => $hits, 'edge_count' => count($hits), 'injections' => $injections];
}

$projectClasses = count(array_filter($index->classes, fn ($c) => $c->project));
$summary = [
    'files_parsed' => count($asts),
    'parse_errors' => $parseErrors,
    'project_classes' => $projectClasses,
    'vendor_indexed' => $opts['vendor'],
    'timing_seconds' => ['declarations' => round($t1 - $t0, 2), 'references' => round($t2 - $t1, 2), 'total' => round($t2 - $t0, 2)],
    'method_calls' => [
        'total' => count($calls),
        'direct_this' => count($calls) - count($hard),
        'hard' => count($hard),
        'hard_resolved_conf_ge_0.8' => count($resolvedHi),
        'hard_resolved_conf_0.6_to_0.8' => count($resolvedMid),
        'hard_unresolved' => count($unres),
        'ratio_resolved_ge_0.8' => count($hard) ? round(count($resolvedHi) / count($hard), 3) : null,
        'ratio_resolved_ge_0.6' => count($hard) ? round((count($resolvedHi) + count($resolvedMid)) / count($hard), 3) : null,
        'by_resolution' => $byRes,
        'by_receiver_scope' => $byScope,
        'project_receiver_calls' => ['total' => count($projectCallsAll), 'conf_ge_0.8' => count($projectCallsHi)],
        'unresolved_reasons' => $reasons,
        'unresolved_top_members' => array_slice($unresMembers, 0, 25, true),
    ],
    'static_calls' => ['total' => count($statics), 'project_target' => count(array_filter($statics, fn ($e) => $e['scope'] === 'project'))],
    'instantiations' => ['total' => count($news), 'resolved' => count(array_filter($news, fn ($e) => $e['type'] !== null)), 'project_target' => count(array_filter($news, fn ($e) => $e['scope'] === 'project'))],
    'container_resolves' => count($resolves),
    'checks' => $checks,
];

if ($opts['dumpFunctions']) {
    fwrite(STDERR, "== function return types ==\n");
    foreach ($opts['dumpFunctions'] as $fn) {
        $fn = strtolower(trim($fn));
        fwrite(STDERR, sprintf("  %-15s => %s\n", $fn, $index->functions[$fn] ?? '(not indexed)'));
    }
}
if ($opts['dumpClass'] !== null) {
    $ci = $index->get($opts['dumpClass']);
    fwrite(STDERR, "== class {$opts['dumpClass']} ==\n");
    if ($ci === null) {
        fwrite(STDERR, "  not indexed\n");
    } else {
        fwrite(STDERR, "  parent: " . ($ci->parent ?? '(none)') . "\n");
        fwrite(STDERR, "  interfaces: " . implode(', ', $ci->interfaces) . "\n");
        foreach (['route', 'back', 'to', 'user', 'guard'] as $m) {
            if (isset($ci->methods[$m])) {
                fwrite(STDERR, sprintf("  method %-8s return=%s\n", $m, $ci->methods[$m]['return'] ?? '(untyped)'));
            }
        }
    }
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
if ($opts['out'] !== null) {
    file_put_contents($opts['out'], json_encode(['summary' => $summary, 'edges' => $edges], JSON_UNESCAPED_SLASHES));
    fwrite(STDERR, "edges written to {$opts['out']}\n");
}
