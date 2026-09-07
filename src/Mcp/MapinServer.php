<?php

declare(strict_types=1);

namespace Mapin\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;
use Mapin\Mcp\Adapters\CalleesToolAdapter;
use Mapin\Mcp\Adapters\CallersToolAdapter;
use Mapin\Mcp\Adapters\FindToolAdapter;
use Mapin\Mcp\Adapters\ImpactToolAdapter;
use Mapin\Mcp\Adapters\ModelToolAdapter;
use Mapin\Mcp\Adapters\NodeToolAdapter;
use Mapin\Mcp\Adapters\PathToolAdapter;
use Mapin\Mcp\Adapters\RouteToolAdapter;
use Mapin\Mcp\Adapters\StatsToolAdapter;
use Mapin\Mcp\Adapters\UnresolvedToolAdapter;
use Mapin\Mcp\Adapters\ViewToolAdapter;

/**
 * hubs, communities and docs are not registered here: they need phase 4/5 data (Markdown module,
 * community detection) that does not exist yet - SPEC.md section 1.6 tracks this, not silence.
 */
final class MapinServer extends Server
{
    protected string $name = 'Mapin';

    protected string $instructions = <<<'MARKDOWN'
        Mapin is a queryable code graph for this Laravel application: dependency injection, container
        bindings, routes, views, Eloquent relations, and how they connect. Every tool answers with
        `found: false` and ranked `suggestions` rather than a guess when nothing matches exactly - do
        not treat a suggestion as the answer. Every response also carries a `graph` block reporting how
        stale the indexed data is against the current commit; a build makes with `php artisan mapin:build`.
        MARKDOWN;

    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        FindToolAdapter::class,
        NodeToolAdapter::class,
        CallersToolAdapter::class,
        CalleesToolAdapter::class,
        ImpactToolAdapter::class,
        PathToolAdapter::class,
        RouteToolAdapter::class,
        ViewToolAdapter::class,
        ModelToolAdapter::class,
        UnresolvedToolAdapter::class,
        StatsToolAdapter::class,
    ];
}
