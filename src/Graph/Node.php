<?php

declare(strict_types=1);

namespace Mapin\Graph;

final class Node
{
    /** @param array<string,mixed> $meta */
    public function __construct(
        public readonly NodeType $type,
        public readonly string $name,
        public readonly string $key,
        public readonly ?string $file = null,
        public readonly ?int $line = null,
        public readonly array $meta = [],
    ) {}

    /** @param array<string,mixed> $meta */
    public function withMeta(array $meta): self
    {
        return new self($this->type, $this->name, $this->key, $this->file, $this->line, [...$this->meta, ...$meta]);
    }
}
