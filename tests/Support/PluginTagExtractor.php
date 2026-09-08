<?php

declare(strict_types=1);

namespace Mapin\Tests\Support;

use Mapin\Extract\Contracts\ExtractionContext;
use Mapin\Extract\Contracts\Extractor;
use Mapin\Extract\Contracts\Fragment;
use Mapin\Extract\Contracts\SourceFile;
use Mapin\Graph\Node;
use Mapin\Graph\NodeType;

/**
 * Minimal third-party-style Extractor, registered only via config('mapin.extractors') in a test's
 * own environment - proves the real thing (BuildCommand resolving that config through the
 * container and BuildRunner actually running it alongside PhpExtractor on the same file, not
 * instead of it) rather than just that the Extractor interface can be implemented.
 */
final class PluginTagExtractor implements Extractor
{
    public function supports(SourceFile $file): bool
    {
        return $file->lang === 'php';
    }

    public function requiresBoot(): bool
    {
        return false;
    }

    public function extract(SourceFile $file, ExtractionContext $ctx): Fragment
    {
        $key = 'external:plugin-tag:'.$file->relativePath;

        return new Fragment(nodes: [new Node(NodeType::External, $key, $key, $file->relativePath)]);
    }
}
