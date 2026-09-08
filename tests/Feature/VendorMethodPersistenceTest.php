<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mapin\Store\SqliteStore;

/**
 * Regression for a real bug found 2026-09-08 auditing unresolved calls against a real application:
 * a vendor class's own methods (return types included) were extracted correctly, but ClassMeta's
 * own toMeta() never persisted them, and hydrateIndex()'s only way to rebuild ->methods on an
 * incremental build - `method:` nodes - only ever exist for project classes (PhpExtractor's own
 * `if ($class->project)` gate). So a vendor class's methods were only ever known in-memory, during
 * whichever build last re-parsed that exact file - a real full build resolved every call chain
 * through it correctly, and the very next incremental build that did not touch that vendor file
 * silently forgot every one of its methods, degrading resolution with no file of the caller's own
 * ever changing. See SPEC.md section 1.17 for the full write-up.
 */
it('keeps resolving a call chain through a vendor class method after an incremental build that never re-parses that vendor file', function () {
    $base = $this->app->basePath();
    File::copyDirectory(__DIR__.'/../Fixtures/vendor-degradation/FakeVendor', $base.'/fake-vendor/FakeVendor');
    File::copyDirectory(__DIR__.'/../Fixtures/vendor-degradation/app', $base.'/app/VendorDegradation');

    config(['mapin.paths' => ['app/VendorDegradation']]);
    config(['mapin.vendor_paths' => ['fake-vendor']]);

    Artisan::call('mapin:build', ['--full' => true]);

    $store = new SqliteStore(config('mapin.storage'));
    $callerKey = 'method:App\\RedirectController::test';

    $edgesFull = $store->pdo()
        ->query("SELECT edges.* FROM edges JOIN nodes ON nodes.id = edges.from_id WHERE nodes.key = '{$callerKey}'")
        ->fetchAll(PDO::FETCH_ASSOC);
    $withInputFull = array_filter($edgesFull, fn (array $e) => str_contains((string) $e['meta'], '"member":"withInput"'));
    expect($withInputFull)->not->toBeEmpty('a fresh --full resolves the whole chain, including through the vendor class');

    // Touch only the caller - FakeVendor/Redirector.php and RedirectResponse.php are untouched on
    // disk, so an incremental build never re-parses them.
    $callerFile = $base.'/app/VendorDegradation/RedirectController.php';
    file_put_contents($callerFile, file_get_contents($callerFile)."\n// touched, still calls the same chain\n");

    Artisan::call('mapin:build', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);
    expect($report['files_changed'])->toBe(1, 'only RedirectController.php was touched on disk');
    expect($report['files_affected'] ?? 0)->toBe(0, 'FakeVendor classes never changed, so nothing should be affected by symbol_deps either - this is the scenario where the bug hid');

    $edgesIncremental = $store->pdo()
        ->query("SELECT edges.* FROM edges JOIN nodes ON nodes.id = edges.from_id WHERE nodes.key = '{$callerKey}'")
        ->fetchAll(PDO::FETCH_ASSOC);
    $withInputIncremental = array_filter($edgesIncremental, fn (array $e) => str_contains((string) $e['meta'], '"member":"withInput"'));
    expect($withInputIncremental)->not->toBeEmpty(
        'withInput() should still resolve after the incremental build - before the fix, FakeVendor\\Redirector::back() lost its own '
        .'return type on hydration (no method: node for a vendor class to rebuild it from), so back()\'s return type, and therefore '
        .'withInput()\'s receiver, silently became unknown',
    );

    File::deleteDirectory($base.'/fake-vendor');
    File::deleteDirectory($base.'/app/VendorDegradation');
    @unlink(config('mapin.storage'));
});
