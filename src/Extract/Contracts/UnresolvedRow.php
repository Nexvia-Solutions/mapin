<?php

declare(strict_types=1);

namespace Mapin\Extract\Contracts;

final class UnresolvedRow
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $kind,
        public readonly string $expression,
        public readonly ?string $receiverHint,
        public readonly ?string $member,
        /** @var string[] */
        public readonly array $candidates = [],
        /**
         * @var string[] lowercase symbol FQCNs (or "function:name") this attempt consulted before
         *               giving up - see Edge::$dependsOn for why this exists.
         */
        public readonly array $dependsOn = [],
    ) {}
}
