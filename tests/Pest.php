<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Mapin\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * Shared by every Feature test that needs the invented fixture app on disk (Phase2Test,
 * Phase3Test, ...). Declared here rather than in one test file so it is always loaded regardless
 * of which specific file Pest is asked to run - a global function declared inside a test file is
 * only defined when that file itself was required, which running a single other file by path does
 * not guarantee.
 */
function mapinCopyPhase2Fixtures(Application $app): void
{
    File::ensureDirectoryExists($app->basePath('app/MapinFixture'));
    File::copyDirectory(__DIR__.'/Fixtures/app', $app->basePath('app/MapinFixture'));

    File::ensureDirectoryExists($app->basePath('database/migrations'));
    File::copyDirectory(__DIR__.'/Fixtures/database/migrations', $app->basePath('database/migrations'));

    File::ensureDirectoryExists($app->basePath('resources/views'));
    File::copyDirectory(__DIR__.'/Fixtures/resources/views', $app->basePath('resources/views'));
}

function mapinCleanupPhase2Fixtures(Application $app): void
{
    File::deleteDirectory($app->basePath('app/MapinFixture'));
    File::deleteDirectory($app->basePath('database/migrations'));
    File::deleteDirectory($app->basePath('resources/views'));
    @unlink(config('mapin.storage'));
}
