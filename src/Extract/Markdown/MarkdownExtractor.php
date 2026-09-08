<?php

declare(strict_types=1);

namespace Mapin\Extract\Markdown;

use Mapin\Extract\Contracts\SourceFile;
use Mapin\Extract\Contracts\UnresolvedRow;
use Mapin\Graph\Edge;
use Mapin\Graph\EdgeType;
use Mapin\Graph\Key;
use Mapin\Graph\Node;
use Mapin\Graph\NodeType;
use Mapin\Store\SqliteStore;

/**
 * Deterministic layer of SPEC.md section 7: a `doc` node per file, a `section` node per heading,
 * `links_doc` for relative Markdown links, and `documents` edges from mentions of a known class,
 * route, file, table or view in section text.
 *
 * Split into two phases the same way PhpExtractor (declare) and Resolver (resolve) are, and for
 * the same reason: a link or mention can point at a node - including this same file's own doc
 * node, for a same-page anchor link - that only exists in the store once every markdown file's own
 * nodes have been upserted, not just this one's. `nodes()` is pure text parsing with no store
 * access, safe to run for every file before any of them are written; `edges()` re-walks the same
 * parsed sections against the now-complete store. Neither is an `Extractor` implementation, for
 * the same reason RouteExtractor and BindingExtractor are not: this needs the built graph to
 * search against, not just one file's own content.
 *
 * Every match is a literal string equal to a real node's key, full name or exact short name -
 * never a guess. A mention that resolves to more than one candidate (a short class name two
 * different namespaces both use, a name that is both a route and a view) is recorded as unresolved
 * with every candidate listed, not silently picked; a mention that resolves to nothing is not
 * recorded at all, since most backticked or capitalized text in prose is not a reference to
 * project code.
 */
final class MarkdownExtractor
{
    private const CLASS_LIKE = '/^[A-Z][A-Za-z0-9]*(?:\\\\[A-Z][A-Za-z0-9]*)*$/';

    private const DOTTED_NAME = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/';

    private const TABLE_LIKE = '/^[a-z][a-z0-9_]*$/';

    public function requiresBoot(): bool
    {
        return false;
    }

    /**
     * @return array{nodes: Node[], sections: array<int, array{level: int, title: string, anchor: string, line: int, text: string}>}
     */
    public function nodes(SourceFile $file): array
    {
        $path = $file->relativePath;
        $docKey = Key::doc($path);
        $nodes = [new Node(NodeType::Doc, $path, $docKey, $path)];
        $usedAnchors = [];

        $sections = [];
        foreach ($this->parseSections($file->contents()) as $section) {
            $anchor = $this->uniqueAnchor(self::slugify($section['title']), $usedAnchors);
            $sectionKey = Key::section($path, $anchor);
            $nodes[] = new Node(
                NodeType::Section,
                $section['title'],
                $sectionKey,
                $path,
                $section['line'],
                ['anchor' => $anchor, 'level' => $section['level']],
            );
            $sections[] = [...$section, 'anchor' => $anchor];
        }

        return ['nodes' => $nodes, 'sections' => $sections];
    }

    /**
     * @param  array<int, array{level: int, title: string, anchor: string, line: int, text: string}>  $sections
     * @return array{edges: Edge[], unresolved: UnresolvedRow[]}
     */
    public function edges(SourceFile $file, array $sections, SqliteStore $store): array
    {
        $path = $file->relativePath;
        $docKey = Key::doc($path);
        $edges = [new Edge(EdgeType::Declares, Key::file($path), $docKey, $path)];
        $unresolved = [];

        foreach ($sections as $section) {
            $sectionKey = Key::section($path, $section['anchor']);
            $edges[] = new Edge(EdgeType::Declares, $docKey, $sectionKey, $path, $section['line']);

            foreach ($this->findLinks($section['text']) as $link) {
                $target = $this->resolveLink($link['url'], $path);
                if ($target === null) {
                    continue;
                }
                $targetDoc = $store->findNodeByKey(Key::doc($target['path']));
                if ($targetDoc === null) {
                    $unresolved[] = new UnresolvedRow($path, $section['line'], 'links_doc', $link['raw'], null, null, [$target['path']]);

                    continue;
                }
                $edges[] = new Edge(
                    EdgeType::LinksDoc,
                    $docKey,
                    Key::doc($target['path']),
                    $path,
                    $section['line'],
                    meta: array_filter(['anchor' => $target['anchor']]),
                );
            }

            foreach ($this->findMentions($section['text']) as $mention) {
                $candidates = $this->resolveMention($mention['text'], $mention['backticked'], $store);
                if ($candidates === []) {
                    continue;
                }
                if (count($candidates) > 1) {
                    $unresolved[] = new UnresolvedRow(
                        $path,
                        $section['line'],
                        'documents',
                        $mention['text'],
                        null,
                        null,
                        array_map(static fn (array $c) => $c['key'], $candidates),
                    );

                    continue;
                }
                $edges[] = new Edge(
                    EdgeType::Documents,
                    $sectionKey,
                    $candidates[0]['key'],
                    $path,
                    $section['line'],
                    meta: ['match' => $candidates[0]['match']],
                );
            }
        }

        return ['edges' => $edges, 'unresolved' => $unresolved];
    }

