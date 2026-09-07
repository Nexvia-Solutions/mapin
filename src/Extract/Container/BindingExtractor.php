<?php

declare(strict_types=1);

namespace Mapin\Extract\Container;

use Mapin\Extract\Contracts\ExtractionContext;
use Mapin\Extract\Contracts\Fragment;
use Mapin\Extract\SymbolIndex;
use Mapin\Graph\Edge;
use Mapin\Graph\EdgeType;
use Mapin\Graph\Key;

/**
 * Reads the booted container's binding table (SPEC.md 4.1 rule 4: "if the container has a
 * binding, use it"). Only bindings whose concrete side is a plain class name are captured - a
 * binding built from a genuinely custom closure has no statically discoverable target, and running
 * the closure to find out would mean executing arbitrary application code during a build, which
 * Mapin never does.
 *
 * `Container::bind()` always wraps its $concrete argument in a closure internally
 * (`getClosure($abstract, $concrete)`), even when the caller passed a plain class-name string -
 * `getBindings()` therefore never returns a bare string for the common `bind(Interface::class,
 * Concrete::class)` case, only for the rarer case of a binding registered directly against the
 * internal array. The class name is still recoverable without executing anything: Laravel's own
 * wrapper closure captures it as a `use ($abstract, $concrete)` variable, which
 * ReflectionFunction::getStaticVariables() reads back without calling the closure.
 */
final class BindingExtractor
{
    public function requiresBoot(): bool
    {
        return true;
    }

    public function extract(ExtractionContext $ctx, SymbolIndex $index): Fragment
    {
        if ($ctx->container === null) {
            return new Fragment;
        }

        $edges = [];
        foreach ($ctx->container->getBindings() as $abstract => $binding) {
            $concrete = $this->concreteClassName($binding['concrete'] ?? null);
            if ($concrete === null || $concrete === $abstract) {
                continue;
            }
            $abstract = ltrim($abstract, '\\');
            $concrete = ltrim($concrete, '\\');
            if (! $index->has($abstract) || ! $index->has($concrete)) {
                continue;
            }
            $index->addBinding($abstract, $concrete);
            $edges[] = new Edge(
                EdgeType::Binds,
                Key::classLike($abstract),
                Key::classLike($concrete),
                meta: ['singleton' => (bool) ($binding['shared'] ?? false)],
            );
        }

        return new Fragment(edges: $edges);
    }

    private function concreteClassName(mixed $concrete): ?string
    {
        if (is_string($concrete)) {
            return $concrete;
        }
        if (! $concrete instanceof \Closure) {
            return null;
        }
        $vars = (new \ReflectionFunction($concrete))->getStaticVariables();

        return isset($vars['abstract'], $vars['concrete']) && is_string($vars['concrete']) ? $vars['concrete'] : null;
    }
}
