<?php

declare(strict_types=1);

namespace Mapin\Query;

use Mapin\Graph\NodeType;
use Mapin\Store\SqliteStore;

/**
 * The one query service backing both the CLI and the MCP server (SPEC.md section 6): one method
 * per tool in section 6.1, each returning a QueryResult, plus envelope() to wrap that result in the
 * response contract every tool shares. Callers (CLI commands, Mcp\Tools\*) own presentation - a
 * table for a terminal, structured content for an MCP response - this class only ever returns data.
 */
final class Query
{
    /**
     * Edge types impact() walks backward. Structural edges (routes_to, renders, ...) carry no
     * confidence of their own - they are read from the source, not inferred - so min_confidence
     * only filters `calls` edges in practice.
     */
    private const REVERSE_IMPACT_EDGE_TYPES = [
        'calls', 'routes_to', 'renders', 'dispatches', 'schedules', 'listens',
        'observes', 'injects', 'instantiates', 'resolves', 'links_route',
        'includes', 'uses_component',
    ];

    public function __construct(
        private readonly SqliteStore $store,
        private readonly string $projectRoot,
        private readonly GitStatus $git = new GitStatus,
    ) {}

    public function find(string $name, ?NodeType $type, bool $fuzzy): QueryResult
    {
        $rows = $this->store->findNodes($name, $type);
        $exact = $this->exactMatches($rows, $name);

        if ($exact !== []) {
            return QueryResult::found($exact, confidence: 1.0);
        }
        if (! $fuzzy) {
            return QueryResult::notFound($this->toSuggestions($rows));
        }

        return $rows === [] ? QueryResult::notFound() : QueryResult::found($rows, confidence: 0.6);
    }

    /**
     * SPEC.md 6.1 lists "short class name" as an exact lookup target alongside key and FQCN, not
     * something that needs fuzzy: true - a class node's own `name` is its full FQCN, so matching
     * the segment after the last backslash is what makes a bare "TenantContext" an exact hit
     * against "Fixture\Services\TenantContext" without also matching "Tenant". Shared by find()
     * and model() (both take "FQCN or short class name") - model() used to skip this and just take
     * findNodes()'s first LIKE-ranked row, which a real, large application's data showed is a real
     * bug, not a hypothetical: searching for a real Eloquent model by its short name matched an
     * unrelated console command whose class name happened to contain the same substring instead,
     * because neither is an exact hit against the literal search string under the old rule, so
     * whichever LIKE match SQLite happened to return first won.
     *
     * @param  array<int, array<string,mixed>>  $rows
     * @return array<int, array<string,mixed>>
     */
    private function exactMatches(array $rows, string $name): array
    {
        return array_values(array_filter($rows, static function (array $r) use ($name): bool {
            if ($r['key'] === $name || $r['name'] === $name) {
                return true;
            }
            $shortName = str_contains($r['name'], '\\') ? substr($r['name'], (int) strrpos($r['name'], '\\') + 1) : $r['name'];

            return $shortName === $name;
        }));
    }

    public function node(string $key): QueryResult
    {
        $rows = $this->store->findNodes($key);
        $node = null;
        foreach ($rows as $row) {
            if ($row['key'] === $key) {
                $node = $row;
                break;
            }
        }
        if ($node === null) {
            return QueryResult::notFound($this->toSuggestions($rows));
        }

        $pdo = $this->store->pdo();

        return QueryResult::found([
            'key' => $node['key'],
            'name' => $node['name'],
            'type' => $node['type'],
            'file' => $node['file_path'],
            'line' => $node['line'] !== null ? (int) $node['line'] : null,
            'meta' => json_decode((string) ($node['meta'] ?? '{}'), true) ?? [],
            'outgoing_edges' => $this->edgeTypeCounts($pdo, 'from_id', (int) $node['id']),
            'incoming_edges' => $this->edgeTypeCounts($pdo, 'to_id', (int) $node['id']),
        ]);
    }

