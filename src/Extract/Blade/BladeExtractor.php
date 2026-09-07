<?php

declare(strict_types=1);

namespace Mapin\Extract\Blade;

use Mapin\Extract\Contracts\ExtractionContext;
use Mapin\Extract\Contracts\Extractor;
use Mapin\Extract\Contracts\Fragment;
use Mapin\Extract\Contracts\SourceFile;
use Mapin\Graph\Edge;
use Mapin\Graph\EdgeType;
use Mapin\Graph\Key;
use Mapin\Graph\Node;
use Mapin\Graph\NodeType;

/**
 * Regex-based directive scanner - SPEC.md section 4's documented `--no-boot` fallback, built
 * first because it needs nothing booted and is exact for the directives it targets (Blade
 * directive syntax is fixed, not something that varies by app), unlike the compiler-based
 * approach section 4 also describes, which needs the app's real BladeCompiler and is not yet
 * built. Confidence-wise this extractor never guesses: every edge comes from a literal string
 * argument to a real directive or tag, never a computed name.
 */
final class BladeExtractor implements Extractor
{
    private const VIEWS_ROOT = 'resources/views/';

    public function supports(SourceFile $file): bool
    {
        return $file->lang === 'blade';
    }

    public function requiresBoot(): bool
    {
        return false;
    }

    public function extract(SourceFile $file, ExtractionContext $ctx): Fragment
    {
        $viewName = $this->viewNameFromPath($file->relativePath);
        $viewKey = Key::view($viewName);
        $content = $file->contents();

        $nodes = [new Node(NodeType::View, $viewName, $viewKey, $file->relativePath)];
        $edges = [new Edge(EdgeType::Declares, Key::file($file->relativePath), $viewKey)];

        foreach ($this->includedViews($content) as $included => $directive) {
            $edges[] = new Edge(EdgeType::Includes, $viewKey, Key::view($included), $file->relativePath, meta: ['directive' => $directive]);
        }

        foreach ($this->referencedComponents($content) as $component) {
            $componentKey = Key::component($component);
            $nodes[] = new Node(NodeType::Component, $component, $componentKey);
            $edges[] = new Edge(EdgeType::UsesComponent, $viewKey, $componentKey, $file->relativePath);
        }

        return new Fragment($nodes, $edges);
    }

    private function viewNameFromPath(string $relativePath): string
    {
        $path = str_starts_with($relativePath, self::VIEWS_ROOT) ? substr($relativePath, strlen(self::VIEWS_ROOT)) : $relativePath;
        $path = preg_replace('/\.blade\.php$/', '', $path);

        return str_replace('/', '.', $path);
    }

    /** @return array<string,string> included view name => directive that referenced it */
    private function includedViews(string $content): array
    {
        $found = [];

        if (preg_match('/@extends\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $match)) {
            $found[$match[1]] = 'extends';
        }
        foreach (['include' => 'include', 'includeIf' => 'includeIf', 'each' => 'each'] as $directive => $label) {
            if (preg_match_all('/@'.$directive.'\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
                foreach ($matches[1] as $view) {
                    $found[$view] = $label;
                }
            }
        }
        if (preg_match_all('/@(includeWhen|includeUnless)\s*\([^,]+,\s*[\'"]([^\'"]+)[\'"]/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $found[$match[2]] = $match[1];
            }
        }
        if (preg_match('/@includeFirst\s*\(\s*\[([^\]]+)\]/', $content, $match)
            && preg_match_all('/[\'"]([^\'"]+)[\'"]/', $match[1], $items)) {
            foreach ($items[1] as $view) {
                $found[$view] = 'includeFirst';
            }
        }

        return $found;
    }

    /** @return string[] */
    private function referencedComponents(string $content): array
    {
        $components = [];
        if (preg_match_all('/<x[-:]([a-zA-Z0-9_.\-]+)/', $content, $matches)) {
            $components = [...$components, ...$matches[1]];
        }
        if (preg_match_all('/@component\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
            $components = [...$components, ...$matches[1]];
        }

        return array_values(array_unique($components));
    }
}
