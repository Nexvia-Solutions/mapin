<?php

declare(strict_types=1);

namespace Mapin\Console\Commands;

use Illuminate\Console\Command;

/**
 * Installs a git post-commit and post-merge hook that runs an incremental `mapin:build` in the
 * background after every local commit or merge - the closest equivalent to what graphify's own
 * git hook did, before it moved to a shared post-deploy hook because it was saturating a shared
 * server's CPU (SPEC.md's own retired-predecessor history). This one runs per developer, on their
 * own machine, against their own local graph - the same reasoning that made a shared CPU a real
 * concern does not apply to one person's own laptop.
 */
final class InstallHooksCommand extends Command
{
    /** @var string */
    protected $signature = 'mapin:install-hooks {--command= : The exact command that rebuilds the graph, e.g. "docker exec vendor-app-dev php artisan mapin:build" for a containerised project}';

    /** @var string */
    protected $description = 'Install git post-commit/post-merge hooks that rebuild the graph incrementally in the background';

    private const MARKER_START = '# mapin:auto-build - installed by `mapin:install-hooks`, safe to remove this block by hand';

    private const MARKER_END = '# mapin:auto-build end';

    public function handle(): int
    {
        $gitDir = trim((string) shell_exec('cd '.escapeshellarg(base_path()).' && git rev-parse --git-dir 2>/dev/null'));
        if ($gitDir === '') {
            $this->error('Mapin: not a git repository (or git is not available) - nothing to install.');

            return 1;
        }
        $hooksDir = str_starts_with($gitDir, '/') ? $gitDir.'/hooks' : base_path($gitDir.'/hooks');
        if (! is_dir($hooksDir)) {
            @mkdir($hooksDir, 0755, true);
        }

        $command = (string) ($this->option('command') ?: 'php artisan mapin:build');
        $block = self::MARKER_START."\n"
            .'(cd "$(git rev-parse --show-toplevel)" && '.$command.' > /dev/null 2>&1 &)'."\n"
            .self::MARKER_END;

        foreach (['post-commit', 'post-merge'] as $hook) {
            $this->installHook($hooksDir.'/'.$hook, $block);
        }

        $this->info("Mapin: hooks installed in {$hooksDir} (post-commit, post-merge), running: {$command}");

        return 0;
    }

    private function installHook(string $path, string $block): void
    {
        $existing = file_exists($path) ? (string) file_get_contents($path) : "#!/bin/sh\n";

        if (str_contains($existing, self::MARKER_START)) {
            $updated = (string) preg_replace(
                '/'.preg_quote(self::MARKER_START, '/').'.*?'.preg_quote(self::MARKER_END, '/').'/s',
                $block,
                $existing,
            );
        } else {
            $updated = rtrim($existing)."\n\n".$block."\n";
        }

        file_put_contents($path, $updated);
        chmod($path, 0755);
    }
}
