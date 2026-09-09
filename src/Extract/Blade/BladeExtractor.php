<?php

declare(strict_types=1);

namespace Mapin\Extract\Blade;

use Illuminate\View\Compilers\BladeCompiler;
use Mapin\Extract\Contracts\ExtractionContext;
use Mapin\Extract\Contracts\Extractor;
use Mapin\Extract\Contracts\Fragment;
use Mapin\Extract\Contracts\SourceFile;
use Mapin\Extract\Contracts\UnresolvedRow;
use PhpParser\Error;
use PhpParser\Node as AstNode;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * SPEC.md section 4's originally-documented compiler-based extractor (never built until 1.21 -
 * see that section for the full write-up), composed rather than a full rewrite: `DirectiveScanner`
 * (the former, and still `--no-boot`, BladeExtractor) stays the authoritative source for
 * structural edges (`Includes`, `UsesComponent`, `Declares`) - Blade compiles those directives to
 * calls on the `$__env` runtime object, not to `view()`, so there is nothing for a generic PHP
 * reference visitor to recognize there without teaching it Blade-runtime types specifically. What
 * compiling genuinely adds, and regex structurally cannot see: `route()`, `view()`, `app()`,
 * method calls, `new` - anything written as real PHP inside `{{ }}`, `{!! !!}`, or an `@php`
 * block. Mirrors `PhpExtractor`'s own parse-cache-resolve shape closely on purpose - `BuildRunner`
 * treats the two extractors' cached ASTs the same way in its own resolve pass.
 */
final class BladeExtractor implements Extractor
{
    /** Blade's own compiled-runtime call targets - never application code, never resolvable to a
     *  real type Mapin tracks, so recording them as `unresolved` would be pure noise repeated on
     *  every single Blade file a real application has (SPEC.md 1.21: confirmed by tracing
     *  ReferenceVisitor::record(), which only stays silent for a literal `$this` receiver).
     *  `$errors`/`$__bag` added after real-application verification against vendor-brandon showed
     *  they alone accounted for roughly half of every new Blade-sourced unresolved row: `$errors`
     *  is auto-shared into every view by Illuminate\View\Middleware\ShareErrorsFromSession, never
     *  application code either, and `$__bag` is `@error`'s own compiled local (`$__bag =
     *  $errors->getBag(...)`) - both framework/Blade runtime plumbing by the same reasoning as the
     *  original three, not a guess extending the list further without evidence. */
    private const RUNTIME_RECEIVER_PREFIXES = ['$__env->', '$attributes->', '$component->', '$errors->', '$__bag->'];

    private readonly Parser $parser;

    private readonly DirectiveScanner $structural;

    /** @var array<string, AstNode[]> relativePath => compiled, name-resolved statements, held only for this build run */
    private array $parsedStmts = [];

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->structural = new DirectiveScanner;
    }

    public function supports(SourceFile $file): bool
    {
        return $file->lang === 'blade';
    }

    public function requiresBoot(): bool
    {
        return true;
    }

    public function extract(SourceFile $file, ExtractionContext $ctx): Fragment
    {
        $fragment = $this->structural->extract($file, $ctx);

        if ($ctx->container === null) {
            return $fragment;
        }

        try {
            /** @var BladeCompiler $compiler */
            $compiler = $ctx->container->make(BladeCompiler::class);
            $compiled = $compiler->compileString($file->contents());
        } catch (\Throwable $e) {
            return new Fragment($fragment->nodes, $fragment->edges, [
                ...$fragment->warnings,
                "{$file->relativePath}: could not compile Blade template - {$e->getMessage()}",
            ]);
        }

        try {
            $stmts = $this->parser->parse($compiled);
        } catch (Error $e) {
            return new Fragment($fragment->nodes, $fragment->edges, [
                ...$fragment->warnings,
                "{$file->relativePath}: compiled output failed to parse - {$e->getMessage()}",
            ]);
        }
        if ($stmts === null) {
            return $fragment;
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $this->parsedStmts[$file->relativePath] = $traverser->traverse($stmts);

        return $fragment;
    }

    /** @return AstNode[]|null */
    public function parsedStatementsFor(string $relativePath): ?array
    {
        return $this->parsedStmts[$relativePath] ?? null;
    }

    /**
     * @param  UnresolvedRow[]  $unresolved
     * @return UnresolvedRow[]
     */
    public function filterRuntimeNoise(array $unresolved): array
    {
        return array_values(array_filter(
            $unresolved,
            static function (UnresolvedRow $row): bool {
                foreach (self::RUNTIME_RECEIVER_PREFIXES as $prefix) {
                    if (str_starts_with($row->expression, $prefix)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }
}
