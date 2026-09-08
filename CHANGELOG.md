# Changelog

All notable changes to this project are documented here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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

[Unreleased]: https://github.com/Nexvia-Solutions/mapin/compare/v0.1.0...main
[0.1.0]: https://github.com/Nexvia-Solutions/mapin/releases/tag/v0.1.0
