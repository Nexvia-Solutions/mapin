<?php

declare(strict_types=1);

namespace Mapin\Extract\Contracts;

interface Extractor
{
    public function supports(SourceFile $file): bool;

    public function requiresBoot(): bool;

    public function extract(SourceFile $file, ExtractionContext $ctx): Fragment;
}
