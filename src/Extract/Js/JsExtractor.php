<?php

declare(strict_types=1);

namespace Mapin\Extract\Js;

use Mapin\Extract\Contracts\SourceFile;
use Mapin\Extract\Contracts\UnresolvedRow;
use Mapin\Graph\Edge;
use Mapin\Graph\EdgeType;
use Mapin\Graph\Key;
use Mapin\Graph\Node;
use Mapin\Graph\NodeType;
use Mapin\Store\SqliteStore;

/**
 * SPEC.md section 4/section 1.3's own non-goal: "Phase 7 maps HTTP calls in JS files to routes by
 * URL; component trees and state flow are out of scope." Regex based, not a real JS/TS/JSX parser -
 * the same "good enough, not a full parser" call BladeExtractor already made for Blade directives,
 * applied to a narrower surface still: `fetch(...)`, `axios.<verb>(...)`, `axios({...})`,
 * `$.ajax({...})` and `$.get`/`$.post`, matching only a literal string URL - a computed, templated
 * or interpolated one is invisible here on purpose, the identical "no runtime-computed targets"
 * non-goal every other extractor in this project already respects.
 *
 * Not an `Extractor` implementation, split into `nodes()`/`edges()` like MarkdownExtractor, for the
 * same store-access reason: an unresolved-verb call needs `SqliteStore::findRoutesByUri()` to know
 * whether it is a clean single match or a genuine ambiguity, which nothing in the plain `Extractor`
 * interface's `extract(SourceFile, ExtractionContext)` signature can reach.
 */
final class JsExtractor
{
    public function requiresBoot(): bool
    {
        return false;
    }

    /** @return array{nodes: Node[], calls: array<int, array{verb: string, url: string, line: int}>} */
    public function nodes(SourceFile $file): array
    {
        $path = $file->relativePath;
        $node = new Node(NodeType::File, $path, Key::file($path), $path);
        $content = $file->contents();

        $calls = [
            ...$this->findAxiosMethodCalls($content),
            ...$this->findObjectFormCalls($content, '/\baxios(?:\.request)?\s*\(\s*\{(?<opts>(?:[^{}]|\{[^{}]*\})*)\}/is'),
            ...$this->findFetchCalls($content),
            ...$this->findObjectFormCalls($content, '/\$\.ajax\s*\(\s*\{(?<opts>(?:[^{}]|\{[^{}]*\})*)\}/is'),
            ...$this->findJqueryShorthandCalls($content),
        ];

        return ['nodes' => [$node], 'calls' => $calls];
    }

    /**
     * @param  array<int, array{verb: string, url: string, line: int}>  $calls
     * @return array{edges: Edge[], unresolved: UnresolvedRow[]}
     */
    public function edges(SourceFile $file, array $calls, SqliteStore $store): array
    {
        $path = $file->relativePath;
        $fileKey = Key::file($path);
        $edges = [];
        $unresolved = [];

        foreach ($calls as $call) {
            if (! str_starts_with($call['url'], '/')) {
                // Only a root-relative path is meaningfully "this route's own URI" - an absolute
                // external URL, a relative path, or a template literal whose interpolation left
                // this looking like a URL but is not a literal one, resolves to nothing.
                continue;
            }

            // The verb (always known - every scanner below either reads it directly off the call
            // shape or defaults to GET, each library's own documented default) is tried as an exact
            // (verb, URL) match first, not just carried as meta - checked against real application
            // code while building this, not assumed: the single largest source of "ambiguous" URLs
            // in that real graph turned out to be exactly this pattern, `axios.get('/admin/cart',
            // ...)` and `axios.post('/admin/cart', ...)` as two deliberate, separate calls a few
            // hundred lines apart, not vague or interpolated code. `axios.<verb>(...)` names the
            // verb as directly and unambiguously as `new X()` names a class - not a heuristic guess
            // to be distrusted, so matching by URL alone first (as an earlier version of this method
            // did) actively threw away real information and landed a resolvable pair of calls in
            // `unresolved` for no reason.
            $exactKey = Key::route($call['verb'], $call['url']);
            if ($store->findNodeByKey($exactKey) !== null) {
                $edges[] = new Edge(EdgeType::Requests, $fileKey, $exactKey, $path, $call['line'], meta: ['verb' => $call['verb'], 'url' => $call['url']]);

                continue;
            }

            // The detected verb did not match any route at that URL - most likely this extractor's
            // own verb detection missed something the call site actually specifies (a computed
            // method value, an unrecognised call shape), not a genuinely nonexistent route. Falls
            // back to matching by URL alone, which is either a clean single match or a genuine
            // ambiguity to report, never a guess between candidates.
            $candidates = $store->findRoutesByUri($call['url']);
            if (count($candidates) === 1) {
                $edges[] = new Edge(EdgeType::Requests, $fileKey, $candidates[0]['key'], $path, $call['line'], meta: ['verb' => $call['verb'], 'url' => $call['url']]);
            } elseif (count($candidates) > 1) {
                $unresolved[] = new UnresolvedRow(
                    $path,
                    $call['line'],
                    'requests',
                    $call['url'],
                    null,
                    $call['verb'],
                    array_map(static fn (array $c): string => $c['key'], $candidates),
                );
            }
        }

        return ['edges' => $edges, 'unresolved' => $unresolved];
    }

