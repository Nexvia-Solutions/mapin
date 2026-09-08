# Extending Mapin

Mapin's own core covers plain Laravel: routes, views, Eloquent, container bindings, Markdown docs,
JS-to-route calls. Anything specific to a framework built on top of Laravel - Livewire, Inertia,
Filament, or your own conventions - belongs in an `Extractor` your application or a separate
package registers, not in Mapin's own core (SPEC.md section 1.3's own non-goals, and section 15's
"scope creep toward a full static analyser" risk).

## The `Extractor` interface

```php
namespace Mapin\Extract\Contracts;

interface Extractor
{
    public function supports(SourceFile $file): bool;
    public function requiresBoot(): bool;
    public function extract(SourceFile $file, ExtractionContext $ctx): Fragment;
}
```

- **`supports()`** decides whether this extractor has anything to say about a given file. Checked
  against `$file->lang` (`'php'`, `'blade'`, `'md'`, or `'js'` - the same tag `FileDiscovery`
  already assigns by extension) or `$file->relativePath` for something more specific.
- **`requiresBoot()`** tells `mapin:build --no-boot` whether to skip this extractor. `true` if
  `extract()` needs the booted application (the router, the container) - `false` if it works from
  the file's own content alone.
- **`extract()`** returns a `Fragment`: the nodes this file declares, and the edges that are known
  without needing the rest of the codebase (`declares`, `extends`, `implements`, `uses_trait` -
  SPEC.md section 2 explains why edges needing cross-file information, like `calls` or
  `instantiates`, are a separate pipeline stage, not something a plain `Extractor` produces).

**Every extractor that supports a given file gets a turn, not just the first one that claims it.**
If your extractor also supports `.php` files, it runs alongside `PhpExtractor`, contributing its
own nodes and edges on top of what `PhpExtractor` already found - it does not replace it. Nothing
about registering your own extractor can turn off or change the core PHP/Blade extraction.

## A worked example

Say your application uses a `#[Widget]` attribute on classes that Mapin's own `PhpExtractor` has
no reason to know about, and you want each one to show up as its own node:

```php
namespace App\Mapin;

use Mapin\Extract\Contracts\{Extractor, ExtractionContext, Fragment, SourceFile};
use Mapin\Graph\{Node, NodeType, Key};

final class WidgetExtractor implements Extractor
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
        // Your own detection - a regex scan, or nikic/php-parser directly (the same library
        // PhpExtractor itself uses) if you need real AST precision. Keep it to this one file's own
        // content: an Extractor never sees other files (SPEC.md section 2).
        if (! str_contains($file->contents(), '#[Widget]')) {
            return new Fragment;
        }

        $key = Key::classLike('SomeWidgetNamespace').':widget';

        return new Fragment(nodes: [
            new Node(NodeType::External, 'Widget', $key, $file->relativePath),
        ]);
    }
}
```

Register it:

```php
// config/mapin.php
'extractors' => [
    \App\Mapin\WidgetExtractor::class,
],
```

Resolved through the container on every `mapin:build`, so a constructor-injected dependency works
the same way it would for any other class Laravel resolves for you.

## When you need more than `Extractor`

`RouteExtractor`, `BindingExtractor` and `ScheduleExtractor` are not `Extractor` implementations -
their input is the booted router/container/console kernel as a whole, not one file, so they run
once per build rather than once per file. `MarkdownExtractor` and `JsExtractor` are not `Extractor`
implementations either, for a different reason: they need the store itself to resolve a reference
(a Markdown mention, a JS `fetch()` call) against nodes other files declared, split into their own
`nodes()`/`edges()` methods rather than the single `extract()` call a plain `Extractor` gets. If
your own extractor needs the same thing - resolving something against the rest of the graph, not
just this file's own content - read `MarkdownExtractor`'s class docblock first; none of these three
shapes are wired into `config('mapin.extractors')` today, so a case like this currently means
forking `BuildRunner` itself rather than a clean registration point. That gap is real, not
hypothetical - said plainly here rather than implied to already be solved.

## Adding a query tool

There is no registration point for a custom query tool (`ToolSpec`) today - `MapinServer` and
`mapin:query` both list their tools directly, and nothing in SPEC.md promises otherwise for tools
the way section 4 does for extractors. If your own extractor's nodes and edges are useful to query,
the existing generic tools (`find`, `node`, `callers`, `callees`, `impact`, `path`) already work
against anything in the graph, keyed by whatever `Key::` convention you used - a custom node type
does not need a custom tool to be reachable.
