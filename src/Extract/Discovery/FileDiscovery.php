<?php

declare(strict_types=1);

namespace Mapin\Extract\Discovery;

use Mapin\Extract\Contracts\SourceFile;

/**
 * Walks the configured paths under a project root and yields every file matching a known
 * extension, skipping vendor/node_modules/storage and anything the config excludes. Does not
 * read .gitignore (a project's ignore rules are about version control, not about what is worth
 * indexing); use config('mapin.exclude') for analysis-specific exclusions.
 */
final class FileDiscovery
{
    private const EXTENSION_LANG = [
        'php' => 'php',
        'blade.php' => 'blade',
        'md' => 'md',
        'markdown' => 'md',
        'js' => 'js',
        'jsx' => 'js',
        'ts' => 'js',
        'tsx' => 'js',
    ];

    private const ALWAYS_EXCLUDED_DIRS = [
        'vendor', 'node_modules', 'storage', 'bootstrap/cache', '.git',
    ];

    /**
     * @param  string[]  $paths
     * @param  string[]  $excludeGlobs
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $paths,
        private readonly array $excludeGlobs = [],
        private readonly bool $isProject = true,
    ) {}

    /** @return \Generator<SourceFile> */
    public function discover(): \Generator
    {
        foreach ($this->paths as $path) {
            $absoluteBase = rtrim($this->projectRoot, '/').'/'.ltrim($path, '/');
            if (is_file($absoluteBase)) {
                $file = $this->toSourceFile($absoluteBase);
                if ($file !== null) {
                    yield $file;
                }

                continue;
            }
            if (! is_dir($absoluteBase)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absoluteBase, \FilesystemIterator::SKIP_DOTS),
            );
            /** @var \SplFileInfo $info */
            foreach ($iterator as $info) {
                if (! $info->isFile()) {
                    continue;
                }
                $file = $this->toSourceFile($info->getPathname());
                if ($file !== null) {
                    yield $file;
                }
            }
        }
    }

    private function toSourceFile(string $absolutePath): ?SourceFile
    {
        $relative = ltrim(substr($absolutePath, strlen(rtrim($this->projectRoot, '/'))), '/');
        // Excluded dirs and glob excludes are checked against the path relative to the project
        // root, never the absolute filesystem path - an absolute check would wrongly exclude every
        // file whenever the project itself happens to live under a directory named "vendor" (as it
        // does inside this package's own Testbench fixture, and could in other real layouts too).
        $lang = $this->langFor($relative);
        if ($lang === null || $this->isExcludedDir($relative) || $this->isExcluded($relative)) {
            return null;
        }

        return new SourceFile($relative, $absolutePath, $lang, Hasher::hashFile($absolutePath), $this->isProject);
    }

    private function langFor(string $relativePath): ?string
    {
        if (str_ends_with($relativePath, '.blade.php')) {
            return 'blade';
        }
        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        return self::EXTENSION_LANG[$ext] ?? null;
    }

    private function isExcludedDir(string $relativePath): bool
    {
        // Only applies to project paths (app/, routes/, config/): a vendor-path discovery call
        // (isProject: false) is already scanning inside vendor/ deliberately, so excluding it here
        // too would defeat the whole point of indexing the framework for type resolution.
        if (! $this->isProject) {
            return false;
        }
        foreach (self::ALWAYS_EXCLUDED_DIRS as $dir) {
            if (str_starts_with($relativePath, $dir.'/') || str_contains($relativePath, '/'.$dir.'/')) {
                return true;
            }
        }

        return false;
    }

    private function isExcluded(string $relativePath): bool
    {
        foreach ($this->excludeGlobs as $glob) {
            if (fnmatch($glob, $relativePath)) {
                return true;
            }
        }

        return false;
    }
}
