<?php

declare(strict_types=1);

namespace Mapin\Console\Commands;

use Illuminate\Console\Command;
use Mapin\Mcp\Tools\CalleesTool;
use Mapin\Mcp\Tools\CallersTool;
use Mapin\Mcp\Tools\CommunitiesTool;
use Mapin\Mcp\Tools\DocsTool;
use Mapin\Mcp\Tools\FindTool;
use Mapin\Mcp\Tools\HubsTool;
use Mapin\Mcp\Tools\ImpactTool;
use Mapin\Mcp\Tools\ModelTool;
use Mapin\Mcp\Tools\NodeTool;
use Mapin\Mcp\Tools\PathTool;
use Mapin\Mcp\Tools\RouteTool;
use Mapin\Mcp\Tools\StatsTool;
use Mapin\Mcp\Tools\UnresolvedTool;
use Mapin\Mcp\Tools\ViewTool;
use Mapin\Mcp\ToolSpec;
use Mapin\Query\GitStatus;
use Mapin\Query\Query;
use Mapin\Store\SqliteStore;

/**
 * The one entry point for every tool in SPEC.md section 6.1 - mapin:find/callers/impact/route/
 * unresolved/stats are convenience aliases for the most used ones, this reaches all of them,
 * including node/callees/path which have no dedicated command. Drives the exact same ToolSpec
 * instances the MCP server calls, so a CLI answer and an MCP answer to the same question never
 * diverge.
 */
final class QueryCommand extends Command
{
    /** @var string */
    protected $signature = 'mapin:query {tool : find|node|callers|callees|impact|path|route|view|model|unresolved|stats|docs|hubs|communities}
        {--arg=* : key=value, repeatable}';

    /** @var string */
    protected $description = 'Run any Mapin query tool by name, output is always JSON';

    /** @var array<string, class-string<ToolSpec>> */
    private const TOOLS = [
        'find' => FindTool::class,
        'node' => NodeTool::class,
        'callers' => CallersTool::class,
        'callees' => CalleesTool::class,
        'impact' => ImpactTool::class,
        'path' => PathTool::class,
        'route' => RouteTool::class,
        'view' => ViewTool::class,
        'model' => ModelTool::class,
        'unresolved' => UnresolvedTool::class,
        'stats' => StatsTool::class,
        'docs' => DocsTool::class,
        'hubs' => HubsTool::class,
        'communities' => CommunitiesTool::class,
    ];

    public function handle(): int
    {
        $toolName = (string) $this->argument('tool');
        if (! isset(self::TOOLS[$toolName])) {
            $this->error("Mapin: unknown tool [{$toolName}]. Known tools: ".implode(', ', array_keys(self::TOOLS)).'.');

            return 1;
        }

        $storagePath = (string) config('mapin.storage');
        if (! file_exists($storagePath)) {
            $this->error('Mapin: no graph found. Run mapin:build first.');

            return 1;
        }

        $query = new Query(new SqliteStore($storagePath), base_path(), new GitStatus);
        /** @var ToolSpec $tool */
        $tool = $this->laravel->make(self::TOOLS[$toolName], ['query' => $query]);

        $arguments = $this->parseArgs($tool);
        $envelope = $tool->handle($arguments);

        $this->line((string) json_encode($envelope, JSON_PRETTY_PRINT));

        return 0;
    }

    /** @return array<string, mixed> */
    private function parseArgs(ToolSpec $tool): array
    {
        $spec = $tool->arguments();
        $args = [];
        foreach ((array) $this->option('arg') as $pair) {
            if (! str_contains((string) $pair, '=')) {
                continue;
            }
            [$key, $value] = explode('=', (string) $pair, 2);
            $args[$key] = $this->cast($value, $spec[$key]['type'] ?? 'string');
        }
        foreach ($spec as $name => $arg) {
            if (! array_key_exists($name, $args) && array_key_exists('default', $arg)) {
                $args[$name] = $arg['default'];
            }
        }

        return $args;
    }

    private function cast(string $value, string $type): string|int|float|bool
    {
        return match ($type) {
            'integer' => (int) $value,
            'number' => (float) $value,
            'boolean' => in_array(strtolower($value), ['1', 'true', 'yes'], true),
            default => $value,
        };
    }
}
