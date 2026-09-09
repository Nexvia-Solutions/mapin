<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Mapin\Store\SqliteStore;

/**
 * mapin:misses's sibling for the other kind of signal a build already produces and used to lose -
 * see SPEC.md 1.22. Reuses the blade-compiler fixture's own broken.blade.php (already exercised in
 * BladeCompilerTest.php for the graceful-degradation path itself) as a real, reliable way to
 * trigger a genuine build warning, rather than inventing a second broken fixture just for this.
 */
beforeEach(function () {
    mapinCopyBladeCompilerFixtures($this->app);
    config(['mapin.paths' => ['app/BladeCompilerFixture', 'resources/views/blade-compiler']]);
});

afterEach(function () {
    mapinCleanupBladeCompilerFixtures($this->app);
    File::delete($this->app->basePath('docs/mapin-warnings.jsonl'));
});

it('records a build warning once, and the same warning recurring on a later build does not duplicate it', function () {
    $this->artisan('mapin:build', ['--full' => true])->assertExitCode(0);

    $store = new SqliteStore(config('mapin.storage'));
    $warnings = $store->unexportedBuildWarnings();
    expect($warnings)->toHaveCount(1);
    expect($warnings[0]['warning'])->toContain('broken.blade.php');

    // broken.blade.php is still there, untouched - a second full build hits the exact same
    // problem again. Recording it a second time must not create a second row.
    $this->artisan('mapin:build', ['--full' => true])->assertExitCode(0);
    expect($store->unexportedBuildWarnings())->toHaveCount(1);
});

it('mapin:warnings exports unexported warnings to a JSONL file in the host project, then reports nothing new on the next run', function () {
    $this->artisan('mapin:build', ['--full' => true])->assertExitCode(0);

    $this->artisan('mapin:warnings', ['--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('"exported": 1');

    $path = $this->app->basePath('docs/mapin-warnings.jsonl');
    expect(file_exists($path))->toBeTrue();

    $lines = array_filter(explode("\n", (string) file_get_contents($path)));
    expect($lines)->toHaveCount(1);
    $decoded = json_decode($lines[array_key_first($lines)], true);
    expect($decoded['warning'])->toContain('broken.blade.php');

    // Second run: the same warning is still in the database (and will keep recurring on every
    // build until broken.blade.php is fixed), but already marked exported - no duplicate line.
    $this->artisan('mapin:warnings', ['--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('"exported": 0');

    $linesAfter = array_filter(explode("\n", (string) file_get_contents($path)));
    expect($linesAfter)->toHaveCount(1);
});