    public function callers(string $key, int $depth, float $minConfidence): QueryResult
    {
        if ($this->store->findNodeByKey($key) === null) {
            return QueryResult::notFound($this->toSuggestions($this->store->findNodes($key)));
        }

        $depth = max(1, $depth);
        $visited = [$key => true];
        $frontier = [$key];
        $callers = [];

        for ($level = 0; $level < $depth && $frontier !== []; $level++) {
            $next = [];
            foreach ($frontier as $currentKey) {
                foreach ($this->store->callersOf($currentKey) as $edge) {
                    if ((float) $edge['confidence'] < $minConfidence) {
                        continue;
                    }
                    $callers[] = [
                        'key' => $edge['from_key'],
                        'name' => $edge['from_name'],
                        'type' => $edge['from_type'],
                        'edge_type' => $edge['type'],
                        'resolution' => $edge['resolution'],
                        'confidence' => $edge['confidence'] !== null ? (float) $edge['confidence'] : null,
                        'depth' => $level + 1,
                    ];
                    if (! isset($visited[$edge['from_key']])) {
                        $visited[$edge['from_key']] = true;
                        $next[] = $edge['from_key'];
                    }
                }
            }
            $frontier = $next;
        }

        return QueryResult::found($callers);
    }

    public function callees(string $key, int $depth): QueryResult
    {
        if ($this->store->findNodeByKey($key) === null) {
            return QueryResult::notFound($this->toSuggestions($this->store->findNodes($key)));
        }

        $depth = max(1, $depth);
        $visited = [$key => true];
        $frontier = [$key];
        $callees = [];

        for ($level = 0; $level < $depth && $frontier !== []; $level++) {
            $next = [];
            foreach ($frontier as $currentKey) {
                foreach ($this->store->calleesOf($currentKey) as $edge) {
                    $callees[] = [
                        'key' => $edge['to_key'],
                        'name' => $edge['to_name'],
                        'type' => $edge['to_type'],
                        'edge_type' => $edge['type'],
                        'resolution' => $edge['resolution'],
                        'confidence' => $edge['confidence'] !== null ? (float) $edge['confidence'] : null,
                        'depth' => $level + 1,
                    ];
                    if (! isset($visited[$edge['to_key']])) {
                        $visited[$edge['to_key']] = true;
                        $next[] = $edge['to_key'];
                    }
                }
            }
            $frontier = $next;
        }

        return QueryResult::found($callees);
    }

    public function impact(string $key, int $depth, float $minConfidence): QueryResult
    {
        $node = $this->store->findNodeByKey($key) ?? ($this->store->findNodes($key)[0] ?? null);
        if ($node === null) {
            return QueryResult::notFound($this->toSuggestions($this->store->findNodes($key)));
        }

        $depth = max(1, $depth);
        $pdo = $this->store->pdo();
        $placeholders = implode(',', array_fill(0, count(self::REVERSE_IMPACT_EDGE_TYPES), '?'));
        $stmt = $pdo->prepare(
            "SELECT source.id, source.key, source.name, source.type, source.meta, edges.type AS edge_type, edges.confidence
             FROM edges JOIN nodes source ON source.id = edges.from_id
             WHERE edges.to_id = ? AND edges.type IN ({$placeholders})
                AND (edges.confidence IS NULL OR edges.confidence >= ?)",
        );

        $visited = [(int) $node['id'] => true];
        $frontier = [(int) $node['id']];
        $found = [];

        for ($level = 1; $level <= $depth && $frontier !== []; $level++) {
            $next = [];
            foreach ($frontier as $id) {
                $stmt->execute([$id, ...self::REVERSE_IMPACT_EDGE_TYPES, $minConfidence]);
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $rowId = (int) $row['id'];
                    $found[$row['key']] ??= [
                        'key' => $row['key'],
                        'name' => $row['name'],
                        'type' => $this->classify($row['type'], $row['meta']),
                        'depth' => $level,
                        'via' => $row['edge_type'],
                    ];
                    if (! isset($visited[$rowId])) {
                        $visited[$rowId] = true;
                        $next[] = $rowId;
                    }
                }
            }
            $frontier = $next;
        }

