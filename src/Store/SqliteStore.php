<?php

declare(strict_types=1);

namespace Mapin\Store;

use Mapin\Extract\ClassMeta;
use Mapin\Extract\Contracts\SourceFile;
use Mapin\Extract\Contracts\UnresolvedRow;
use Mapin\Extract\SymbolIndex;
use Mapin\Graph\Edge;
use Mapin\Graph\Node;
use Mapin\Graph\NodeType;

final class SqliteStore
{
    private \PDO $pdo;

    public function __construct(private readonly string $path)
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->pdo = $this->open();
    }

    private function open(): \PDO
    {
        $pdo = new \PDO('sqlite:'.$this->path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        if (! Schema::isCurrent($pdo)) {
            Schema::reset($pdo);
            Schema::install($pdo);
        }

        return $pdo;
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string,string> relative path => content hash, for every stored file */
    public function fileHashes(): array
    {
        $stmt = $this->pdo->query('SELECT path, hash FROM files');

        return $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_KEY_PAIR) : [];
    }

    /** @param string[] $paths */
    public function deleteFiles(array $paths): void
    {
        if ($paths === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($paths), '?'));
        $this->pdo->prepare("DELETE FROM files WHERE path IN ($placeholders)")->execute(array_values($paths));
    }

    public function upsertFile(SourceFile $file, int $size): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO files (path, lang, is_project, hash, size, extracted_at) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(path) DO UPDATE SET lang = excluded.lang, is_project = excluded.is_project,
                hash = excluded.hash, size = excluded.size, extracted_at = excluded.extracted_at',
        );
        $stmt->execute([$file->relativePath, $file->lang, (int) $file->isProject, $file->hash, $size, gmdate('c')]);

        return $this->fileId($file->relativePath);
    }

    public function fileId(string $path): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM files WHERE path = ?');
        $stmt->execute([$path]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException("Mapin: file not found in store: {$path}");
        }

        return (int) $id;
    }

    /**
     * @param  Node[]  $nodes
     * @return array<string,int> key to id
     */
    public function upsertNodes(array $nodes, ?int $fileId): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO nodes (type, name, key, file_id, line, meta) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(key) DO UPDATE SET type = excluded.type, name = excluded.name,
                file_id = excluded.file_id, line = excluded.line, meta = excluded.meta',
        );
        foreach ($nodes as $node) {
            $stmt->execute([$node->type->value, $node->name, $node->key, $fileId, $node->line, $node->meta === [] ? null : json_encode($node->meta)]);
        }

        return $this->nodeIds(array_map(static fn (Node $n) => $n->key, $nodes));
    }

    /**
     * @param  string[]  $keys
     * @return array<string,int>
     */
    public function nodeIds(array $keys): array
    {
        $keys = array_values(array_unique($keys));
        $result = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare("SELECT key, id FROM nodes WHERE key IN ($placeholders)");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(\PDO::FETCH_KEY_PAIR) as $key => $id) {
                $result[$key] = (int) $id;
            }
        }

        return $result;
    }

    /** @return string[] node keys currently stored for this file */
    public function nodeKeysForFile(int $fileId): array
    {
        $stmt = $this->pdo->prepare('SELECT key FROM nodes WHERE file_id = ?');
        $stmt->execute([$fileId]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Removes nodes that belonged to this file but were not produced by the latest extraction.
     *
     * @param  string[]  $survivingKeys
     */
    public function pruneStaleNodes(int $fileId, array $survivingKeys): void
    {
        $existing = $this->nodeKeysForFile($fileId);
        $stale = array_diff($existing, $survivingKeys);
        if ($stale === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($stale), '?'));
        $this->pdo->prepare("DELETE FROM nodes WHERE file_id = ? AND key IN ($placeholders)")
            ->execute([$fileId, ...array_values($stale)]);
    }

    public function clearFileOutput(int $fileId): void
    {
        $this->pdo->prepare('DELETE FROM edges WHERE file_id = ?')->execute([$fileId]);
        $this->pdo->prepare('DELETE FROM unresolved WHERE file_id = ?')->execute([$fileId]);
    }

    /**
     * @param  Edge[]  $edges
     * @param  array<string,int>  $nodeIds
     */
    public function insertEdges(array $edges, array $nodeIds, ?int $fileId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO edges (type, from_id, to_id, file_id, line, resolution, confidence, meta) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(type, from_id, to_id, file_id, line) DO UPDATE SET resolution = excluded.resolution,
                confidence = excluded.confidence, meta = excluded.meta',
        );
        $count = 0;
        foreach ($edges as $edge) {
            $fromId = $nodeIds[$edge->fromKey] ?? null;
            $toId = $nodeIds[$edge->toKey] ?? null;
            if ($fromId === null || $toId === null) {
                continue;
            }
            $stmt->execute([
                $edge->type->value, $fromId, $toId, $fileId, $edge->line,
                $edge->resolution?->value, $edge->confidence, $edge->meta === [] ? null : json_encode($edge->meta),
            ]);
            $count++;
        }

        return $count;
    }

    /** @param UnresolvedRow[] $rows */
    public function insertUnresolved(array $rows, int $fileId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO unresolved (file_id, line, kind, expression, receiver_hint, member, candidates) VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        foreach ($rows as $row) {
            $stmt->execute([$fileId, $row->line, $row->kind, $row->expression, $row->receiverHint, $row->member, $row->candidates === [] ? null : json_encode($row->candidates)]);
        }

        return count($rows);
    }

    /**
     * Hydrates a SymbolIndex from every class/function node NOT belonging to one of the given
     * file ids, without re-parsing anything - this is what makes an incremental build fast.
     *
     * @param  int[]  $excludeFileIds
     */
    public function hydrateIndex(SymbolIndex $index, array $excludeFileIds = []): void
    {
        $exclude = $excludeFileIds === [] ? '' : ' AND file_id NOT IN ('.implode(',', array_fill(0, count($excludeFileIds), '?')).')';

        $classes = $this->pdo->prepare("SELECT key, name, file_id, line, meta FROM nodes WHERE type = 'class'{$exclude}");
        $classes->execute($excludeFileIds);
        $metaByKey = [];
        foreach ($classes->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $meta = json_decode($row['meta'] ?? '{}', true) ?? [];
            $class = ClassMeta::fromMeta($row['name'], $meta, null, (int) $row['line']);
            $metaByKey[$row['key']] = $class;
        }

        if ($metaByKey !== []) {
            $methods = $this->pdo->prepare("SELECT key, name, meta FROM nodes WHERE type = 'method'{$exclude}");
            $methods->execute($excludeFileIds);
            foreach ($methods->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $classKey = substr($row['key'], 0, strrpos($row['key'], '::'));
                $classKey = str_replace('method:', 'class:', $classKey);
                if (isset($metaByKey[$classKey])) {
                    $meta = json_decode($row['meta'] ?? '{}', true) ?? [];
                    $metaByKey[$classKey]->addMethod($row['name'], $meta);
                }
            }
        }

        foreach ($metaByKey as $class) {
            $index->addClass($class);
        }

        $functions = $this->pdo->prepare("SELECT name, meta FROM nodes WHERE type = 'function'{$exclude}");
        $functions->execute($excludeFileIds);
        foreach ($functions->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $meta = json_decode($row['meta'] ?? '{}', true) ?? [];
            $index->addFunction($row['name'], $meta['return'] ?? null);
        }
    }

    /**
     * The symbols (lowercase class FQCNs, plus each class's own implemented interfaces, plus
     * "function:name" for functions) declared by these files as currently stored - read this
     * before anything in this build overwrites their nodes, so it reflects what was true before
     * the change. Used with the fresh set from the new extraction to build the full set of symbols
     * that changed, which SqliteStore::filesDependingOn() then resolves to affected files.
     *
     * @param  int[]  $fileIds
     * @return string[]
     */
    public function declaredSymbolsForFiles(array $fileIds): array
    {
        $fileIds = array_values($fileIds);
        if ($fileIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $stmt = $this->pdo->prepare("SELECT type, name, meta FROM nodes WHERE file_id IN ({$placeholders}) AND type IN ('class', 'function')");
        $stmt->execute($fileIds);
        $symbols = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($row['type'] === 'function') {
                $symbols[] = 'function:'.strtolower($row['name']);

                continue;
            }
            $symbols[] = strtolower($row['name']);
            $meta = json_decode($row['meta'] ?? '{}', true) ?? [];
            foreach ($meta['interfaces'] ?? [] as $interface) {
                $symbols[] = strtolower($interface);
            }
        }

        return array_values(array_unique($symbols));
    }

    /**
     * Replaces the set of symbols this file's resolution depended on. Called once per file that
     * gets (re)resolved this build, changed or merely affected - a file with no dependencies left
     * (every reference resolved with zero index lookups, or the file has none) clears its rows.
     *
     * @param  string[]  $symbols
     */
    public function replaceSymbolDeps(int $fileId, array $symbols): void
    {
        $this->pdo->prepare('DELETE FROM symbol_deps WHERE file_id = ?')->execute([$fileId]);
        $symbols = array_values(array_unique($symbols));
        if ($symbols === []) {
            return;
        }
        $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO symbol_deps (file_id, symbol) VALUES (?, ?)');
        foreach ($symbols as $symbol) {
            $stmt->execute([$fileId, $symbol]);
        }
    }

    /**
     * Every stored file id whose resolution consulted at least one of these symbols - the files an
     * incremental build must re-resolve even though their own content did not change, because a
     * changed file added, removed or changed one of the symbols they depend on.
     *
     * @param  string[]  $symbols
     * @return int[]
     */
    public function filesDependingOn(array $symbols): array
    {
        $symbols = array_values(array_unique($symbols));
        if ($symbols === []) {
            return [];
        }
        $found = [];
        foreach (array_chunk($symbols, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare("SELECT DISTINCT file_id FROM symbol_deps WHERE symbol IN ({$placeholders})");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                $found[(int) $id] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * @param  int[]  $fileIds
     * @return array<int, array{path: string, lang: string, is_project: bool, hash: string}>
     */
    public function filesById(array $fileIds): array
    {
        $fileIds = array_values($fileIds);
        if ($fileIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $stmt = $this->pdo->prepare("SELECT id, path, lang, is_project, hash FROM files WHERE id IN ({$placeholders})");
        $stmt->execute($fileIds);
        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['id']] = [
                'path' => $row['path'],
                'lang' => $row['lang'],
                'is_project' => (bool) $row['is_project'],
                'hash' => $row['hash'],
            ];
        }

        return $result;
    }

    /** @param string[] $warnings */
    public function recordBuild(string $startedAt, string $mode, int $filesSeen, int $filesChanged, int $filesAffected, int $nodes, int $edges, int $unresolved, array $warnings, ?string $commit): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO builds (started_at, finished_at, commit_hash, mode, files_seen, files_changed, files_affected, nodes, edges, unresolved, warnings)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([$startedAt, gmdate('c'), $commit, $mode, $filesSeen, $filesChanged, $filesAffected, $nodes, $edges, $unresolved, $warnings === [] ? null : json_encode($warnings)]);
    }

    /** @return array<string,mixed>|null */
    public function lastBuild(): ?array
    {
        $stmt = $this->pdo->query('SELECT * FROM builds ORDER BY id DESC LIMIT 1');
        $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;

        return $row === false ? null : $row;
    }

    /**
     * Route and middleware nodes/edges are not owned by one file (routes come from the booted
     * router as a whole, SPEC.md 4), so incremental hash-diffing does not apply to them: every
     * booted build re-extracts the complete route table and this clears the previous one first,
     * rather than diffing which routes changed.
     */
    public function clearRouteData(): void
    {
        $this->pdo->exec("DELETE FROM edges WHERE type IN ('routes_to', 'uses_middleware')");
        $this->pdo->exec("DELETE FROM nodes WHERE type IN ('route', 'middleware')");
    }

    /** Same reasoning as clearRouteData(): the booted container's binding table is small and cheap to fully re-read every build. */
    public function clearBindingData(): void
    {
        $this->pdo->exec("DELETE FROM edges WHERE type = 'binds'");
    }

    /** @return array<string,string> route name => route node key, for every currently stored route that has one */
    public function routeKeysByName(): array
    {
        $stmt = $this->pdo->query("SELECT key, meta FROM nodes WHERE type = 'route'");
        $result = [];
        foreach ($stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [] as $row) {
            $meta = json_decode($row['meta'] ?? '{}', true) ?? [];
            if (! empty($meta['name'])) {
                $result[$meta['name']] = $row['key'];
            }
        }

        return $result;
    }

    /** @return array<string,int> */
    public function counts(): array
    {
        return [
            'files' => (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn(),
            'nodes' => (int) $this->pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn(),
            'edges' => (int) $this->pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn(),
            'unresolved' => (int) $this->pdo->query('SELECT COUNT(*) FROM unresolved')->fetchColumn(),
        ];
    }

    /**
     * Docs-focused slice of counts(), for `mapin:docs` (SPEC.md section 9) to report on the
     * Markdown module specifically rather than the whole graph.
     *
     * @return array<string,int>
     */
    public function docsCounts(): array
    {
        $unresolvedStmt = $this->pdo->prepare("SELECT COUNT(*) FROM unresolved WHERE kind IN ('documents', 'links_doc')");
        $unresolvedStmt->execute();

        return [
            'docs' => (int) $this->pdo->query("SELECT COUNT(*) FROM nodes WHERE type = 'doc'")->fetchColumn(),
            'sections' => (int) $this->pdo->query("SELECT COUNT(*) FROM nodes WHERE type = 'section'")->fetchColumn(),
            'documents_edges' => (int) $this->pdo->query("SELECT COUNT(*) FROM edges WHERE type = 'documents'")->fetchColumn(),
            'links_doc_edges' => (int) $this->pdo->query("SELECT COUNT(*) FROM edges WHERE type = 'links_doc'")->fetchColumn(),
            'unresolved' => (int) $unresolvedStmt->fetchColumn(),
            // Always reported, whether or not --with-llm ran this particular invocation - a
            // standing view of how much semantic data the graph already carries from any past run.
            'concepts' => (int) $this->pdo->query("SELECT COUNT(*) FROM nodes WHERE type = 'concept'")->fetchColumn(),
            'mentions_edges' => (int) $this->pdo->query("SELECT COUNT(*) FROM edges WHERE type = 'mentions'")->fetchColumn(),
            'relates_concept_edges' => (int) $this->pdo->query("SELECT COUNT(*) FROM edges WHERE type = 'relates_concept'")->fetchColumn(),
        ];
    }

    /** @return array<string,mixed>[] */
    public function findNodes(string $name, ?NodeType $type = null): array
    {
        $sql = 'SELECT nodes.*, files.path AS file_path FROM nodes LEFT JOIN files ON files.id = nodes.file_id
                WHERE (nodes.key = :exact OR nodes.name = :name OR nodes.key LIKE :like)';
        $params = ['exact' => $name, 'name' => $name, 'like' => '%'.$name.'%'];
        if ($type !== null) {
            $sql .= ' AND nodes.type = :type';
            $params['type'] = $type->value;
        }
        $sql .= ' ORDER BY (nodes.key = :exact2) DESC LIMIT 25';
        $params['exact2'] = $name;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function findNodeByKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM nodes WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>[] edges pointing at this node, with the caller node's key/name joined in */
    public function callersOf(string $key, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT edges.*, callers.key AS from_key, callers.name AS from_name, callers.type AS from_type
             FROM edges JOIN nodes callers ON callers.id = edges.from_id
             JOIN nodes targets ON targets.id = edges.to_id
             WHERE targets.key = ? ORDER BY edges.confidence DESC LIMIT ?',
        );
        $stmt->bindValue(1, $key, \PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>[] edges this node points at, with the target node's key/name joined in */
    public function calleesOf(string $key, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT edges.*, targets.key AS to_key, targets.name AS to_name, targets.type AS to_type
             FROM edges JOIN nodes sources ON sources.id = edges.from_id
             JOIN nodes targets ON targets.id = edges.to_id
             WHERE sources.key = ? ORDER BY edges.confidence DESC LIMIT ?',
        );
        $stmt->bindValue(1, $key, \PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>[] */
    public function unresolvedRows(?string $file, ?string $member, int $limit): array
    {
        $sql = 'SELECT unresolved.*, files.path AS file_path FROM unresolved
                LEFT JOIN files ON files.id = unresolved.file_id WHERE 1=1';
        $params = [];
        if ($file !== null) {
            $sql .= ' AND files.path LIKE ?';
            $params[] = '%'.$file.'%';
        }
        if ($member !== null) {
            $sql .= ' AND unresolved.member = ?';
            $params[] = $member;
        }
        $sql .= ' ORDER BY unresolved.id DESC LIMIT ?';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value, \PDO::PARAM_STR);
        }
        $stmt->bindValue(count($params) + 1, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function findRouteByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM nodes WHERE type = 'route' AND json_extract(meta, '$.name') = ? LIMIT 1");
        $stmt->execute([$name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Every route node whose URI matches, regardless of HTTP method - a route key embeds the
     * method (`route:GET /admin/orders`), but a bare URI mentioned in prose does not specify one,
     * so more than one match here is a genuine ambiguity, not a bug.
     *
     * @return array<string,mixed>[]
     */
    public function findRoutesByUri(string $uri): array
    {
        $uri = '/'.ltrim($uri, '/');
        $stmt = $this->pdo->prepare("SELECT * FROM nodes WHERE type = 'route' AND key LIKE 'route:% ' || ? ESCAPE '\\'");
        $stmt->execute([str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $uri)]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>[] every `doc` node with its file path joined in - LlmConceptExtractor's own entry point into what Markdown files exist to re-read. */
    public function docNodes(): array
    {
        $stmt = $this->pdo->query(
            "SELECT nodes.*, files.path AS file_path FROM nodes JOIN files ON files.id = nodes.file_id WHERE nodes.type = 'doc'",
        );

        return $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    /**
     * Merges into an existing node's stored meta rather than replacing it - unlike upsertNodes(),
     * which always overwrites a node's whole meta column. LlmConceptExtractor uses this to stamp a
     * section's `llm_hash` onto the exact same node MarkdownExtractor already wrote `anchor`/`level`
     * into, without a second write ever being able to clobber the first regardless of which ran more
     * recently.
     *
     * @param  array<string,mixed>  $meta
     */
    public function mergeNodeMeta(int $nodeId, array $meta): void
    {
        $stmt = $this->pdo->prepare('SELECT meta FROM nodes WHERE id = ?');
        $stmt->execute([$nodeId]);
        $current = json_decode((string) ($stmt->fetchColumn() ?: '{}'), true) ?? [];
        $merged = [...$current, ...$meta];
        $this->pdo->prepare('UPDATE nodes SET meta = ? WHERE id = ?')->execute([json_encode($merged), $nodeId]);
    }

    /**
     * Deletes the `mentions`/`relates_concept` edges a specific section previously produced, keyed
     * by (file_id, line) - the section's own heading line, the same attribution MarkdownExtractor
     * already uses to scope `documents`/`links_doc` edges to one section. Called before regenerating
     * a section whose content hash changed, so a stale concept link never survives next to a fresh one.
     */
    public function clearSectionConceptEdges(int $fileId, int $line): void
    {
        $this->pdo->prepare("DELETE FROM edges WHERE file_id = ? AND line = ? AND type IN ('mentions', 'relates_concept')")
            ->execute([$fileId, $line]);
    }
}
