<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Mapin\Store\SqliteStore;

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

it('does not reparse unchanged files on the next build', function () {
    $this->artisan('mapin:build', ['--full' => true])->assertExitCode(0);

    $this->artisan('mapin:build', ['--json' => true])->assertExitCode(0);

    $store = new SqliteStore(config('mapin.storage'));
    $lastBuild = $store->lastBuild();
    expect($lastBuild['mode'])->toBe('incremental');
    expect((int) $lastBuild['files_changed'])->toBe(0);
});
