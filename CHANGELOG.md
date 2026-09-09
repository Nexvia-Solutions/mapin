# Changelog

All notable changes to this project are documented here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- `mapin:warnings`: `mapin:misses`'s sibling for the other kind of signal a build already
  produces and used to lose - a file that fails to parse/compile (never aborts the build itself,
  but the warning only ever lived inside that one build's own row in `builds`, easy to miss and
  never deduplicated across however many builds a real file keeps failing on until someone fixes
  it). Keyed by the warning text itself and upserted, not appended, so the same recurring problem
  stays one row (`last_seen_at` moving forward) instead of flooding the table. Same host-project
  export boundary and the same "never write outside storage/, never a network call" discipline as
  `mapin:misses`. See SPEC.md section 1.22.

- `BladeExtractor` is compiler-based now, the design section 4 always described but phase 2 never
  built (regex was "enough to pass every case tested" at the time). The regex scanner survives as
  `DirectiveScanner`, still the structural source (`@include`/`@extends`/component tags - Blade
  compiles these to calls on its own `$__env` runtime variable, not `view()`, so a generic PHP
  reference visitor has nothing to recognize there) and still the documented `--no-boot` fallback.
  What compiling adds: `route()`, `view()`, `app()`, method calls, `new` - anything written as
  literal PHP inside `{{ }}`, `{!! !!}`, or an `@php` block, invisible to regex by construction.
  Closes the real gap disclosed since section 1.8. See SPEC.md section 1.21 for the full write-up,
  including two problems found only by testing against a real application at scale: Blade's own
  compiled runtime calls (`$__env->`, `$attributes->`, `$errors->`, others) would have flooded
  `unresolved` with pure noise without an explicit filter, and `.blade.php` files never had a
  generic `file:` node the way `.php` files do - a pre-existing, previously-disclosed gap that
  silently dropped this extractor's own `Declares` edge and would have dropped every new edge the
  same way; both fixed as part of this change.

## [0.2.1] - 2026-09-08

### Added

- `mapin:install-hooks`: installs `post-commit`/`post-merge` git hooks that rebuild the graph
  incrementally in the background (never blocking the commit/merge itself), closing the gap
  disclosed since the first release - unlike its retired predecessor, this package never rebuilt
  itself automatically. `--command` is configurable rather than hardcoded to a bare `php artisan`
  call, since a real host application may run entirely inside Docker. Never overwrites an existing
  hook wholesale - the installed block is uniquely marked and only that block is touched on
  reinstall. See SPEC.md section 1.16.

### Fixed

- A vendor (non-project) class's own methods - and their return types - were extracted correctly
  but never persisted to `nodes.meta`, and the only mechanism that could rebuild them on an
  incremental build (per-method `method:` nodes) has only ever existed for project classes. In
  practice: after a project's first `--full`, every incremental build silently resolved chained
  calls through vendor code (`redirect()->back()->withInput()`, or any similar chain through any
  vendor method) a little worse than the one before, with no file of the caller's own needing to
  change. Found auditing unresolved calls against a real application - `redirect()`/`back()` alone
  accounted for 3.4% of them. Fixed by persisting a vendor class's own methods (only vendor -
  project classes already get this back for free from their `method:` nodes, so this avoids
  duplicating it there). See SPEC.md section 1.17.

- `SqliteStore::findNodes()`'s own `ORDER BY` compared a short-name search (e.g. `"ServiceOrder"`)
  against a node's full `key` (`"class:App\Models\ServiceOrder"`), which can never match - a
  silent no-op for the single most common way `find()` is actually called. On a real application
  with hundreds of nodes sharing a common substring, the exact node could land outside the
  `LIMIT 25` cut before `Query::exactMatches()` (itself correct) ever got a chance to see it.
  Reported from real use: `mapin:find "ServiceOrder"` answered not-found for a plain Eloquent
  model that genuinely existed, with the graph fully up to date. Fixed by adding two more ranking
  terms mirroring the same rules `exactMatches()` already applies in PHP. See SPEC.md section 1.18.

