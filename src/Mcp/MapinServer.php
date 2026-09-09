<?php

declare(strict_types=1);

namespace Mapin\Mcp;

use Laravel\Mcp\Enums\MetaKey;
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
     *
     * The first version of this fix checked `_meta`'s outright absence as the legacy signal -
     * wrong, found from a real client's real `tools/call` request: `_meta` is a general-purpose
     * bag that classic (pre-2026-07-28) clients already use for other things (a progress token,
     * for one), so a legacy request can carry a non-empty `_meta` that still has no
     * `protocolVersion` key in it. The correct signal is that specific key's presence, not
     * `_meta`'s own - `MetaKey::PROTOCOL_VERSION`, the exact one the base class's own check below
     * requires, checked directly rather than inferred from a broader condition.
     */
    protected function validateProtocolMeta(JsonRpcRequest $request, ServerContext $context): void
    {
        $meta = $request->meta();
        if ($meta === null || ! array_key_exists(MetaKey::PROTOCOL_VERSION->value, $meta)) {
            return;
        }

        parent::validateProtocolMeta($request, $context);
    }
}
