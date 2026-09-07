<?php

declare(strict_types=1);

namespace Mapin\Mcp\Adapters;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Mapin\Mcp\ToolSpec;

/**
 * Translates one ToolSpec into laravel/mcp's own Tool base class - the only place this package
 * knows laravel/mcp exists (SPEC.md section 6.1). Swapping MCP libraries means rewriting this class
 * and its concrete subclasses, never src/Mcp/Tools or src/Query.
 */
abstract class AbstractToolAdapter extends Tool
{
    abstract protected function toolSpec(): ToolSpec;

    public function name(): string
    {
        return $this->toolSpec()->name();
    }

    public function description(): string
    {
        return $this->toolSpec()->description();
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        $properties = [];
        foreach ($this->toolSpec()->arguments() as $name => $arg) {
            $type = match ($arg['type']) {
                'integer' => $schema->integer(),
                'number' => $schema->number(),
                'boolean' => $schema->boolean(),
                default => $schema->string(),
            };
            $type->description($arg['description']);
            if ($arg['required']) {
                $type->required();
            }
            if (array_key_exists('default', $arg)) {
                $type->default($arg['default']);
            }
            if (isset($arg['enum'])) {
                $type->enum($arg['enum']);
            }
            $properties[$name] = $type;
        }

        return $properties;
    }

    public function handle(Request $request): ResponseFactory
    {
        $spec = $this->toolSpec();
        $arguments = $request->all();
        foreach ($spec->arguments() as $name => $arg) {
            if (! array_key_exists($name, $arguments) && array_key_exists('default', $arg)) {
                $arguments[$name] = $arg['default'];
            }
        }

        return Response::structured($spec->handle($arguments));
    }
}
