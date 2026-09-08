<?php

declare(strict_types=1);

namespace Mapin\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Mapin\Mcp\Adapters\CalleesToolAdapter;
use Mapin\Mcp\Adapters\CallersToolAdapter;
use Mapin\Mcp\Adapters\CommunitiesToolAdapter;
use Mapin\Mcp\Adapters\DocsToolAdapter;
use Mapin\Mcp\Adapters\FindToolAdapter;
use Mapin\Mcp\Adapters\HubsToolAdapter;
use Mapin\Mcp\Adapters\ImpactToolAdapter;
use Mapin\Mcp\Adapters\ModelToolAdapter;
use Mapin\Mcp\Adapters\NodeToolAdapter;
use Mapin\Mcp\Adapters\PathToolAdapter;
use Mapin\Mcp\Adapters\RouteToolAdapter;
use Mapin\Mcp\Adapters\StatsToolAdapter;
use Mapin\Mcp\Adapters\UnresolvedToolAdapter;
use Mapin\Mcp\Adapters\ViewToolAdapter;
use Mapin\Mcp\Methods\LegacyInitialize;

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
        DocsToolAdapter::class,
        HubsToolAdapter::class,
        CommunitiesToolAdapter::class,
    ];

    protected function boot(): void
    {
        $this->addMethod('initialize', LegacyInitialize::class);
    }

    /**
     * The base class demands `_meta.protocolVersion` on every request, the mechanism MCP
     * 2026-07-28 introduced to replace per-session negotiation - a classic client (SPEC.md section
     * 1.19) never sends it, not just on `initialize` but on every request for the rest of that
     * session, since it negotiated the protocol version once, up front, and expects that to stick.
     * `_meta`'s absence is itself the signal: a 2026-07-28-aware client always sends it, so a
     * request with none is unambiguously a legacy client, not a session to track state for.
     */
    protected function validateProtocolMeta(JsonRpcRequest $request, ServerContext $context): void
    {
        if ($request->meta() === null) {
            return;
        }

        parent::validateProtocolMeta($request, $context);
    }
}
