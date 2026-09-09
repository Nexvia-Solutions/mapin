<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Mapin\Store\SqliteStore;

/**
 * SPEC.md section 1.21: BladeExtractor became compiler-based, closing the gap section 1.8 named -
 * route()/view() written as literal PHP inside a .blade.php file were invisible to the regex
 * scanner, because nothing ever parsed that PHP. These tests use a dedicated fixture, outside the
 * shared golden fixture, the same way SPEC.md 1.17's vendor-degradation fixture does - a change
 * that would add noise to the golden snapshot's diff gets its own small fixture instead.
 * mapinCopyBladeCompilerFixtures()/mapinCleanupBladeCompilerFixtures() are declared in
 * tests/Pest.php, shared with BuildWarningsTest.php.
 */
it('resolves route(), view() and a real method call written as literal PHP inside a Blade template', function () {
    mapinCopyBladeCompilerFixtures($this->app);
    Route::get('/orders/{id}', fn () => null)->name('orders.show');
    config(['mapin.paths' => ['app/BladeCompilerFixture', 'resources/views/blade-compiler']]);

    try {
        Artisan::call('mapin:build', ['--full' => true]);

        $store = new SqliteStore(config('mapin.storage'));
        $fileKey = 'file:resources/views/blade-compiler/order-summary.blade.php';

        $edges = $store->pdo()
            ->query("SELECT edges.type, nodes.key AS to_key FROM edges JOIN nodes ON nodes.id = edges.to_id JOIN nodes AS f ON f.id = edges.from_id WHERE f.key = '{$fileKey}'")
            ->fetchAll(PDO::FETCH_ASSOC);
        $byType = [];
        foreach ($edges as $edge) {
            $byType[$edge['type']][] = $edge['to_key'];
        }

        expect($byType['links_route'] ?? [])->toContain('route:GET /orders/{id}');
        expect($byType['renders'] ?? [])->toContain('view:blade-compiler.other-view');
        expect($byType['instantiates'] ?? [])->toContain('class:App\Greeter');
        expect($byType['calls'] ?? [])->toContain('method:App\Greeter::hello');
    } finally {
        mapinCleanupBladeCompilerFixtures($this->app);
    }
});

it('never reports Blade\'s own compiled runtime calls as unresolved', function () {
    // The shared fixture's own dashboard/index.blade.php already uses @extends/@section/@include
    // and a component tag - real structural directives, all of which compile to calls on $__env,
    // not view(). The dedicated blade-compiler fixture adds @error, which compiles to a call on
    // $__bag on top of $errors (auto-shared into every view, never application code either) -
    // together these two accounted for roughly half of every new Blade-sourced unresolved row
    // found verifying this against vendor-brandon (SPEC.md 1.21), not a hypothetical concern.
    mapinCopyPhase2Fixtures($this->app);
    mapinCopyBladeCompilerFixtures($this->app);
    config(['mapin.paths' => array_merge((array) config('mapin.paths'), ['app/BladeCompilerFixture', 'resources/views/blade-compiler'])]);

    try {
        Artisan::call('mapin:build', ['--full' => true]);

        $store = new SqliteStore(config('mapin.storage'));
        $count = (int) $store->pdo()
            ->query("SELECT COUNT(*) FROM unresolved WHERE expression LIKE '\$__env->%' OR expression LIKE '\$attributes->%' OR expression LIKE '\$component->%' OR expression LIKE '\$errors->%' OR expression LIKE '\$__bag->%'")
            ->fetchColumn();

        expect($count)->toBe(0);
    } finally {
        mapinCleanupPhase2Fixtures($this->app);
        mapinCleanupBladeCompilerFixtures($this->app);
    }
});

it('falls back to DirectiveScanner under --no-boot: structural edges only, no compiled-PHP edges', function () {
    mapinCopyBladeCompilerFixtures($this->app);
    config(['mapin.paths' => ['app/BladeCompilerFixture', 'resources/views/blade-compiler']]);

    try {
        Artisan::call('mapin:build', ['--full' => true, '--no-boot' => true]);

        $store = new SqliteStore(config('mapin.storage'));
        $fileKey = 'file:resources/views/blade-compiler/order-summary.blade.php';

        // The file: node itself is still there (DirectiveScanner's own fix, not gated by boot)...
        $fileNode = $store->pdo()->query("SELECT id FROM nodes WHERE key = '{$fileKey}'")->fetch(PDO::FETCH_ASSOC);
        expect($fileNode)->not->toBeFalse();

        // ...but nothing from the compiled-PHP path: no container was available to compile with.
        $edges = $store->pdo()
            ->query("SELECT edges.type FROM edges JOIN nodes ON nodes.id = edges.from_id WHERE nodes.key = '{$fileKey}'")
            ->fetchAll(PDO::FETCH_COLUMN);
        expect($edges)->not->toContain('links_route')
            ->and($edges)->not->toContain('renders');
    } finally {
        mapinCleanupBladeCompilerFixtures($this->app);
    }
});

it('degrades to a build warning instead of aborting when a Blade file compiles to invalid PHP', function () {
    mapinCopyBladeCompilerFixtures($this->app);
    config(['mapin.paths' => ['app/BladeCompilerFixture', 'resources/views/blade-compiler']]);

    try {
        Artisan::call('mapin:build', ['--full' => true, '--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $matchesBroken = array_filter($report['warnings'], static fn (string $w) => str_contains($w, 'broken.blade.php'));
        expect($matchesBroken)->not->toBeEmpty();

        // The rest of the build still completed - order-summary.blade.php's own edges still landed.
        $store = new SqliteStore(config('mapin.storage'));
        $count = (int) $store->pdo()->query("SELECT COUNT(*) FROM nodes WHERE type = 'view'")->fetchColumn();
        expect($count)->toBeGreaterThan(0);
    } finally {
        mapinCleanupBladeCompilerFixtures($this->app);
    }
});
