<?php

declare(strict_types=1);

namespace Mapin\Extract;

/**
 * Everything the resolver needs to know about one class, interface, trait or enum. Built fresh
 * during extraction of a changed file, or hydrated from a stored node's meta JSON for a file that
 * did not change in this build (see SymbolIndex::fromStoredMeta) - either path produces the same
 * shape, so resolution behaves identically regardless of where a class came from.
 */
final class ClassMeta
{
    /** @var array<string, array{type: ?string, source: string}> */
    public array $props = [];

    /** @var array<string, array{name: string, return: ?string, static: bool, params: array<string, ?string>}> */
    public array $methods = [];

    /** @var array<string, string> lowercase facade method name => return type */
    public array $facadeMethods = [];

    /** @var string[] */
    public array $interfaces = [];

    /** @var string[] */
    public array $traits = [];

    /** Literal value of a declared `protected $table = '...'`, when there is one. */
    public ?string $tableOverride = null;

    /**
     * A declared `protected $listen = [Event::class => [Listener::class, ...], ...]`, read once at
     * extraction time and turned straight into `listens` edges (PhpExtractor) - not persisted in
     * toMeta(), since it is only ever needed on the same pass that just parsed it fresh.
     *
     * @var array<string, string[]> event FQCN => listener FQCNs
     */
    public array $listenMap = [];

    public function __construct(
        public readonly string $fqcn,
        public readonly string $kind,
        public readonly ?string $file,
        public readonly int $line,
        public ?string $parent,
        public readonly bool $project,
    ) {}

    /** @return array<string,mixed> */
    public function toMeta(): array
    {
        return [
            'kind' => $this->kind,
            'parent' => $this->parent,
            'interfaces' => $this->interfaces,
            'traits' => $this->traits,
            'project' => $this->project,
            'props' => $this->props,
            'facade_methods' => $this->facadeMethods,
            'table_override' => $this->tableOverride,
        ];
    }

    /** @param array<string,mixed> $meta */
    public static function fromMeta(string $fqcn, array $meta, ?string $file, int $line): self
    {
        $class = new self($fqcn, $meta['kind'], $file, $line, $meta['parent'], (bool) $meta['project']);
        $class->interfaces = $meta['interfaces'] ?? [];
        $class->traits = $meta['traits'] ?? [];
        $class->props = $meta['props'] ?? [];
        $class->facadeMethods = $meta['facade_methods'] ?? [];
        $class->tableOverride = $meta['table_override'] ?? null;

        return $class;
    }

    /**
     * Keyed by lowercase name for case-insensitive lookup (PHP method names are
     * case-insensitive), but the original declared casing is kept in the value so node keys and
     * display names stay correct.
     *
     * @param  array{name?: string, return: ?string, static: bool, params?: array<string,?string>}  $meta
     */
    public function addMethod(string $name, array $meta): void
    {
        $this->methods[strtolower($name)] = [
            'name' => $meta['name'] ?? $name,
            'return' => $meta['return'],
            'static' => (bool) $meta['static'],
            'params' => $meta['params'] ?? [],
        ];
    }
}
