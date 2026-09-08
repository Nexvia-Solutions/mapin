# Mapin over MCP

Mapin's query layer (`Mapin\Query\Query`) is available as an MCP server, so an AI coding assistant
can ask about your Laravel application's code graph directly instead of you running `mapin:*`
commands and pasting the output back in. The server is stdio-based and self-contained: it does not
need anything registered in `routes/ai.php`, and starting it does not boot your queue, run jobs, or
touch anything beyond the graph SQLite file.

## 1. Build the graph first

The MCP server only ever reads what `mapin:build` already wrote - it never parses your code itself.

```bash
php artisan mapin:build --full
```

Every tool response carries a `graph` block reporting how stale the answer might be
(`built_commit` vs. the repository's current `head_commit`, and how many files changed since), so a
client can tell you when it's worth rebuilding rather than silently answering from an old graph.

## 2. Start the server

```bash
php artisan mapin:mcp
```

This blocks, reading JSON-RPC requests from stdin and writing responses to stdout - that is
expected, not a hang. An MCP client manages this process for you; you should not normally run it
by hand outside of testing the command itself.

## 3. Claude Code

Claude Code reads MCP servers from `.mcp.json` in your project root (or a user/global config - see
Claude Code's own MCP documentation for scope). Add an entry pointing at your application's own
`artisan`:

```json
{
  "mcpServers": {
    "mapin": {
      "command": "php",
      "args": ["artisan", "mapin:mcp"],
      "cwd": "/absolute/path/to/your/laravel-app"
    }
  }
}
```

If your app runs inside a container (as with the Docker-based workflow this package was built
against), point the command at however you already exec into it, for example:

```json
{
  "mcpServers": {
    "mapin": {
      "command": "docker",
      "args": ["exec", "-i", "your-app-container", "php", "artisan", "mapin:mcp"]
    }
  }
}
```

The `-i` flag is required - without it, stdin is not attached and the server never sees a request.

## 4. Cursor

Cursor reads MCP servers from `.cursor/mcp.json` (project) or `~/.cursor/mcp.json` (global). The
entry shape is the same as Claude Code's:

```json
{
  "mcpServers": {
    "mapin": {
      "command": "php",
      "args": ["artisan", "mapin:mcp"],
      "cwd": "/absolute/path/to/your/laravel-app"
    }
  }
}
```

## 5. What you get

Every tool below is defined once, protocol-agnostically, in `src/Mcp/Tools` (SPEC.md section 6.1)
and driven by the same `Mapin\Query\Query` service the CLI uses - an MCP answer and a
`php artisan mapin:query <tool> --arg=...` answer to the same question never diverge.

| Tool | What it answers |
|---|---|
| `find` | Where is this class/method/route/view/table? (exact by default; pass `fuzzy: true` for a partial match) |
| `node` | Full detail on one node: file, line, meta, edge counts by type |
| `callers` | Who calls, injects, instantiates or resolves this? |
| `callees` | What does this call, render, or dispatch? |
| `impact` | Everything reachable backward from here - routes, views, jobs, commands, methods, classes |
| `path` | Shortest edge path from one node to another |
| `route` | A route's handler, middleware, views, models |
| `view` | A Blade view's renderer, includes, components, linked routes |
| `model` | An Eloquent model's table, relations, migrations, observers, call sites |
| `unresolved` | References the resolver could not link with confidence, for improving code or the resolver itself |
| `stats` | Graph size and the last build's report |
| `docs` | Sections that document a node, or the nodes a section documents (needs `mapin:build` to have indexed Markdown under `docs`/your configured paths) |
| `hubs` | Highest degree nodes in the call/injection graph, vendor code and facades excluded |
| `communities` | Community membership summary - size, top nodes and dominant namespaces per community (needs `mapin:communities` to have run first) |

`hubs` reports degree from the same graph `mapin:communities` builds; its `bridges` field (how many
distinct communities a node touches) is only populated once `mapin:communities` has run at least
once. `communities` itself answers `found: false` until then - it never computes on demand.

## 6. The "not found" contract

A tool never substitutes a look-alike match for the real answer. When nothing matches exactly:

```json
{ "found": false, "query": { "tool": "find", "name": "CartControler" }, "suggestions": [{ "key": "class:App\\Http\\Controllers\\CartController", "score": 0.6 }], "graph": { "...": "..." } }
```

`suggestions` are explicitly labelled as such - treat them as candidates to confirm with the user
or re-query, never as the answer itself.

## 7. `mapin:doctor`

Before configuring a client, `php artisan mapin:doctor` checks PHP/extension requirements, whether
the router is bound, whether a graph exists, how stale it is against the current commit, and the
proportion of references the last build could not resolve - a quick way to tell whether the server
is worth pointing a client at yet.
