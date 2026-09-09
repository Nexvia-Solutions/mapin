<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Server\Contracts\Transport;
use Mapin\Mcp\MapinServer;

/**
 * A minimal in-memory Transport that just records every message MapinServer sends, so these tests
 * can drive raw JSON-RPC strings through the real handle() dispatch path - the layer
 * MapinServer::tool() (used by every other MCP test in this suite) bypasses entirely by calling a
 * tool directly. That layer is exactly what this regression is about (SPEC.md section 1.19):
 * Server::handle()'s own rejection of a classic `initialize` request, and MapinServer's override
 * of validateProtocolMeta() for every request after it in the same legacy session.
 */
final class RecordingTransport implements Transport
{
    /** @var list<array<string,mixed>> */
    public array $sent = [];

    public function onReceive(Closure $handler): void {}

    public function send(string $message): void
    {
        $this->sent[] = json_decode($message, true);
    }

    public function run() {}

    public function stream(Closure $stream): void {}
}

function mapinDriveMcpSession(RecordingTransport $transport): MapinServer
{
    $server = app()->make(MapinServer::class, ['transport' => $transport]);
    $server->start();

    return $server;
}

beforeEach(function () {
    mapinCopyPhase2Fixtures($this->app);
    Artisan::call('mapin:build', ['--full' => true]);
});

afterEach(function () {
    mapinCleanupPhase2Fixtures($this->app);
});

it('answers a classic initialize handshake instead of the bare rejection laravel/mcp v1.0.0-beta.1 serves by default', function () {
    $transport = new RecordingTransport;
    $server = mapinDriveMcpSession($transport);

    $server->handle(json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test-client', 'version' => '0.0.1'],
        ],
    ]));

    expect($transport->sent)->toHaveCount(1);
    $result = $transport->sent[0]['result'];
    expect($result['protocolVersion'])->toBe('2025-06-18')
        ->and($result['serverInfo']['name'])->toBe('Mapin')
        ->and($result)->toHaveKey('capabilities')
        ->and($result)->toHaveKey('instructions');
});

it('lets a full legacy session through - initialize, the initialized notification, then tools/list with no per-request _meta', function () {
    // The regression this guards: laravel/mcp's own _meta.protocolVersion requirement (the
    // mechanism MCP 2026-07-28 introduced to replace session-based negotiation) applies to every
    // request by default, not just initialize - a classic client negotiates once and never sends
    // _meta again for the rest of the session, so tools/list here carries none on purpose.
    $transport = new RecordingTransport;
    $server = mapinDriveMcpSession($transport);

    $server->handle(json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1.0']],
    ]));
    $server->handle(json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
    $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']));

    // The notification carries no id and produces no response of its own - only the two real
    // requests (initialize, tools/list) do.
    expect($transport->sent)->toHaveCount(2);
    expect($transport->sent[1]['result']['tools'])->not->toBeEmpty();
    expect($transport->sent[1])->not->toHaveKey('error');
});

it('treats a legacy request carrying _meta for something else (e.g. a progress token) as legacy too, not as a modern one missing protocolVersion', function () {
    // Regression for a real bug reported from an actual client session, found only after the
    // handshake fix shipped: the first version of validateProtocolMeta() treated _meta's outright
    // absence as the legacy signal, which broke on a real tools/call carrying a non-empty _meta
    // (a progressToken, here) with no protocolVersion in it - _meta is a general-purpose bag other
    // things already use, not something 2026-07-28 introduced. That request failed with the base
    // class's own "_meta is missing the required protocolVersion member" error - the same wording
    // the real report quoted - even though the client was never speaking the modern protocol at all.
    $transport = new RecordingTransport;
    $server = mapinDriveMcpSession($transport);

    $server->handle(json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => [
            'name' => 'stats',
            'arguments' => [],
            '_meta' => ['progressToken' => 'abc123'],
        ],
    ]));

    expect($transport->sent)->toHaveCount(1);
    expect($transport->sent[0])->not->toHaveKey('error');
    expect($transport->sent[0]['result']['structuredContent']['found'])->toBeTrue();
});

it('still enforces _meta.protocolVersion strictly for a modern MCP 2026-07-28 request - the legacy relaxation never leaks into that path', function () {
    $transport = new RecordingTransport;
    $server = mapinDriveMcpSession($transport);

    $server->handle(json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
        'params' => ['_meta' => [
            'io.modelcontextprotocol/protocolVersion' => '1999-01-01',
            'io.modelcontextprotocol/clientCapabilities' => [],
        ]],
    ]));

    expect($transport->sent)->toHaveCount(1);
    expect($transport->sent[0]['error']['message'])->toBe('Unsupported protocol version');
});
