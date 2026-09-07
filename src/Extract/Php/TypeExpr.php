<?php

declare(strict_types=1);

namespace Mapin\Extract\Php;

use PhpParser\Node;

/**
 * Turns a php-parser type node, or a docblock type expression, into a class-like FQCN string, or
 * null for builtins/unknown types. Handles the PHPStan conventions Illuminate's own source (and a
 * lot of userland code) relies on: conditional return types and generic type parameters
 * (SPEC.md section 4.1 rule 7).
 */
final class TypeExpr
{
    public static function fromNode(?Node $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof Node\NullableType) {
            return self::fromNode($type->type);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $inner) {
                $resolved = self::fromNode($inner);
                if ($resolved !== null) {
                    return $resolved;
                }
            }

            return null;
        }
        if ($type instanceof Node\Name) {
            $name = $type->toString();

            return in_array(strtolower($name), ['self', 'static', 'parent'], true) ? strtolower($name) : ltrim($name, '\\');
        }
        if ($type instanceof Node\Identifier) {
            $name = strtolower($type->toString());

            return in_array($name, ['self', 'static'], true) ? $name : null;
        }

        return null;
    }

    /**
     * Extracts the return expression text from a docblock, unwrapping the PHPStan conditional
     * return type syntax used pervasively across the Laravel framework - for example, a tag
     * reading "($key is null ? \Illuminate\Http\Request : mixed)". The "is null" true branch is
     * taken because that is overwhelmingly the branch used when the helper or method is called
     * with no arguments, the common case for further chaining.
     */
    public static function extractReturnExpr(string $doc): ?string
    {
        if (preg_match('/@return\s*\(\s*\$\w+\s+is\s+null\s*\?\s*([^\s:]+)/', $doc, $matches)) {
            return $matches[1];
        }
        if (preg_match('/@return\s+([^\s]+)/', $doc, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * First class-like type from a docblock type expression such as "\Foo|null" or "Foo[]".
     *
     * @param  array<string,string>  $uses
     */
    public static function fromDoc(?string $expr, ?string $namespace = null, array $uses = []): ?string
    {
        if ($expr === null) {
            return null;
        }
        foreach (explode('|', $expr) as $part) {
            $part = trim($part);
            if ($part === '' || str_ends_with($part, '[]')) {
                continue;
            }
            $part = ltrim($part, '?');
            // Strip a PHPStan/Psalm generic parameter, e.g. "Builder<static>" or "Collection<int, User>".
            $part = preg_replace('/<.*>$/', '', $part);
            if (preg_match('/^(array|string|int|float|bool|boolean|integer|mixed|void|null|callable|iterable|object|resource|false|true|\$this|static|self|never)$/i', $part)) {
                if (in_array(strtolower($part), ['static', 'self'], true) || $part === '$this') {
                    return 'static';
                }

                continue;
            }
            if (preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $part) !== 1) {
                continue;
            }
            if (str_starts_with($part, '\\')) {
                return ltrim($part, '\\');
            }
            $first = explode('\\', $part)[0];
            if (isset($uses[$first])) {
                return $uses[$first].substr($part, strlen($first));
            }

            return $namespace !== null ? $namespace.'\\'.$part : $part;
        }

        return null;
    }
}
