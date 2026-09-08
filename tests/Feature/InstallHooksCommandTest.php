<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function () {
    // base_path() during a Feature test is Testbench's own skeleton app, inside this package's own
    // vendor/ - confirmed to have no .git of its own before relying on that here. A real git repo is
    // initialised on top of it just for this test file, and torn down afterwards, so this never
    // touches this package's own real .git (a completely separate directory tree).
    shell_exec('cd '.escapeshellarg(base_path()).' && git init -q && git config user.email test@example.com && git config user.name Test');
});

afterEach(function () {
    File::deleteDirectory(base_path('.git'));
});

it('installs a post-commit and post-merge hook that runs the given command in the background', function () {
    $this->artisan('mapin:install-hooks', ['--command' => 'php artisan mapin:build'])->assertExitCode(0);

    foreach (['post-commit', 'post-merge'] as $hook) {
        $path = base_path('.git/hooks/'.$hook);
        expect(file_exists($path))->toBeTrue();
        expect(is_executable($path))->toBeTrue();
        $contents = (string) file_get_contents($path);
        expect($contents)->toContain('mapin:auto-build')
            ->and($contents)->toContain('php artisan mapin:build')
            ->and($contents)->toContain('&)'); // backgrounded, never blocks the commit/merge itself
    }
});

it('is idempotent - running it twice does not duplicate the installed block', function () {
    $this->artisan('mapin:install-hooks')->assertExitCode(0);
    $this->artisan('mapin:install-hooks')->assertExitCode(0);

    $contents = (string) file_get_contents(base_path('.git/hooks/post-commit'));
    expect(substr_count($contents, 'mapin:auto-build - installed'))->toBe(1);
});

it('preserves an existing hook\'s own content instead of overwriting it', function () {
    File::ensureDirectoryExists(base_path('.git/hooks'));
    file_put_contents(base_path('.git/hooks/post-commit'), "#!/bin/sh\necho 'some other tool ran this'\n");

    $this->artisan('mapin:install-hooks')->assertExitCode(0);

    $contents = (string) file_get_contents(base_path('.git/hooks/post-commit'));
    expect($contents)->toContain('some other tool ran this')
        ->and($contents)->toContain('mapin:auto-build');
});

it('reinstalling with a different --command replaces only mapin\'s own block, not the rest of the file', function () {
    $this->artisan('mapin:install-hooks', ['--command' => 'php artisan mapin:build'])->assertExitCode(0);
    file_put_contents(base_path('.git/hooks/post-commit'), "\necho 'kept'\n", FILE_APPEND);

    $this->artisan('mapin:install-hooks', ['--command' => 'docker exec vendor-app-dev php artisan mapin:build'])->assertExitCode(0);

    $contents = (string) file_get_contents(base_path('.git/hooks/post-commit'));
    expect($contents)->toContain('docker exec vendor-app-dev php artisan mapin:build')
        ->and($contents)->not->toContain('(cd "$(git rev-parse --show-toplevel)" && php artisan mapin:build >') // the old command string is gone
        ->and($contents)->toContain("echo 'kept'"); // content outside mapin's own markers survives
});

it('fails cleanly when the project is not a git repository', function () {
    // base_path() during a Feature test lives inside this package's own vendor/, which is itself
    // inside this package's own real git repository - deleting .git here still leaves git finding
    // the real repo by walking up the tree, exactly as git is supposed to. A genuinely isolated
    // directory outside any git tree is the only reliable way to exercise "not a repo at all".
    $isolated = sys_get_temp_dir().'/mapin-not-a-git-repo-'.bin2hex(random_bytes(6));
    mkdir($isolated);
    $this->app->setBasePath($isolated);

    try {
        $this->artisan('mapin:install-hooks')->assertExitCode(1);
    } finally {
        File::deleteDirectory($isolated);
    }
});
