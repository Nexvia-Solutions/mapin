<?php

declare(strict_types=1);

namespace Mapin\Store;

/**
 * The SQLite schema from SPEC.md section 5. A schema version change here must bump
 * CURRENT_VERSION so a stale database triggers a full rebuild instead of silently reading data in
 * a shape the current code no longer expects.
 */
final class Schema
{
    public const CURRENT_VERSION = 4;

    public const STATEMENTS = [
        'CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)',
        'CREATE TABLE IF NOT EXISTS files (
            id INTEGER PRIMARY KEY,
            path TEXT UNIQUE NOT NULL,
            lang TEXT NOT NULL,
            is_project INTEGER NOT NULL DEFAULT 1,
            hash TEXT NOT NULL,
            size INTEGER NOT NULL DEFAULT 0,
            extracted_at TEXT NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS nodes (
            id INTEGER PRIMARY KEY,
            type TEXT NOT NULL,
            name TEXT NOT NULL,
            key TEXT UNIQUE NOT NULL,
            file_id INTEGER REFERENCES files(id) ON DELETE CASCADE,
            line INTEGER,
            meta TEXT
        )',
        'CREATE TABLE IF NOT EXISTS edges (
            id INTEGER PRIMARY KEY,
            type TEXT NOT NULL,
            from_id INTEGER NOT NULL REFERENCES nodes(id) ON DELETE CASCADE,
            to_id INTEGER NOT NULL REFERENCES nodes(id) ON DELETE CASCADE,
            file_id INTEGER REFERENCES files(id) ON DELETE CASCADE,
            line INTEGER,
            resolution TEXT,
            confidence REAL,
            meta TEXT,
            UNIQUE(type, from_id, to_id, file_id, line)
        )',
        'CREATE TABLE IF NOT EXISTS unresolved (
            id INTEGER PRIMARY KEY,
            file_id INTEGER REFERENCES files(id) ON DELETE CASCADE,
            line INTEGER,
            kind TEXT NOT NULL,
            expression TEXT,
            receiver_hint TEXT,
            member TEXT,
            candidates TEXT
        )',
        'CREATE TABLE IF NOT EXISTS builds (
            id INTEGER PRIMARY KEY,
            started_at TEXT NOT NULL,
            finished_at TEXT,
            commit_hash TEXT,
            mode TEXT NOT NULL,
            files_seen INTEGER NOT NULL DEFAULT 0,
            files_changed INTEGER NOT NULL DEFAULT 0,
            files_affected INTEGER NOT NULL DEFAULT 0,
            nodes INTEGER NOT NULL DEFAULT 0,
            edges INTEGER NOT NULL DEFAULT 0,
            unresolved INTEGER NOT NULL DEFAULT 0,
            warnings TEXT
        )',
        // Every symbol (lowercase class FQCN, or "function:name") a file's resolution consulted
        // while resolving any of its own references, regardless of whether that resolution
        // succeeded. This is what makes an incremental build correct rather than merely fast: when
        // a changed file adds, removes or changes a symbol, this table finds every unchanged file
        // whose earlier resolution depended on that symbol, so it gets re-resolved too - see
        // SPEC.md section 5 and 1.4.
        'CREATE TABLE IF NOT EXISTS symbol_deps (
            file_id INTEGER NOT NULL REFERENCES files(id) ON DELETE CASCADE,
            symbol TEXT NOT NULL,
            PRIMARY KEY (file_id, symbol)
        )',
        'CREATE INDEX IF NOT EXISTS symbol_deps_symbol ON symbol_deps(symbol)',
        'CREATE INDEX IF NOT EXISTS edges_from ON edges(from_id, type)',
        'CREATE INDEX IF NOT EXISTS edges_to ON edges(to_id, type)',
        'CREATE INDEX IF NOT EXISTS nodes_type ON nodes(type, name)',
        'CREATE INDEX IF NOT EXISTS nodes_file ON nodes(file_id)',
        // One row per query that Query::envelope() answered with found: false - never for a query
        // whose result is merely an empty list (callers/impact/etc. on a real node with zero results
        // is still found: true, see Query.php's own found()/notFound() split). Purely local and never
        // sent anywhere on its own - only `mapin:misses` (SPEC.md section 13's one deliberate
        // exception to "never write outside storage/") reads this, and only when a human runs it.
        'CREATE TABLE IF NOT EXISTS query_misses (
            id INTEGER PRIMARY KEY,
            tool TEXT NOT NULL,
            args TEXT,
            suggestions TEXT,
            occurred_at TEXT NOT NULL,
            exported_at TEXT
        )',
        'CREATE INDEX IF NOT EXISTS query_misses_exported ON query_misses(exported_at)',

        // A file that fails to parse/compile during mapin:build (SPEC.md 1.17/1.21's own
        // graceful-degradation path) already produces a warning string every time it happens, but
        // `builds.warnings` only ever holds the one build row's own JSON blob - the exact same
        // problem query_misses solved for found:false, adapted for how build warnings actually
        // recur: the SAME broken file produces the SAME warning on every subsequent build until
        // someone fixes it (and with mapin:install-hooks, that can be many times a day), so this
        // is keyed by the warning text itself (UNIQUE) and upserted, not appended - one row per
        // distinct problem, `last_seen_at` moving forward on every build it still occurs, not a
        // new row each time. mapin:warnings (mirroring mapin:misses) is the one deliberate
        // exception, same as query_misses, to "never write outside storage/".
        'CREATE TABLE IF NOT EXISTS build_warnings (
            id INTEGER PRIMARY KEY,
            warning TEXT NOT NULL UNIQUE,
            first_seen_at TEXT NOT NULL,
            last_seen_at TEXT NOT NULL,
            exported_at TEXT
        )',
        'CREATE INDEX IF NOT EXISTS build_warnings_exported ON build_warnings(exported_at)',
    ];

    private const TABLES = ['symbol_deps', 'unresolved', 'edges', 'nodes', 'files', 'builds', 'meta', 'query_misses', 'build_warnings'];

    public static function install(\PDO $pdo): void
    {
        foreach (self::STATEMENTS as $statement) {
            $pdo->exec($statement);
        }
        $stmt = $pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $stmt->execute(['schema_version', (string) self::CURRENT_VERSION]);
    }

    /**
     * Drops every table this schema knows about. `install()` only ever creates what is missing, so
     * an older on-disk schema (a table present but with fewer columns than the current shape
     * expects) must be dropped first - the docblock above promises a stale database triggers a
     * full rebuild, not a best-effort column migration.
     */
    public static function reset(\PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = OFF');
        foreach (self::TABLES as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    public static function isCurrent(\PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SELECT value FROM meta WHERE key = 'schema_version'");
        } catch (\PDOException) {
            // A brand new database file has no tables yet - that just means the schema is missing,
            // not that something went wrong.
            return false;
        }
        $version = $stmt !== false ? $stmt->fetchColumn() : false;

        return $version !== false && (int) $version === self::CURRENT_VERSION;
    }
}
