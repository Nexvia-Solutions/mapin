<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Mapin\Store\SqliteStore;
use Mapin\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * Shared by every Feature test that needs the invented fixture app on disk (Phase2Test,
 * Phase3Test, ...). Declared here rather than in one test file so it is always loaded regardless
 * of which specific file Pest is asked to run - a global function declared inside a test file is
 * only defined when that file itself was required, which running a single other file by path does
 * not guarantee. Kept its Phase 2 name for continuity with every existing call site even though it
 * now also covers the phase 4 Markdown fixture - SPEC.md section 12 wants one invented app with
 * every pattern, not a separate fixture per phase.
 */
function mapinCopyPhase2Fixtures(Application $app): void
{
    File::ensureDirectoryExists($app->basePath('app/MapinFixture'));
    File::copyDirectory(__DIR__.'/Fixtures/app', $app->basePath('app/MapinFixture'));

    File::ensureDirectoryExists($app->basePath('database/migrations'));
    File::copyDirectory(__DIR__.'/Fixtures/database/migrations', $app->basePath('database/migrations'));

    File::ensureDirectoryExists($app->basePath('resources/views'));
    File::copyDirectory(__DIR__.'/Fixtures/resources/views', $app->basePath('resources/views'));

    File::ensureDirectoryExists($app->basePath('docs'));
    File::copyDirectory(__DIR__.'/Fixtures/docs', $app->basePath('docs'));
}

function mapinCleanupPhase2Fixtures(Application $app): void
{
    File::deleteDirectory($app->basePath('app/MapinFixture'));
    File::deleteDirectory($app->basePath('database/migrations'));
    File::deleteDirectory($app->basePath('resources/views'));
    File::deleteDirectory($app->basePath('docs'));
    @unlink(config('mapin.storage'));
}

/** Shared by every phase's own edge-existence assertions, for the identical reason mapinCopyPhase2Fixtures() lives here rather than in whichever test file first needed it. */
function mapinEdgeExists(SqliteStore $store, string $type, string $fromKey, string $toKey): bool
{
    $stmt = $store->pdo()->prepare(
        'SELECT COUNT(*) FROM edges JOIN nodes f ON f.id = edges.from_id JOIN nodes t ON t.id = edges.to_id
         WHERE edges.type = ? AND f.key = ? AND t.key = ?',
    );
    $stmt->execute([$type, $fromKey, $toKey]);

    return ((int) $stmt->fetchColumn()) > 0;
}