    /**
     * Flat (not nested) sections: everything from one heading up to the next, regardless of
     * level - simple, and enough for "which section mentioned this", the only thing section
     * boundaries are used for. Content before the first heading belongs to no section and is not
     * scanned for mentions or links.
     *
     * @return array<int, array{level: int, title: string, line: int, text: string}>
     */
    private function parseSections(string $content): array
    {
        $lines = explode("\n", $content);
        $sections = [];
        $current = null;

        foreach ($lines as $i => $line) {
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $match) === 1) {
                if ($current !== null) {
                    $sections[] = $current;
                }
                $current = ['level' => strlen($match[1]), 'title' => trim($match[2]), 'line' => $i + 1, 'text' => ''];

                continue;
            }
            if ($current !== null) {
                $current['text'] .= $line."\n";
            }
        }
        if ($current !== null) {
            $sections[] = $current;
        }

        return $sections;
    }

    /**
     * GitHub's own heading-anchor algorithm: lowercase, strip anything but letters/digits/spaces/
     * hyphens, spaces to hyphens. Public and static so LlmConceptExtractor can slugify a concept
     * name the identical way a section slugifies its own anchor - same shape of string, same rule,
     * not a coincidence worth two implementations of.
     */
    public static function slugify(string $title): string
    {
        $slug = strtolower($title);
        $slug = preg_replace('/[^a-z0-9\s\-_]/', '', $slug) ?? '';
        $slug = preg_replace('/\s+/', '-', trim($slug)) ?? '';

        return $slug;
    }

    /** @param array<string,true> $used */
    private function uniqueAnchor(string $base, array &$used): string
    {
        $anchor = $base;
        $suffix = 1;
        while (isset($used[$anchor])) {
            $anchor = $base.'-'.$suffix;
            $suffix++;
        }
        $used[$anchor] = true;

        return $anchor;
    }

    /** @return array<int, array{raw: string, url: string}> */
    private function findLinks(string $text): array
    {
        if (preg_match_all('/\[([^\]]*)\]\(([^)\s]+)\)/', $text, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        return array_map(static fn (array $m) => ['raw' => $m[0], 'url' => $m[2]], $matches);
    }

    /**
     * Null for anything that is not a local Markdown reference: external URLs, mailto links, or a
     * relative link to a file that is not itself Markdown (an image, for instance).
     *
     * @return array{path: string, anchor: ?string}|null
     */
    private function resolveLink(string $url, string $currentPath): ?array
    {
        if (preg_match('#^(https?:)?//#i', $url) === 1 || str_starts_with($url, 'mailto:')) {
            return null;
        }
        $anchor = null;
        $path = $url;
        if (str_contains($url, '#')) {
            [$path, $anchor] = explode('#', $url, 2);
        }
        $targetPath = $path === '' ? $currentPath : $this->normalizeRelativePath($currentPath, $path);
        if (preg_match('/\.(md|markdown)$/i', $targetPath) !== 1) {
            return null;
        }

        return ['path' => $targetPath, 'anchor' => $anchor !== '' ? $anchor : null];
    }

    private function normalizeRelativePath(string $fromPath, string $relative): string
    {
        if (str_starts_with($relative, '/')) {
            $combined = ltrim($relative, '/');
        } else {
            $baseDir = dirname($fromPath);
            $combined = ($baseDir === '.' ? '' : $baseDir.'/').$relative;
        }

        $parts = [];
        foreach (explode('/', $combined) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    /** @return array<int, array{text: string, backticked: bool}> */
    private function findMentions(string $text): array
    {
        $mentions = [];

        if (preg_match_all('/`([^`\n]+)`/', $text, $backtickMatches) !== false) {
            foreach ($backtickMatches[1] as $m) {
                $mentions[] = ['text' => trim($m), 'backticked' => true];
            }
        }

        // Strip backticked spans before scanning plain text, so a mention already captured above
        // is never double-counted as a plain mention too.
        $plainText = preg_replace('/`[^`\n]+`/', ' ', $text) ?? '';

        if (preg_match_all('/\b[A-Z][A-Za-z0-9]*(?:\\\\[A-Z][A-Za-z0-9]*)*\b/', $plainText, $classMatches) !== false) {
            foreach ($classMatches[0] as $m) {
                $mentions[] = ['text' => $m, 'backticked' => false];
            }
        }
        if (preg_match_all('/\b[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+\b/', $plainText, $dottedMatches) !== false) {
            foreach ($dottedMatches[0] as $m) {
                $mentions[] = ['text' => $m, 'backticked' => false];
            }
        }
        if (preg_match_all('#(?<![\w./])/[a-zA-Z0-9_\-{}/]*[a-zA-Z0-9_\-{}]#', $plainText, $uriMatches) !== false) {
            foreach ($uriMatches[0] as $m) {
                $mentions[] = ['text' => $m, 'backticked' => false];
            }
        }

        return $mentions;
    }

    /** @return array<int, array{key: string, match: string}> */
    private function resolveMention(string $text, bool $backticked, SqliteStore $store): array
    {
        $candidates = [];

        if (preg_match(self::CLASS_LIKE, $text) === 1) {
            $candidates = [...$candidates, ...$this->classCandidates($text, $store)];
        }

        if (preg_match(self::DOTTED_NAME, $text) === 1) {
            $route = $store->findRouteByName($text);
            if ($route !== null) {
                $candidates[] = ['key' => $route['key'], 'match' => 'route_name'];
            }
            $view = $store->findNodeByKey(Key::view($text));
            if ($view !== null) {
                $candidates[] = ['key' => $view['key'], 'match' => 'view'];
            }
        }

        if (str_starts_with($text, '/')) {
            foreach ($store->findRoutesByUri($text) as $route) {
                $candidates[] = ['key' => $route['key'], 'match' => 'route_uri'];
            }
        }

        if ($backticked) {
            if (str_contains($text, '/') || preg_match('/\.[a-z]{1,10}$/i', $text) === 1) {
                $file = $store->findNodeByKey(Key::file($text));
                if ($file !== null) {
                    $candidates[] = ['key' => $file['key'], 'match' => 'file'];
                }
            }
            if (preg_match(self::TABLE_LIKE, $text) === 1) {
                $table = $store->findNodeByKey(Key::table($text));
                if ($table !== null) {
                    $candidates[] = ['key' => $table['key'], 'match' => 'table'];
                }
            }
        }

        return $candidates;
    }

    /**
     * Exact matches only - key, full name, or the segment after the last backslash - the same rule
     * Query::exactMatches() applies for find()/model(), reimplemented here rather than shared
     * across the extract/query boundary: this runs at build time over the store being built,
     * Query runs at read time over a finished one, and the two layers are kept from depending on
     * each other on purpose.
     *
     * @return array<int, array{key: string, match: string}>
     */
    private function classCandidates(string $text, SqliteStore $store): array
    {
        $rows = $store->findNodes($text, NodeType::ClassLike);
        $exact = array_filter($rows, static function (array $r) use ($text): bool {
            if ($r['key'] === $text || $r['name'] === $text) {
                return true;
            }
            $shortName = str_contains($r['name'], '\\') ? substr($r['name'], (int) strrpos($r['name'], '\\') + 1) : $r['name'];

            return $shortName === $text;
        });

        return array_values(array_map(static fn (array $r): array => ['key' => $r['key'], 'match' => 'class'], $exact));
    }
}
