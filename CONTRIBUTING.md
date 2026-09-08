# Contributing to Mapin

Thanks for considering it. This document covers the mechanics; [SPEC.md](SPEC.md) is the actual
specification and the reasoning behind every design decision - read it before touching an
extractor, the resolver, or the query layer.

## Setup

```bash
git clone https://github.com/Nexvia-Solutions/mapin.git
cd mapin
composer install
```

That installs `orchestra/testbench`, which provides the throwaway Laravel application every test
boots against - no separate Laravel installation needed to work on Mapin itself.

## Running the checks

```bash
vendor/bin/pest             # test suite
vendor/bin/phpstan analyse  # static analysis, level 6
vendor/bin/pint --test      # code style, PSR-12 via the laravel preset
```

All three must pass before a commit, not just before a PR - this project treats "written but never
actually run" as a real, recurring source of bugs, not a formality (see SPEC.md section 1.14 for a
concrete account of exactly that happening to this project's own CI). `vendor/bin/pint` (without
`--test`) auto-fixes most style issues.

## Code style

- PHP 8.2+, `declare(strict_types=1)` in every file.
- Classes `final` by default; value objects `readonly`; enums for node types, edge types and
  resolution kinds.
- One class per responsibility. Extractors, visitors and tools stay small - a long `switch` over
  node types belongs in a small strategy class, not inline.
- No hardcoded directories, framework paths, or class names beyond the small, explicitly
  documented list in SPEC.md section 3.4. Everything else is discovered through the container, the
  router, or configuration - see SPEC.md section 8's own real bug (a denylist that let framework
  noise into the community graph) for what happens when this slips.
- Comments explain *why*, not *what* - a well-named identifier already says what; a comment earns
  its place by capturing a non-obvious constraint, a workaround, or a real bug that motivated the
  code being the way it is, not by restating the code in prose.
- No em dash or en dash characters anywhere - code comments, docs, commit messages, CLI output. Use
  a period, a comma, or parentheses instead.
- Conventional Commits (`feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`).

## Testing philosophy

- **Fixtures are invented, always.** `tests/Fixtures/` is a small library-domain application built
  specifically to exercise every pattern this project cares about (SPEC.md section 12). Never add
  a real company, product, or tenant name anywhere in the fixtures, tests, or docs - a public
  repository is not the place for it, and it has already had to be cleaned out of this project's
  own history once.
- **`tests/Golden/expected.json`** is every node key and every edge the fixture app produces,
  diffed exactly on every run. Regenerate it deliberately with `MAPIN_UPDATE_GOLDEN=1 vendor/bin/pest
  tests/Feature/GoldenTest.php`, and review the diff before committing it - never just to make a
  red test green without understanding why the graph's shape changed.
- **A fixture-only test suite is not the whole story.** Several real bugs in this project were only
  found by running the actual code against a real Laravel application, at real scale, after the
  fixture-based suite was already green - a filter that let framework noise into an analysis
  step, a design choice that discarded real information a fixture was too small to expose, a golden
  snapshot that quietly depended on which Laravel version generated it. If you are changing an
  extractor's matching or filtering logic, a fixture passing is a start, not proof.
- **Unit tests** (`tests/Unit/`) need no Laravel boot at all - `SqliteStore`, `Louvain`,
  `WeightedGraph` and friends take a plain file path or plain data, which makes them fast and lets
  you hand-verify a small graph's expected answer directly.

## Pull requests

- Keep them scoped to one concern. This project's own history is a series of numbered phases, each
  with its own SPEC.md write-up - a PR that mirrors that shape (one clear thing, documented, tested,
  verified) is easier to review than one that mixes several.
- If you're adding a new `Extractor`, see [docs/EXTENDING.md](docs/EXTENDING.md) first - the
  interface, and when an extractor needs the two-phase `nodes()`/`edges()` split instead
  (`MarkdownExtractor` and `JsExtractor` both need it, for different reasons documented in their
  own class docblocks).
- CI runs Pint, PHPStan and Pest across PHP 8.2 through 8.4 and Laravel 11 through 13 (Laravel 13
  is allowed to fail there for now - see the Requirements section of the README).

## Boundaries

A few things this project holds itself to strictly (the full list, with the reasoning behind each
one, is in SPEC.md section 13):

- Never send code to an LLM without an explicit flag, and never anything but Markdown section text
  even then.
- Never write outside the configured storage directory. No files in a host project's root, no
  edits to its `.gitignore`.
- Never execute a host application's business logic - booting the kernel to read the router and
  the container is allowed; dispatching requests, running jobs or commands, or querying its
  database is not.
- Never answer a query with an approximate match without saying so explicitly.

If a change you're proposing would cross one of these, open an issue first and say why - it may
still be the right call, but it needs a real conversation, not a quiet PR.
