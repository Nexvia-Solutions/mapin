<?php

declare(strict_types=1);

namespace Mapin\Graph;

/**
 * Builds the stable, public node keys described in SPEC.md section 3.1. Keys are the handle
 * every query is addressed by, so the format here must not change without a schema version bump.
 */
final class Key
{
    public static function file(string $relativePath): string
    {
        return 'file:'.$relativePath;
    }

    public static function classLike(string $fqcn): string
    {
        return 'class:'.ltrim($fqcn, '\\');
    }

    public static function anonymousClass(string $relativePath, int $line): string
    {
        return 'class:anonymous@'.$relativePath.':'.$line;
    }

    public static function method(string $fqcn, string $method): string
    {
        return 'method:'.ltrim($fqcn, '\\').'::'.$method;
    }

    public static function function_(string $name): string
    {
        return 'function:'.$name;
    }

    public static function external(string $fqcn): string
    {
        return 'external:'.ltrim($fqcn, '\\');
    }

    public static function route(string $method, string $uri): string
    {
        return 'route:'.strtoupper($method).' /'.ltrim($uri, '/');
    }

    public static function view(string $name): string
    {
        return 'view:'.$name;
    }

    public static function component(string $name): string
    {
        return 'component:'.$name;
    }

    public static function middleware(string $alias): string
    {
        return 'middleware:'.$alias;
    }

    public static function table(string $name): string
    {
        return 'table:'.$name;
    }
}
