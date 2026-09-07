<?php

declare(strict_types=1);

namespace Mapin\Mcp;

/**
 * A query tool, defined independently of any MCP library (SPEC.md section 6.1: "the MCP server is
 * exposed through laravel/mcp ... swapping the MCP library must not touch src/Query or
 * src/Mcp/Tools"). Implementations live in src/Mcp/Tools and only ever talk to Mapin\Query\Query;
 * src/Mcp/Adapters translates a ToolSpec into whatever the chosen MCP library's own base class
 * expects, and mapin:query drives the exact same instances from the CLI.
 */
interface ToolSpec
{
    public function name(): string;

    public function description(): string;

    /**
     * One entry per argument, in the shape AbstractToolAdapter needs to build both a JSON Schema
     * property and a default: type is 'string'|'integer'|'number'|'boolean', required is whether
     * the argument must be present, description is shown to the caller, and default/enum are
     * optional.
     *
     * @return array<string, array{type: string, required: bool, description: string, default?: mixed, enum?: array<int, string>}>
     */
    public function arguments(): array;

    /**
     * @param  array<string, mixed>  $arguments  already defaulted for any argument the caller omitted
     * @return array<string, mixed> the full response envelope from Query::envelope()
     */
    public function handle(array $arguments): array;
}
