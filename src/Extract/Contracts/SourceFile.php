<?php

declare(strict_types=1);

namespace Mapin\Extract\Contracts;

final class SourceFile
{
    public function __construct(
        public readonly string $relativePath,
        public readonly string $absolutePath,
        public readonly string $lang,
        public readonly string $hash,
        public readonly bool $isProject = true,
    ) {}

    public function contents(): string
    {
        return file_get_contents($this->absolutePath);
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->relativePath, PATHINFO_EXTENSION));
    }
}
