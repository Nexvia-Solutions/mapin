<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Mapin\Store\SqliteStore;

beforeEach(function () {
    mapinCopyPhase2Fixtures($this->app);
    $this->artisan('mapin:build', ['--full' => true])->assertExitCode(0);
});

afterEach(function () {
    mapinCleanupPhase2Fixtures($this->app);
    File::delete($this->app->basePath('docs/mapin-misses.jsonl'));
});

it('records a query miss when a tool answers found: false, but not when the result is merely an empty list', function () {
    // The negative half of this assertion is the one a naive "log every empty result" approach
    // would get wrong: TenantContext::setCurrent is a real method with zero real callers in the
    // fixture, and that is a legitimate found: true answer (Query.php's own found()/notFound()
    // split), not a miss.
    $this->artisan('mapin:find', ['name' => 'NoSuchClassAnywhere', '--json' => true])->assertExitCode(0);
    $this->artisan('mapin:callers', ['key' => 'method:Fixture\\Services\\TenantContext::setCurrent', '--json' => true])->assertExitCode(0);

    $store = new SqliteStore(config('mapin.storage'));
    $misses = $store->unexportedQueryMisses();

    expect($misses)->toHaveCount(1);
    expect($misses[0]['tool'])->toBe('find');
    expect(json_decode((string) $misses[0]['args'], true)['name'])->toBe('NoSuchClassAnywhere');
});

it('mapin:misses exports unexported misses to a JSONL file in the host project, then reports nothing new on the next run', function () {
    $this->artisan('mapin:find', ['name' => 'NoSuchClassAnywhere', '--json' => true])->assertExitCode(0);
    $this->artisan('mapin:route', ['route' => 'no.such.route', '--json' => true])->assertExitCode(0);

    $this->artisan('mapin:misses', ['--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('"exported": 2');

    $path = $this->app->basePath('docs/mapin-misses.jsonl');
    expect(file_exists($path))->toBeTrue();

    $lines = array_filter(explode("\n", (string) file_get_contents($path)));
    expect($lines)->toHaveCount(2);
    $decoded = array_map(fn (string $l) => json_decode($l, true), $lines);
    expect(array_column($decoded, 'tool'))->toBe(['find', 'route']);
    expect($decoded[0]['args']['name'])->toBe('NoSuchClassAnywhere');

    // Second run: same two misses are still in the database, but already marked exported - the
    // file must not gain duplicate lines.
    $this->artisan('mapin:misses', ['--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('"exported": 0');

    $linesAfter = array_filter(explode("\n", (string) file_get_contents($path)));
    expect($linesAfter)->toHaveCount(2);
});

it('mapin:misses only exports misses recorded since the last export, appending rather than overwriting', function () {
    $this->artisan('mapin:find', ['name' => 'NoSuchClassAnywhere', '--json' => true])->assertExitCode(0);
    $this->artisan('mapin:misses')->assertExitCode(0);

    $this->artisan('mapin:find', ['name' => 'AlsoMissing', '--json' => true])->assertExitCode(0);
    $this->artisan('mapin:misses', ['--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('"exported": 1');

    $path = $this->app->basePath('docs/mapin-misses.jsonl');
    $lines = array_filter(explode("\n", (string) file_get_contents($path)));
    expect($lines)->toHaveCount(2);
});
