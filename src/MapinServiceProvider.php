<?php

declare(strict_types=1);

namespace Mapin;

use Illuminate\Support\ServiceProvider;
use Mapin\Console\Commands\BuildCommand;
use Mapin\Console\Commands\CallersCommand;
use Mapin\Console\Commands\CommunitiesCommand;
use Mapin\Console\Commands\DocsCommand;
use Mapin\Console\Commands\DoctorCommand;
use Mapin\Console\Commands\FindCommand;
use Mapin\Console\Commands\ImpactCommand;
use Mapin\Console\Commands\InstallHooksCommand;
use Mapin\Console\Commands\McpCommand;
use Mapin\Console\Commands\MissesCommand;
use Mapin\Console\Commands\ModelCommand;
use Mapin\Console\Commands\QueryCommand;
use Mapin\Console\Commands\RouteCommand;
use Mapin\Console\Commands\StatsCommand;
use Mapin\Console\Commands\UnresolvedCommand;
use Mapin\Console\Commands\ViewCommand;
use Mapin\Query\GitStatus;
use Mapin\Query\Query;
use Mapin\Store\SqliteStore;

final class MapinServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mapin.php', 'mapin');

        // The MCP tool classes are resolved by laravel/mcp through the container (ToolInvoker
        // calls Container::getInstance()->call([$tool, 'handle'])), so Query and SqliteStore need
        // to be bound for that auto-wiring to work - CLI commands construct these manually instead,
        // since they already have $storagePath and base_path() in hand at that point.
        $this->app->singleton(SqliteStore::class, fn ($app): SqliteStore => new SqliteStore((string) $app['config']->get('mapin.storage')));
        $this->app->singleton(Query::class, fn ($app): Query => new Query($app->make(SqliteStore::class), $app->basePath(), new GitStatus));
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/mapin.php' => $this->app->configPath('mapin.php'),
        ], 'mapin-config');

        $this->commands([
            BuildCommand::class,
            StatsCommand::class,
            FindCommand::class,
            CallersCommand::class,
            RouteCommand::class,
            ViewCommand::class,
            ModelCommand::class,
            ImpactCommand::class,
            UnresolvedCommand::class,
            QueryCommand::class,
            McpCommand::class,
            DoctorCommand::class,
            DocsCommand::class,
            CommunitiesCommand::class,
            MissesCommand::class,
            InstallHooksCommand::class,
        ]);
    }
}