    /** @return array<int, array{verb: string, url: string, line: int}> */
    private function findAxiosMethodCalls(string $content): array
    {
        $calls = [];
        if (preg_match_all('/\baxios\s*\.\s*(get|post|put|patch|delete|head)\s*\(\s*([\'"`])((?:(?!\2).)*)\2/i', $content, $matches, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[1] as $i => [$verb]) {
                $calls[] = [
                    'verb' => strtoupper($verb),
                    'url' => $matches[3][$i][0],
                    'line' => $this->lineAt($content, (int) $matches[0][$i][1]),
                ];
            }
        }

        return $calls;
    }

    /** @return array<int, array{verb: string, url: string, line: int}> */
    private function findJqueryShorthandCalls(string $content): array
    {
        $calls = [];
        if (preg_match_all('/\$\.(get|post)\s*\(\s*([\'"`])((?:(?!\2).)*)\2/i', $content, $matches, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[1] as $i => [$verb]) {
                $calls[] = [
                    'verb' => strtoupper($verb),
                    'url' => $matches[3][$i][0],
                    'line' => $this->lineAt($content, (int) $matches[0][$i][1]),
                ];
            }
        }

        return $calls;
    }

    /** @return array<int, array{verb: string, url: string, line: int}> */
    private function findFetchCalls(string $content): array
    {
        $calls = [];
        // The URL is fetch's own first positional argument; `rest` is everything up to the call's
        // own closing paren (tolerant of one nested-paren level, enough for an inline options
        // object's own method calls if any), searched for an options object's `method:`. No
        // `method:` key at all defaults to GET - the Fetch API's own documented default, not a
        // guess, checked against a real call shape (a bare `fetch(url, { headers: {...} })`, no
        // `method` key at all) found in the real application this extractor was verified against.
        if (preg_match_all('/\bfetch\s*\(\s*([\'"`])((?:(?!\1).)*)\1(?<rest>(?:[^()]|\((?:[^()]*)\))*)\)/is', $content, $matches, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[2] as $i => [$url]) {
                $rest = $matches['rest'][$i][0];
                $verb = 'GET';
                if (preg_match('/\bmethod\s*:\s*([\'"`])(\w+)\1/i', $rest, $verbMatch) === 1) {
                    $verb = strtoupper($verbMatch[2]);
                }
                $calls[] = [
                    'verb' => $verb,
                    'url' => $url,
                    'line' => $this->lineAt($content, (int) $matches[0][$i][1]),
                ];
            }
        }

        return $calls;
    }

    /**
     * Shared by both `axios({...})`/`axios.request({...})` and `$.ajax({...})` - both document the
     * identical default of GET when neither options object specifies `method`/`type`, so a missing
     * key defaults to GET here too rather than leaving the verb unknown.
     *
     * @return array<int, array{verb: string, url: string, line: int}>
     */
    private function findObjectFormCalls(string $content, string $callPattern): array
    {
        $calls = [];
        if (preg_match_all($callPattern, $content, $matches, PREG_OFFSET_CAPTURE) === false || ! isset($matches['opts'])) {
            return $calls;
        }
        foreach ($matches['opts'] as $i => [$opts]) {
            if (preg_match('/\burl\s*:\s*([\'"`])((?:(?!\1).)*)\1/', $opts, $urlMatch) !== 1) {
                continue;
            }
            $verb = 'GET';
            if (preg_match('/\b(?:method|type)\s*:\s*([\'"`])(\w+)\1/i', $opts, $verbMatch) === 1) {
                $verb = strtoupper($verbMatch[2]);
            }
            $calls[] = [
                'verb' => $verb,
                'url' => $urlMatch[2],
                'line' => $this->lineAt($content, (int) $matches[0][$i][1]),
            ];
        }

        return $calls;
    }

    private function lineAt(string $content, int $offset): int
    {
        return substr_count($content, "\n", 0, $offset) + 1;
    }
}