- `mapin:mcp` never completed a handshake with a classic MCP client (Claude Code's included, per a
  real report) - `laravel/mcp` v1.0.0-beta.1 dropped the `initialize` method entirely in favor of
  MCP 2026-07-28's per-request `_meta.protocolVersion`, an upstream, deliberate BC break
  (github.com/laravel/mcp pull #296), not a Mapin bug. Fixed entirely inside `MapinServer`: a
  registered classic `initialize` handler, plus treating `_meta`'s outright absence on any request
  as the legacy-client signal it actually is, so the rest of a legacy session (`tools/list`,
  `tools/call`, ...) isn't held to a per-request mechanism that protocol never had. The modern
  `2026-07-28` path is untouched - still validated exactly as strictly as before. See SPEC.md
  section 1.19.

- `mapin:doctor`'s `graph_staleness` check reported stale on every graph, including one built the
  same second the check ran - it compared `built_commit` (a 12-char prefix,
  `BuildRunner::currentCommit()`) against `head_commit` (the full 40-char SHA,
  `GitStatus::headCommit()`) with plain `!==`, which can never be equal even for the same commit.
  Fixed with `str_starts_with()`, correct regardless of which length `built_commit` happens to be.
  See SPEC.md section 1.20.

## [0.2.0] - 2026-09-08

### Added

- `mapin:misses`: tracks every query the graph answered with `found: false` (never a merely-empty
  result on a real node - see SPEC.md section 1.15 for how that distinction is preserved) in a new
  local `query_misses` table (schema bump to version 3), and exports what hasn't been exported yet
  to a JSONL file inside the *host* project (default `docs/mapin-misses.jsonl`) so a team can review
  real gaps together. Purely local start to finish - no network call, ever; see SPEC.md section 13
  point 7.

## [0.1.1] - 2026-09-08

### Fixed

- `mapin:build`'s own reported node/edge counts (and what it records into `builds`) could
  significantly overstate the graph's real size - found 2026-09-08 running it twice against a real
  application, before and after a `git rebase`, and seeing the reported total drop in a way that
  looked like data loss but wasn't: `SqliteStore::counts()` (a real `SELECT COUNT(*)`) was correct
  the whole time. Three real sources of the same shape of bug, all in how a count was computed, not
  in what got written: `RouteExtractor` pushed one `Node` per route×middleware combination instead
  of deduplicating by middleware alias (inflated a real application's own report by roughly 11,000);
  extraNodes (`view()`, `DB::table()`, external classes) are only deduplicated within the one file
  that produced them, so two files referencing the same one each counted it as new; and
  `SqliteStore::insertEdges()` counted every row it attempted rather than the rows that actually
  landed - including, once fixed to deduplicate by the same tuple the table's own `UNIQUE` index
  resolves on, a second bug that fix itself introduced: `line` is nullable, and SQLite treats every
  `NULL` as distinct from every other `NULL` in a `UNIQUE` index, so a PHP-side tuple dedup silently
  undercounted rows that share `line: null` (or a null `file_id`, the shape route/binding edges take)
  by treating two such rows as one. Fixed by counting what `upsertNodes()` actually returns (a
  key-to-id map, deduplicated by construction, tracked across the whole build so a key repeating in
  a later file doesn't count twice either) for nodes, and by measuring a real `COUNT(*)` delta
  around `insertEdges()`'s own inserts for edges, rather than reproducing SQLite's own null-handling
  in PHP. Verified against a real application: reported totals now match `counts()` exactly
  (25,706 nodes, 74,250 edges, both previously overstated).

## [0.1.0] - 2026-09-08

First public release. Everything below shipped in the same initial development push - phases 0
through 8 of [SPEC.md](SPEC.md), each with its own results write-up (SPEC.md section 1) covering
what was built, what was verified against a real Laravel application, and every real bug found
along the way. Phase numbers below match those write-ups.

### Added

- **Core** (phase 1): incremental, content-hash-based graph build over a Laravel application's
  classes, methods, functions, calls, `new`, and dependency injection, stored in SQLite.
  `mapin:build`, `mapin:stats`, `mapin:find`, `mapin:callers`.
- **Laravel extractors** (phase 2): routes and their middleware, container bindings, Blade views
  (includes, components, layouts), Eloquent relations, table mapping, migrations, dispatched
  jobs and events, listeners, observers, scheduled commands. `mapin:route`, `mapin:view`,
  `mapin:model`, `mapin:impact`.
- **Query layer and MCP server** (phase 3): every query as one shared service behind both the CLI
  and an MCP server (`mapin:mcp`) exposed to Claude Code, Cursor, or any MCP client - a CLI answer
  and an MCP answer to the same question never diverge. `mapin:query`, `mapin:doctor`.
- **Markdown module** (phase 4): `doc`/`section` nodes, links between docs, and mentions of a
  known class, route, table, view or file in section text - an ambiguous short name lands in
  `unresolved`, never a guess. `mapin:docs`, the `docs` tool.
- **Community detection and hubs** (phase 5): Louvain community detection and hub/bridge degree
  over the call and injection graph, deterministic across runs, run on demand and never during a
  plain build. `mapin:communities`, the `hubs` and `communities` tools.
- **Semantic docs** (phase 6, opt-in via `mapin:docs --with-llm`): concepts and relations between
  them extracted from Markdown section text only, never PHP source, and never without the flag.
  Local by default (`NullClient`); one provider driver ships (Ollama).
- **JavaScript extraction** (phase 7): `fetch`/`axios`/jQuery `$.ajax` calls with a literal URL,
  matched to the route they request and included in `impact`.
- **Release polish** (phase 8): third-party extractors, registered via `config('mapin.extractors')`
  and run alongside the built-in ones rather than replacing them; README, README.es, CONTRIBUTING,
  and `docs/EXTENDING.md`; a working CI matrix across Laravel 11/12/13 and PHP 8.2-8.4.

### Fixed

Real bugs found while verifying each phase against a real Laravel application, not hypothetical -
the most consequential are documented in their own SPEC.md sections, not buried here. A sample of
what "verified against something real" actually caught: forward-reference edges silently dropped
across files in the same build; `model()` returning an unrelated class on an ambiguous short-name
search instead of reporting not-found; a Markdown-module bug that let 10,000+ documentation nodes
pollute an unrelated community-detection analysis; a community/hub filter that was a denylist
instead of an allowlist, letting framework noise through; a JS extractor that discarded an
explicit `axios.get`/`axios.post` verb in favor of guessing from the URL alone; and the project's
own CI, which had never actually been run against real GitHub infrastructure until this release
was being prepared, and needed three separate real fixes once it was.

[Unreleased]: https://github.com/Nexvia-Solutions/mapin/compare/v0.2.1...main
[0.2.1]: https://github.com/Nexvia-Solutions/mapin/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/Nexvia-Solutions/mapin/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/Nexvia-Solutions/mapin/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/Nexvia-Solutions/mapin/releases/tag/v0.1.0
