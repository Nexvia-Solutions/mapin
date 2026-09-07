<?php

declare(strict_types=1);

namespace Mapin\Extract\Routes;

use Illuminate\Routing\Route as IlluminateRoute;
use Mapin\Extract\Contracts\ExtractionContext;
use Mapin\Extract\Contracts\Fragment;
use Mapin\Extract\SymbolIndex;
use Mapin\Graph\Edge;
use Mapin\Graph\EdgeType;
use Mapin\Graph\Key;
use Mapin\Graph\Node;
use Mapin\Graph\NodeType;

/**
 * Reads the booted router's route collection directly - not a per-file extractor like PhpExtractor
 * (SPEC.md's own extractor table lists its input as "booted router", not a set of files). Cheap
 * enough (iterating already-registered Route objects, no parsing) that BuildRunner re-runs it on
 * every build a booted context is available for, rather than trying to hash-diff it.
 */
final class RouteExtractor
{
    public function requiresBoot(): bool
    {
        return true;
    }

    public function extract(ExtractionContext $ctx, SymbolIndex $index): Fragment
    {
        if ($ctx->router === null) {
            return new Fragment;
        }

        $routes = $ctx->router->getRoutes();
        if (! $routes instanceof \Traversable) {
            // The interface does not require it, but every real Laravel router returns a
            // RouteCollection, which does (Countable, IteratorAggregate) - this is a real, cheap
            // safety check, not a formality to satisfy static analysis.
            return new Fragment;
        }

        $nodes = [];
        $edges = [];
        $middlewareAliases = $ctx->router->getMiddleware();

        foreach ($routes as $route) {
            /** @var IlluminateRoute $route */
            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            $method = $methods[0] ?? $route->methods()[0] ?? 'GET';
            $uri = $route->uri();
            $routeKey = Key::route($method, $uri);

            $action = $route->getActionName();
            $middleware = $route->gatherMiddleware();

            $nodes[] = new Node(NodeType::Route, $method.' /'.ltrim($uri, '/'), $routeKey, meta: [
                'name' => $route->getName(),
                'action' => $action,
                'middleware' => $middleware,
                'domain' => $route->getDomain(),
                'methods' => $methods,
            ]);

            $target = $this->controllerTarget($action, $index);
            if ($target !== null) {
                $edges[] = new Edge(EdgeType::RoutesTo, $routeKey, $target);
            }

            foreach ($middleware as $alias) {
                if (! is_string($alias)) {
                    // Route::middleware() also accepts a Closure or a middleware object directly,
                    // not just registered string aliases - real code does this (found while
                    // validating against a real application's full route table, not the fixture
                    // app, which only ever exercised string aliases). Neither has a name stable
                    // across builds to key a graph node on, so there is nothing meaningful to record.
                    continue;
                }
                $resolvedClass = $middlewareAliases[$alias] ?? (str_contains($alias, ':') ? ($middlewareAliases[explode(':', $alias, 2)[0]] ?? null) : null);
                $middlewareKey = Key::middleware($alias);
                $nodes[] = new Node(NodeType::Middleware, $alias, $middlewareKey, meta: array_filter(['class' => $resolvedClass]));
                $edges[] = new Edge(EdgeType::UsesMiddleware, $routeKey, $middlewareKey);
            }
        }

        return new Fragment($nodes, $edges);
    }

    private function controllerTarget(string $action, SymbolIndex $index): ?string
    {
        if ($action === 'Closure') {
            return null;
        }
        if (str_contains($action, '@')) {
            [$controller, $method] = explode('@', $action, 2);
            $controller = ltrim($controller, '\\');
        } else {
            // An invokable controller: Route::get(..., SomeController::class) with no explicit
            // method - Laravel calls __invoke() on it, and getActionName() reports just the bare
            // class name here, the same shape a Closure action can take in other Laravel versions
            // (ruled out above) - isProject()+findMethod() below reject anything that isn't a real
            // project class with an __invoke method, so a genuine Closure never falls through here.
            $controller = ltrim($action, '\\');
            $method = '__invoke';
        }
        if (! $index->isProject($controller)) {
            return null;
        }
        $found = $index->findMethod($controller, $method);
        if ($found === null || ! $index->isProject($found[0])) {
            return null;
        }

        return Key::method($found[0], $found[1]['name']);
    }
}
