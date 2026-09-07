<?php

declare(strict_types=1);

namespace Mapin\Extract\Contracts;

use Illuminate\Container\Container;
use Illuminate\Routing\Router;

final class ExtractionContext
{
    public function __construct(
        public readonly string $projectRoot,
        public readonly bool $booted,
        public readonly ?Router $router = null,
        public readonly ?Container $container = null,
    ) {}
}
