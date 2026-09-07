<?php

declare(strict_types=1);

namespace Mapin\Query;

/**
 * Staleness reporting for the "graph" block every response carries (SPEC.md section 8: "built_commit
 * versus git rev-parse HEAD and git diff --name-only, reported in every response"). Shells out to git
 * rather than reading .git internals directly (unlike BuildRunner::currentCommit(), which only needs
 * the commit itself and reads .git/HEAD to avoid a process spawn on every build) because a name-only
 * diff has no cheap file-based equivalent.
 */
final class GitStatus
{
    public function headCommit(string $root): ?string
    {
        return $this->run($root, ['git', 'rev-parse', 'HEAD']);
    }

    /**
     * Files that differ between $builtCommit and the current working tree - staged, unstaged and
     * committed-since all in one, which is exactly what "changed since this build" means. Null when
     * there is no built commit to compare against (no build yet, or the build ran outside a git repo).
     */
    public function filesChangedSince(string $root, ?string $builtCommit): ?int
    {
        if ($builtCommit === null) {
            return null;
        }
        $output = $this->run($root, ['git', 'diff', '--name-only', $builtCommit]);

        return $output === null ? null : count(array_filter(explode("\n", $output)));
    }

    /** @param list<string> $command */
    private function run(string $root, array $command): ?string
    {
        if (! is_dir($root.'/.git')) {
            return null;
        }
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($command, $descriptors, $pipes, $root);
        if (! is_resource($process)) {
            return null;
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ($exitCode === 0 && $output !== false) ? trim($output) : null;
    }
}
