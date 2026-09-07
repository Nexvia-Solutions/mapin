<?php

declare(strict_types=1);

namespace Mapin\Extract;

/**
 * The complete cross-file map of classes, methods and functions that resolution needs. Built once
 * per build (full or incremental) by merging freshly extracted ClassMeta for changed files with
 * ClassMeta hydrated from previously stored nodes for everything else - see SPEC.md section 5 on
 * why an incremental build must not re-parse unchanged files to get this.
 */
final class SymbolIndex
{
    /** @var array<string, ClassMeta> keyed by lowercase FQCN */
    private array $classes = [];

    /** @var array<string, ?string> lowercase function name => return type */
    private array $functions = [];

    /** @var array<string, string[]> lowercase interface FQCN => implementing project FQCNs */
    private array $implementations = [];

    /** @var array<string, string> lowercase abstract FQCN => bound concrete FQCN, from the booted container */
    private array $bindings = [];

    private bool $tracking = false;

    /** @var array<string,true> lowercase FQCN, or "function:name", touched since the last startTracking() */
    private array $reads = [];

    /**
     * Starts recording every symbol this index is asked about, so the caller can attribute a
     * dependency from whatever it is currently resolving to each one - see SqliteStore's
     * symbol_deps table and BuildRunner's affected-file detection.
     */
    public function startTracking(): void
    {
        $this->tracking = true;
        $this->reads = [];
    }

    /** @return string[] symbols touched since startTracking(), deduplicated */
    public function stopTracking(): array
    {
        $this->tracking = false;
        $reads = array_keys($this->reads);
        $this->reads = [];

        return $reads;
    }

    private function track(?string $symbol): void
    {
        if ($this->tracking && $symbol !== null) {
            $this->reads[strtolower($symbol)] = true;
        }
    }

    public function addClass(ClassMeta $class): void
    {
        $this->classes[strtolower($class->fqcn)] = $class;
        if ($class->project) {
            foreach ($class->interfaces as $interface) {
                $this->implementations[strtolower($interface)][] = $class->fqcn;
            }
        }
    }

    public function addFunction(string $name, ?string $returnType): void
    {
        $this->functions[strtolower($name)] = $returnType;
    }

    public function functionReturn(string $name): ?string
    {
        $this->track('function:'.strtolower($name));

        return $this->functions[strtolower($name)] ?? null;
    }

    public function get(?string $fqcn): ?ClassMeta
    {
        $this->track($fqcn);

        return $fqcn === null ? null : ($this->classes[strtolower($fqcn)] ?? null);
    }

    public function has(string $fqcn): bool
    {
        $this->track($fqcn);

        return isset($this->classes[strtolower($fqcn)]);
    }

    /**
     * The one project class implementing this interface, or null if there is none or more than
     * one. Tracked on the interface itself: a new or removed implementation elsewhere changes this
     * answer even though the interface's own declaration did not change.
     */
    public function singleImplementation(string $interfaceFqcn): ?string
    {
        $this->track($interfaceFqcn);
        $implementations = $this->implementations[strtolower($interfaceFqcn)] ?? [];

        return count($implementations) === 1 ? $implementations[0] : null;
    }

    public function addBinding(string $abstract, string $concrete): void
    {
        $this->bindings[strtolower($abstract)] = $concrete;
    }

    /** The booted container's binding for this abstract, if any (SPEC.md 4.1 rule 4, preferred over singleImplementation()). */
    public function binding(string $abstractFqcn): ?string
    {
        $this->track($abstractFqcn);

        return $this->bindings[strtolower($abstractFqcn)] ?? null;
    }

    /** @return array{0: string, 1: array{name: string, return: ?string, static: bool, params: array<string,?string>}}|null */
    public function findMethod(?string $fqcn, string $name, int $depth = 0): ?array
    {
        $class = $this->get($fqcn);
        if ($class === null || $depth > 15) {
            return null;
        }
        $lower = strtolower($name);
        if (isset($class->methods[$lower])) {
            return [$class->fqcn, $class->methods[$lower]];
        }
        foreach ($class->traits as $trait) {
            if ($found = $this->findMethod($trait, $name, $depth + 1)) {
                return $found;
            }
        }
        if ($class->parent !== null && ($found = $this->findMethod($class->parent, $name, $depth + 1))) {
            return $found;
        }
        foreach ($class->interfaces as $interface) {
            if ($found = $this->findMethod($interface, $name, $depth + 1)) {
                return $found;
            }
        }

        return null;
    }

    /** @return array{type: ?string, source: string}|null */
    public function findProp(?string $fqcn, string $name, int $depth = 0): ?array
    {
        $class = $this->get($fqcn);
        if ($class === null || $depth > 15) {
            return null;
        }
        if (isset($class->props[$name])) {
            return $class->props[$name];
        }
        foreach ($class->traits as $trait) {
            if ($found = $this->findProp($trait, $name, $depth + 1)) {
                return $found;
            }
        }
        if ($class->parent !== null && ($found = $this->findProp($class->parent, $name, $depth + 1))) {
            return $found;
        }
        // Eloquent casts created_at/updated_at to Carbon by default on every model, and deleted_at
        // when the model uses SoftDeletes, even with no explicit $casts entry (SPEC.md 4.1 rule 12).
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
        if (in_array(strtolower($fqcn), [
            'illuminate\database\eloquent\model',
            'illuminate\foundation\auth\user',
            'illuminate\database\eloquent\relations\pivot',
        ], true)) {
            return true;
        }
        $class = $this->get($fqcn);

        return $class !== null && $class->parent !== null && $this->isModel($class->parent, $depth + 1);
    }

    public function usesSoftDeletes(?string $fqcn, int $depth = 0): bool
    {
        if ($fqcn === null || $depth > 15) {
            return false;
        }
        $class = $this->get($fqcn);
        if ($class === null) {
            return false;
        }
        foreach ($class->traits as $trait) {
            if (str_ends_with(strtolower($trait), '\softdeletes')) {
                return true;
            }
        }

        return $class->parent !== null && $this->usesSoftDeletes($class->parent, $depth + 1);
    }

    public function isInterface(?string $fqcn): bool
    {
        return $this->get($fqcn)?->kind === 'interface';
    }

    public function isProject(?string $fqcn): bool
    {
        return $this->get($fqcn)?->project ?? false;
    }
}
