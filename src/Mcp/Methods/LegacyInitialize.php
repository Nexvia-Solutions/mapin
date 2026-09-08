<?php

declare(strict_types=1);

namespace Mapin\Mcp\Methods;

use Laravel\Mcp\Enums\ProtocolVersion;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * laravel/mcp v1.0.0-beta.1 serves only MCP 2026-07-28 by default and rejects a classic
 * `initialize` request outright (`Server::handle()`'s own check for
 * `! isset($this->methods['initialize'])`) - a deliberate BC break on the package's part
 * (github.com/laravel/mcp pull #296), with the package's own PR description naming
 * `addMethod('initialize', ...)` as the way a server that still needs it can register one. Real
 * MCP clients still opening with the classic handshake (Claude Code's included, confirmed from a
 * separate session's real report - SPEC.md section 1.19) would otherwise never get past the first
 * message. Registered by MapinServer::boot(); MapinServer::validateProtocolMeta() is the other
 * half of this fix, needed because every request past this one from a legacy client also lacks
 * the `_meta.protocolVersion` the base class demands unconditionally.
 */
final class LegacyInitialize implements Method
{
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $requested = $request->get('protocolVersion');
        $supported = ProtocolVersion::initializeSupported();
        $version = is_string($requested) && in_array($requested, $supported, true)
            ? $requested
            : ($supported[0] ?? ProtocolVersion::V2025_06_18->value);

        return JsonRpcResponse::result($request->id, [
            'protocolVersion' => $version,
            'capabilities' => $context->serverCapabilities ?: (object) [],
            'serverInfo' => $context->implementation->toArray(),
            'instructions' => $context->instructions,
        ]);
    }
}