        $grouped = ['routes' => [], 'views' => [], 'jobs' => [], 'commands' => [], 'methods' => [], 'classes' => []];
        foreach ($found as $item) {
            $bucket = match ($item['type']) {
                'route' => 'routes',
                'view' => 'views',
                'job' => 'jobs',
                'command' => 'commands',
                'method' => 'methods',
                default => 'classes',
            };
            $grouped[$bucket][] = ['key' => $item['key'], 'name' => $item['name'], 'depth' => $item['depth'], 'via' => $item['via']];
        }

        return QueryResult::found($grouped);
    }

    /** result.path is a list of {from, to, edge_type, confidence} steps, or null if none was found within max_depth. */
    public function path(string $from, string $to, int $maxDepth): QueryResult
    {
        $fromNode = $this->store->findNodeByKey($from);
        $toNode = $this->store->findNodeByKey($to);
        if ($fromNode === null || $toNode === null) {
            $suggestions = [
                ...($fromNode === null ? $this->toSuggestions($this->store->findNodes($from)) : []),
                ...($toNode === null ? $this->toSuggestions($this->store->findNodes($to)) : []),
            ];

            return QueryResult::notFound($suggestions);
        }

        if ($from === $to) {
            return QueryResult::found(['path' => [], 'length' => 0]);
        }

        $maxDepth = max(1, $maxDepth);
        $visited = [$from => true];
        $frontier = [$from];
        /** @var array<string, array{from: string, edge_type: string, confidence: ?float}> $parent */
        $parent = [];
        $reached = false;

        for ($level = 0; $level < $maxDepth && $frontier !== [] && ! $reached; $level++) {
            $next = [];
            foreach ($frontier as $currentKey) {
                foreach ($this->store->calleesOf($currentKey) as $edge) {
                    $childKey = $edge['to_key'];
                    if (isset($visited[$childKey])) {
                        continue;
                    }
                    $visited[$childKey] = true;
                    $parent[$childKey] = [
                        'from' => $currentKey,
                        'edge_type' => $edge['type'],
                        'confidence' => $edge['confidence'] !== null ? (float) $edge['confidence'] : null,
                    ];
                    if ($childKey === $to) {
                        $reached = true;
                        break 2;
                    }
                    $next[] = $childKey;
                }
            }
            $frontier = $next;
        }

        if (! $reached) {
            return QueryResult::found(['path' => null, 'length' => null, 'max_depth' => $maxDepth]);
        }

        $steps = [];
        $cursor = $to;
        while ($cursor !== $from) {
            $step = $parent[$cursor];
            $steps[] = ['from' => $step['from'], 'to' => $cursor, 'edge_type' => $step['edge_type'], 'confidence' => $step['confidence']];
            $cursor = $step['from'];
        }
        $steps = array_reverse($steps);

        return QueryResult::found(['path' => $steps, 'length' => count($steps)]);
    }

    public function route(string $uriOrName): QueryResult
    {
        $node = str_contains($uriOrName, ' ')
            ? $this->store->findNodeByKey('route:'.strtoupper(explode(' ', $uriOrName, 2)[0]).' /'.ltrim(explode(' ', $uriOrName, 2)[1], '/'))
            : $this->findRouteByName($uriOrName);

        if ($node === null) {
            return QueryResult::notFound();
        }

        $meta = json_decode((string) ($node['meta'] ?? '{}'), true) ?? [];
        $renders = $this->store->pdo()->prepare(
            "SELECT targets.name AS view FROM edges JOIN nodes targets ON targets.id = edges.to_id
             JOIN nodes source ON source.id = edges.from_id
             WHERE edges.type = 'renders' AND source.key IN (
                SELECT nodes.key FROM edges JOIN nodes ON nodes.id = edges.to_id WHERE edges.type = 'routes_to' AND edges.from_id = ?
             )",
        );
        $renders->execute([$node['id']]);

        return QueryResult::found([
            'key' => $node['key'],
            'name' => $meta['name'] ?? null,
            'action' => $meta['action'] ?? null,
            'middleware' => $meta['middleware'] ?? [],
            'domain' => $meta['domain'] ?? null,
            'views' => array_column($renders->fetchAll(\PDO::FETCH_ASSOC), 'view'),
        ]);
    }

    public function view(string $name): QueryResult
    {
        $key = 'view:'.$name;
        $node = $this->store->findNodeByKey($key);
        if ($node === null) {
            return QueryResult::notFound();
        }

        $pdo = $this->store->pdo();

        return QueryResult::found([
            'key' => $node['key'],
            'rendered_by' => $this->relatedKeys($pdo, 'renders', toId: (int) $node['id']),
            'includes' => $this->relatedKeys($pdo, 'includes', fromId: (int) $node['id']),
            'included_by' => $this->relatedKeys($pdo, 'includes', toId: (int) $node['id']),
            'components' => $this->relatedKeys($pdo, 'uses_component', fromId: (int) $node['id']),
            'links_to_routes' => $this->relatedKeys($pdo, 'links_route', fromId: (int) $node['id']),
        ]);
    }

    public function model(string $name): QueryResult
    {
        $rows = $this->store->findNodes($name, NodeType::ClassLike);
        $exact = $this->exactMatches($rows, $name);
        // model() has no fuzzy escape hatch (SPEC.md 6.1 gives it just `name`), so unlike find()
        // there is nowhere to fall back to - one exact match proceeds, anything else (zero, or
        // more than one short name colliding across namespaces) is reported as not found with
        // candidates rather than silently picking whichever row came back first.
        if (count($exact) !== 1) {
            return QueryResult::notFound($this->toSuggestions($exact !== [] ? $exact : $rows));
        }
        $node = $exact[0];

        $pdo = $this->store->pdo();
        $table = $this->relatedKeys($pdo, 'maps_table', fromId: (int) $node['id']);
        $relations = $this->relationsFrom($pdo, (int) $node['id']);
        $observers = $this->relatedKeys($pdo, 'observes', toId: (int) $node['id']);
        $touchedByFiles = $table !== [] ? $this->tablesTouchedIn($pdo, $table[0]) : [];
        $callers = $pdo->prepare('SELECT COUNT(*) FROM edges WHERE type = ? AND to_id = ?');
        $callers->execute(['calls', $node['id']]);

        return QueryResult::found([
            'key' => $node['key'],
            'table' => $table[0] ?? null,
            'relations' => $relations,
            'observers' => $observers,
            'migrations_touching_table' => $touchedByFiles,
            'call_sites' => (int) $callers->fetchColumn(),
        ]);
    }

    public function unresolved(?string $file, ?string $member, int $limit): QueryResult
    {
        $rows = $this->store->unresolvedRows($file, $member, max(1, $limit));

        return QueryResult::found(array_map(static fn (array $r): array => [
            'file' => $r['file_path'],
            'line' => $r['line'] !== null ? (int) $r['line'] : null,
            'kind' => $r['kind'],
            'expression' => $r['expression'],
            'receiver_hint' => $r['receiver_hint'],
            'member' => $r['member'],
            'candidates' => $r['candidates'] !== null ? json_decode((string) $r['candidates'], true) : null,
        ], $rows));
    }

    public function stats(): QueryResult
    {
        return QueryResult::found([
            'counts' => $this->store->counts(),
            'last_build' => $this->store->lastBuild(),
        ]);
    }

    /**
     * Wraps a QueryResult in the response contract every tool shares (SPEC.md section 6): found
     * results carry the result and, when set, a confidence; not-found results carry suggestions
     * instead, labelled as such and never substituted for a real answer. The graph block (build
     * staleness) is attached either way, so a caller always knows how current the answer is.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function envelope(string $tool, array $args, QueryResult $result): array
    {
        $graph = $this->graphBlock();

        if (! $result->found) {
            return [
                'found' => false,
                'query' => ['tool' => $tool, ...$args],
                'suggestions' => $result->suggestions,
                'graph' => $graph,
            ];
        }

        $response = [
            'found' => true,
            'query' => ['tool' => $tool, ...$args],
            'result' => $result->result,
            'graph' => $graph,
        ];
        if ($result->confidence !== null) {
            $response['confidence'] = $result->confidence;
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function graphBlock(): array
    {
        $lastBuild = $this->store->lastBuild();
        $builtCommit = $lastBuild['commit_hash'] ?? null;

        return [
            'built_commit' => $builtCommit,
            'head_commit' => $this->git->headCommit($this->projectRoot),
            'files_changed_since_build' => $this->git->filesChangedSince($this->projectRoot, $builtCommit),
            'built_at' => $lastBuild['finished_at'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string,mixed>>  $rows
     * @return array<int, array{key: string, score: float}>
     */
    private function toSuggestions(array $rows): array
    {
        return array_map(static fn (array $r): array => ['key' => $r['key'], 'score' => 0.6], array_slice($rows, 0, 5));
    }

    /** @return array<string,int> */
    private function edgeTypeCounts(\PDO $pdo, string $column, int $nodeId): array
    {
        $stmt = $pdo->prepare("SELECT type, COUNT(*) c FROM edges WHERE {$column} = ? GROUP BY type");
        $stmt->execute([$nodeId]);

        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    /** @return array<string,mixed>|null */
    private function findRouteByName(string $name): ?array
    {
        $stmt = $this->store->pdo()->prepare("SELECT * FROM nodes WHERE type = 'route' AND json_extract(meta, '$.name') = ? LIMIT 1");
        $stmt->execute([$name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return string[] */
    private function relatedKeys(\PDO $pdo, string $edgeType, ?int $fromId = null, ?int $toId = null): array
    {
        if ($fromId !== null) {
            $stmt = $pdo->prepare('SELECT targets.key FROM edges JOIN nodes targets ON targets.id = edges.to_id WHERE edges.type = ? AND edges.from_id = ?');
            $stmt->execute([$edgeType, $fromId]);
        } else {
            $stmt = $pdo->prepare('SELECT source.key FROM edges JOIN nodes source ON source.id = edges.from_id WHERE edges.type = ? AND edges.to_id = ?');
            $stmt->execute([$edgeType, $toId]);
        }

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return array<int,array{relation:string,target:string}> */
    private function relationsFrom(\PDO $pdo, int $nodeId): array
    {
        $stmt = $pdo->prepare(
            "SELECT targets.key AS target, json_extract(edges.meta, '$.relation') AS relation
             FROM edges JOIN nodes targets ON targets.id = edges.to_id
             WHERE edges.type = 'relates' AND edges.from_id = ?",
        );
        $stmt->execute([$nodeId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return string[] migration files with a touches_table edge to this table */
    private function tablesTouchedIn(\PDO $pdo, string $tableKey): array
    {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT files.path FROM edges
             JOIN nodes t ON t.key = ?
             JOIN files ON files.id = edges.file_id
             WHERE edges.type = 'touches_table' AND edges.to_id = t.id",
        );
        $stmt->execute([$tableKey]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** Job/command are not distinct node types (they are `class` nodes); this is a lightweight, one-hop heuristic, not the full category system SPEC.md section 3.4 describes. */
    private function classify(string $nodeType, ?string $metaJson): string
    {
        if ($nodeType !== 'class') {
            return $nodeType;
        }
        $meta = json_decode($metaJson ?? '{}', true) ?? [];
        foreach ($meta['interfaces'] ?? [] as $interface) {
            if (str_ends_with($interface, 'ShouldQueue')) {
                return 'job';
            }
        }
        if (str_ends_with((string) ($meta['parent'] ?? ''), '\Command') || $meta['parent'] === 'Illuminate\Console\Command') {
            return 'command';
        }

        return 'class';
    }
}
