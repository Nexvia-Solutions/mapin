<?php

declare(strict_types=1);

namespace Mapin\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Mcp\Server\Transport\StdioTransport;
use Mapin\Mcp\MapinServer;

/**
 * Starts Mapin's own MCP server directly over stdio - no host app registration in routes/ai.php
 * needed (SPEC.md section 9: "php artisan mapin:mcp # stdio MCP server" is meant to be
 * self-sufficient). Mirrors what Laravel\Mcp\Server\Registrar::startServer() does for a server
 * registered with Mcp::local(), without requiring that registration step.
 */
final class McpCommand extends Command
{
    /** @var string */
    protected $signature = 'mapin:mcp';

    /** @var string */
    protected $description = 'Start the Mapin MCP server over stdio';

    public function handle(): int
    {
        $transport = new StdioTransport;
        /** @var MapinServer $server */
        $server = $this->laravel->make(MapinServer::class, ['transport' => $transport]);
        $server->start();
        $transport->run();

        return self::SUCCESS;
    }
}
