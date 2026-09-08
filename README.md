# Mapin

A queryable code graph for Laravel applications: dependency injection, container bindings, routes,
views, Eloquent relations, Markdown docs and JavaScript-to-route calls, connected the way a
generic tree-sitter-based tool cannot see.

[English](README.md) | [Español](README.es.md)

## Why

Generic code graph tools only see what is visible in a single file's own syntax. Most of the
wiring that actually matters in a real Laravel application happens through the container, through
strings (`route('name')`, `view('name')`), and through convention (`hasMany`, `$listen`,
`Route::get`), none of which a syntax-only parser ever sees. Measured on a real application of
about 900 PHP files, a generic tree-sitter extractor captured static calls and nothing else: zero
edges for dependency injection, `new`, member calls, routes, views, Blade includes or Eloquent
relations. Mapin exists to cover exactly that gap, for an AI coding agent asking "what breaks if I
change this" over MCP, or a developer asking the same thing from the CLI - and to say so honestly
when it does not know: `found: false` with labelled suggestions, never a look-alike match dressed
up as the real answer.

## Requirements

- PHP 8.2 or newer
- Laravel 11.45+, 12, or 13
- `ext-pdo` and `ext-sqlite3` (bundled with PHP on almost every install)

Laravel 13 support is verified against a real application, not yet by the automated test suite -
`orchestra/testbench`, the package this project's own tests use to simulate a Laravel application,
has no release supporting Laravel 13 yet as of this writing. Runtime code does not depend on
Testbench at all; only this package's own dev-time test suite does. See
[SPEC.md section 1.14](SPEC.md) for the full, current detail.

## Install

This package requires `laravel/mcp`, which as of this writing has no stable release yet (only
`v1.0.0-beta.1`). If your app uses Composer's default `minimum-stability: stable` - almost every
Laravel app does - `composer require` will refuse to resolve it until you allow pre-release
versions for the packages that need them:

```bash
composer config minimum-stability beta
composer config prefer-stable true
composer require nexvia-solutions/mapin --dev
```

`prefer-stable: true` keeps every other dependency on its latest stable release; it only lets
`laravel/mcp` (and anything else without a stable release) resolve to a pre-release instead of
failing outright. This is a real, ongoing requirement tied to `laravel/mcp`'s own release status,
not a one-time setup step you can skip once installed - see
[SPEC.md section 1.14](SPEC.md) for how this was found.

The service provider registers itself automatically (Laravel package auto-discovery). Publish the
config if you want to change the defaults - indexed paths, storage location, the semantic layer's
LLM driver, community detection's hub percentile:

```bash
php artisan vendor:publish --tag=mapin-config
```

## Quick start

```bash
# Build the graph - full the first time, incremental (by content hash) every time after
php artisan mapin:build --full

# Ask something
php artisan mapin:find BookController
php artisan mapin:route books.store
php artisan mapin:impact 'method:App\Services\Billing::charge'

# Any tool from the full list, JSON always
php artisan mapin:query hubs --arg=limit=10 --json

# Health check before pointing a client at it
php artisan mapin:doctor
```

`mapin:doctor`'s `unresolved_ratio` check can fail right after install on an app with little code
of its own yet (a fresh skeleton, or a new module) - most calls it finds point into the framework
rather than your own classes, so there is not much to resolve against. It gets more meaningful as
your app grows; on a real, mature application it is a genuine signal worth investigating.

Every command supports `--json`. Every answer carries a `graph` block reporting how stale it might
be (the commit the graph was built from, and how many files changed since) - never a silent guess
about how current the answer is.

## Use it from Claude Code, Cursor, or any MCP client

```bash
php artisan mapin:mcp
```

starts a stdio MCP server exposing every query as a tool, driven by the exact same code the CLI
uses - an MCP answer and a `mapin:query` answer to the same question never diverge. See
[docs/MCP.md](docs/MCP.md) for client configuration (Claude Code's `.mcp.json`, Cursor's
`.cursor/mcp.json`, including the Docker-exec variant for a containerized application).

## What it builds

- **PHP**: classes, methods, functions, calls, `new`, constructor and method injection, container
  bindings, Eloquent relations, table mapping (`$table` and convention), migrations, dispatched
  jobs and events, listeners, observers, scheduled commands.
- **Routes and views**: every registered route to its handler and middleware, Blade includes,
  components, and `route()`/`view()` calls from both PHP and Blade.
- **Markdown**: a `doc`/`section` node per file and heading, links between docs, and mentions of a
  known class, route, table, view or file - ambiguous short names land in `unresolved`, never a
  guess.
- **JavaScript**: `fetch`/`axios`/jQuery `$.ajax` calls with a literal URL, matched to the route
  they request.
- **Analysis**: Louvain community detection and hub/bridge degree over the call and injection
  graph, run on demand (`mapin:communities`), never during a plain build.
- **Semantic docs** (opt-in, `mapin:docs --with-llm`): concepts and relations between them,
  extracted from Markdown section text only - never PHP source, and never without the flag. Local
  by default (`NullClient`); one provider driver ships (Ollama).

Every query tool is listed in [docs/MCP.md](docs/MCP.md); the full behavioral contract, every node
and edge type, and the reasoning behind each design decision live in [SPEC.md](SPEC.md).

## Boundaries this package holds itself to

- Never sends code to an LLM without the explicit `--with-llm` flag, and only Markdown section
  text even then - never PHP source.
- Never writes outside its own configured storage directory (`storage/mapin/` by default). No
  files in your project root, no edits to your `.gitignore`.
- Never executes your application's business logic: booting the kernel to read the router and the
  container is as far as it goes.
- Never answers with an approximate match without saying so.

The full list, with the reasoning behind each one, is in [SPEC.md section 13](SPEC.md).

## Documentation

- [docs/MCP.md](docs/MCP.md) - MCP server setup and the full tool list
- [docs/EXTENDING.md](docs/EXTENDING.md) - adding your own extractor or query tool
- [SPEC.md](SPEC.md) - the complete specification: graph model, every extractor and resolution
  rule, the query contract, and a phase-by-phase account of what was built, what was verified
  against a real application, and every real bug found along the way
- [CONTRIBUTING.md](CONTRIBUTING.md) - development setup, running the test suite, code style

## License

MIT. See [LICENSE](LICENSE).
