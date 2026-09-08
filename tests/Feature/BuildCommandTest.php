<?php

declare(strict_types=1);

use Fixture\Http\Controllers\BookController;
use Fixture\Http\Controllers\CatalogController;
use Fixture\Http\Controllers\DashboardController;
use Fixture\Http\Middleware\EnsureTenant;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Mapin\Store\SqliteStore;
use Mapin\Tests\Support\PluginTagExtractor;

function mapinCopyFixtureIntoApp(string $basePath): string
{
    $target = $basePath.'/app/MapinFixture';
    File::ensureDirectoryExists($target);
    File::copyDirectory(__DIR__.'/../Fixtures/app', $target);

    // The fixture declares namespace Fixture\..., not App\MapinFixture\...; copying it flat under
    // app/ still lets FileDiscovery find and parse it, since Mapin does not require PSR-4 alignment
    // between a file's path and its declared namespace.
    return $target;
}

beforeEach(function () {
    mapinCopyFixtureIntoApp($this->app->basePath());
});

afterEach(function () {
    File::deleteDirectory($this->app->basePath('app/MapinFixture'));
    @unlink(config('mapin.storage'));
});

it('builds a graph from scratch and reports it', function () {
    $this->artisan('mapin:build', ['--full' => true])->assertExitCode(0);

    $this->artisan('mapin:stats', ['--json' => true])
        ->assertExitCode(0);

    expect(file_exists(config('mapin.storage')))->toBeTrue();
});

it('finds a known class by short name', function () {
    $this->artisan('mapin:build', ['--full' => true])->assertExitCode(0);

    $this->artisan('mapin:find', ['name' => 'TenantContext', '--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('"found": true');
});

it('lists callers of a method', function () {
    $this->artisan('mapin:build', ['--full' => true])->assertExitCode(0);

    $this->artisan('mapin:callers', ['key' => 'method:Fixture\\Services\\TenantContext::setCurrent', '--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('"found": true');
});

it('runs a plugin extractor registered via config(mapin.extractors) alongside PhpExtractor, not instead of it', function () {
    // docs/EXTENDING.md's own worked example: a host app or third-party package registers its own
    // Extractor here, resolved through the container, and gets a turn on every file it supports -
    // proves the real wiring (BuildCommand -> BuildRunner -> extractWith()), not just that the
    // Extractor interface can be implemented in isolation.
    config(['mapin.extractors' => [PluginTagExtractor::class]]);

    $this->artisan('mapin:build', ['--full' => true])->assertExitCode(0);

    $store = new SqliteStore(config('mapin.storage'));
    $pluginNode = $store->pdo()->prepare("SELECT COUNT(*) FROM nodes WHERE key = 'external:plugin-tag:app/MapinFixture/Services/TenantContext.php'");
    $pluginNode->execute();
    expect((int) $pluginNode->fetchColumn())->toBe(1);

    // PhpExtractor's own, completely normal extraction of the same file is still there too - the
    // plugin adds to it, it does not take its place.
    $phpNode = $store->pdo()->prepare("SELECT COUNT(*) FROM nodes WHERE key = 'class:Fixture\\Services\\TenantContext'");
    $phpNode->execute();
    expect((int) $phpNode->fetchColumn())->toBe(1);
});

it('does not reparse unchanged files on the next build', function () {
    $this->artisan('mapin:build', ['--full' => true])->assertExitCode(0);

    $this->artisan('mapin:build', ['--json' => true])->assertExitCode(0);

    $store = new SqliteStore(config('mapin.storage'));
    $lastBuild = $store->lastBuild();
    expect($lastBuild['mode'])->toBe('incremental');
    expect((int) $lastBuild['files_changed'])->toBe(0);
});

it('reports the same node and edge counts mapin:build prints as the graph actually holds', function () {
    // Regression for a real bug (found 2026-09-08 running mapin:build against a real application
    // twice, before and after a git rebase): multiple routes sharing one middleware alias each
    // pushed their own Node object for it, so the build report counted the same real row several
    // times over - upsertNodes() correctly collapsed them to one row via ON CONFLICT(key), but the
    // printed/recorded count came from the input array size, not what actually landed in the table.
    // Three routes below share 'ensure-tenant' on purpose, to reproduce the exact shape that hid
    // this: no existing test registered more than one route on the same middleware.
    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('ensure-tenant');
    Route::get('/catalog/{isbn}', [CatalogController::class, 'show'])->middleware('ensure-tenant');
    Route::get('/books', [BookController::class, 'store'])->middleware('ensure-tenant');
    $this->app->make('router')->aliasMiddleware('ensure-tenant', EnsureTenant::class);

    $this->artisan('mapin:build', ['--full' => true])->assertExitCode(0);

    $store = new SqliteStore(config('mapin.storage'));
    $lastBuild = $store->lastBuild();
    $counts = $store->counts();

    expect((int) $lastBuild['nodes'])->toBe($counts['nodes'])
        ->and((int) $lastBuild['edges'])->toBe($counts['edges']);

    // The real assertion behind the numbers matching: one middleware row, not three.
    $middlewareCount = $store->pdo()
        ->query("SELECT COUNT(*) FROM nodes WHERE type = 'middleware' AND key = 'middleware:ensure-tenant'")
        ->fetchColumn();
    expect((int) $middlewareCount)->toBe(1);
});
