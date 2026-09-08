<?php

declare(strict_types=1);

namespace Mapin\Tests;

use Laravel\Mcp\Server\McpServiceProvider;
use Mapin\MapinServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @return array<int,class-string> */
    protected function getPackageProviders($app): array
    {
        return [McpServiceProvider::class, MapinServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mapin.storage', $this->tempStoragePath());
        // Feature tests exercise the command pipeline, not framework-aware type resolution (that is
        // covered by tests/Unit/ResolverTest.php without needing real Illuminate source at all,
        // since SymbolIndex::isModel() matches by name). Keeping this empty keeps them fast.
        $app['config']->set('mapin.vendor_paths', []);
        // Testbench's own skeleton app ships its own default resources/views/welcome.blade.php and
        // resources/views/errors/*.blade.php - noise from the test harness, not the invented
        // fixture, but real enough to break GoldenTest's exact snapshot on a genuinely fresh
        // install: a local dev container reused across a long session never showed this, a real CI
        // run building from scratch did (SPEC.md 1.14).
        $app['config']->set('mapin.exclude', [
            'resources/views/welcome.blade.php',
            'resources/views/errors/*.blade.php',
        ]);
    }

    protected function tempStoragePath(): string
    {
        return sys_get_temp_dir().'/mapin-test-'.bin2hex(random_bytes(6)).'.sqlite';
    }

    protected function fixturePath(string $path = ''): string
    {
        return __DIR__.'/Fixtures/app'.($path !== '' ? '/'.ltrim($path, '/') : '');
    }
}
