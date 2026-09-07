<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mapin\Store\SqliteStore;

/**
 * Proves the gap documented in SPEC.md section 1.4 is closed: a reference that could not resolve
 * because the method it chained through had no return type becomes resolved after that method
 * gains one, even though the file making the call never changed - only the method's own file did.
 */
it('re-resolves an unchanged file when a method it depends on gains a return type', function () {
    $appPath = $this->app->basePath('app/MapinFixture');
    File::ensureDirectoryExists($appPath);
    File::copyDirectory(__DIR__.'/../Fixtures/app', $appPath);

    $catalogFile = $appPath.'/Services/Catalog.php';

    Artisan::call('mapin:build', ['--full' => true]);

    $store = new SqliteStore(config('mapin.storage'));

    $callerKey = 'method:Fixture\\Http\\Controllers\\CatalogController::show';
    $edgesBefore = $store->pdo()
        ->query("SELECT edges.* FROM edges JOIN nodes ON nodes.id = edges.from_id WHERE nodes.key = '{$callerKey}'")
        ->fetchAll(PDO::FETCH_ASSOC);
    $authorEdgesBefore = array_filter($edgesBefore, fn (array $e) => str_contains((string) $e['meta'], '"member":"author"'));
    expect($authorEdgesBefore)->toBeEmpty('findBook() has no return type yet, so ->author() should not resolve to anything');

    $hashBefore = $store->fileHashes()['app/MapinFixture/Http/Controllers/CatalogController.php'] ?? null;

    // Only Catalog.php changes - CatalogController.php is untouched on disk.
    $original = file_get_contents($catalogFile);
    file_put_contents($catalogFile, str_replace(
        'public function findBook(string $isbn)',
        'public function findBook(string $isbn): ?\Fixture\Models\Book',
        $original,
    ));

    Artisan::call('mapin:build', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report['files_changed'])->toBe(1, 'only Catalog.php was touched on disk');
    expect($report['files_affected'])->toBeGreaterThanOrEqual(1, 'CatalogController.php depended on findBook() and must be re-resolved too');

    $hashAfter = $store->fileHashes()['app/MapinFixture/Http/Controllers/CatalogController.php'] ?? null;
    expect($hashAfter)->toBe($hashBefore, 'CatalogController.php content, and therefore its stored hash, never changed');

    $edgesAfter = $store->pdo()
        ->query("SELECT edges.* FROM edges JOIN nodes ON nodes.id = edges.from_id WHERE nodes.key = '{$callerKey}'")
        ->fetchAll(PDO::FETCH_ASSOC);
    $authorEdgesAfter = array_filter($edgesAfter, fn (array $e) => str_contains((string) $e['meta'], '"member":"author"'));
    expect($authorEdgesAfter)->not->toBeEmpty('findBook() now returns Book, so ->author() should resolve');

    File::deleteDirectory($appPath);
    @unlink(config('mapin.storage'));
});
